<?php
declare(strict_types=1);

namespace App;

/**
 * Reads Growatt data the way the Shiner web portal (shiner-us.growatt.com) does, signed in as the
 * installer account (GROWATT_USERNAME / GROWATT_PASSWORD in .env). Not a published API: it can break
 * when Growatt changes the portal.
 *
 * Signing in needs a captcha, so a person connects it from the admin test page about once a month:
 * the tracker shows Growatt's captcha image, the person types it, and the sign-in asks for a 30-day
 * session ("remember me"). After that the session slides on its own: Growatt sets a refresh-token
 * cookie at sign-in and returns a fresh access token in the "authorization" header of responses,
 * which is saved after every call. The collector's regular polling keeps it alive. Nothing here ever
 * reads or solves the captcha.
 *
 * The portal's own sign-in sends the password as sha1(server key + sha1(password)), plus a field
 * "us_authorize" holding the password encrypted with a key and IV published in the portal's script
 * (AES-128-CFB with one segment the length of the password, hex). This copies both exactly.
 *
 * Session state (cookie jar and current access token) lives in private files next to the database.
 */
final class Growatt
{
	public const BASE = 'https://shiner-us.growatt.com';
	private const AES_KEY = 'VidaGrid&Growatt';
	private const AES_IV = 'Growatt&Password';

	public static function configured(): bool
	{
		return trim((string) Config::get('GROWATT_USERNAME')) !== '' && (string) Config::get('GROWATT_PASSWORD') !== '';
	}

	public static function available(): bool
	{
		return function_exists('curl_init') && function_exists('openssl_encrypt');
	}

	/** Connected when an access token is saved; the refresh-token cookie's expiry says for how long. */
	public static function status(): ?array
	{
		$state = self::state();
		if (empty($state['token'])) {
			return null;
		}
		return [
			'connected_at' => (int) ($state['connected_at'] ?? 0),
			'last_ok' => (int) ($state['last_ok'] ?? 0),
			'session_until' => self::refreshCookieExpiry(),
		];
	}

	public static function forget(): void
	{
		@unlink(self::jar());
		@unlink(self::stateFile());
	}

	// ---- connect (captcha typed by a person) --------------------------------------------------

	/**
	 * Start a fresh sign-in: new cookie jar, ask Growatt for a captcha. Returns the image as a data URI
	 * (or null) and the captcha key to send back with the typed code.
	 *
	 * @return array{image:?string,key:?string,error:?string}
	 */
	public static function captcha(): array
	{
		@unlink(self::jar());
		$r = self::request('POST', '/web/v1/auth/captcha', [], '{}');
		$data = $r['json']['data'] ?? null;
		$b64 = is_array($data) ? (string) ($data['b64'] ?? '') : '';
		$key = is_array($data) ? (string) ($data['captchaKey'] ?? '') : '';
		if ($r['status'] !== 200 || $b64 === '' || $key === '') {
			return ['image' => null, 'key' => null, 'error' => 'Growatt did not send a captcha (HTTP ' . $r['status'] . ($r['error'] ? ', ' . $r['error'] : '') . ').'];
		}
		if (preg_match('#^data:image/(png|jpe?g|gif);base64,[A-Za-z0-9+/=\s]+$#', $b64)) {
			$image = $b64;
		} elseif (preg_match('#^[A-Za-z0-9+/=\s]+$#', $b64)) {
			$image = 'data:image/png;base64,' . preg_replace('/\s+/', '', $b64);
		} else {
			return ['image' => null, 'key' => null, 'error' => 'Growatt sent a captcha in an unexpected format.'];
		}
		return ['image' => $image, 'key' => $key, 'error' => null];
	}

	/** Sign in with the captcha a person typed. The result never includes the token or the password. */
	public static function login(string $captcha, string $captchaKey): array
	{
		$keyReply = self::request('GET', '/web/v1/auth/login');
		$serverKey = (string) ($keyReply['json']['data']['key'] ?? '');
		if ($keyReply['status'] !== 200 || $serverKey === '') {
			return ['url' => self::BASE . '/web/v1/auth/login', 'note' => 'Could not get the sign-in key.'] + self::summary($keyReply);
		}
		$password = (string) Config::get('GROWATT_PASSWORD');
		$body = json_encode([
			'username' => trim((string) Config::get('GROWATT_USERNAME')),
			'password' => self::passwordHash($serverKey, $password),
			'captcha' => $captcha,
			'captchaKey' => $captchaKey,
			'us_authorize' => self::usAuthorize($password),
			'expire_minutes' => 43200,
		]);
		$r = self::request('POST', '/web/v1/auth/login', [], (string) $body);
		$token = $r['auth'] ?? null;
		if ($token === null && !empty($r['json']['data']['accessToken'])) {
			$token = 'Bearer ' . $r['json']['data']['accessToken'];
		}
		$ok = $r['status'] === 200 && (int) ($r['json']['code'] ?? -1) === 0 && $token !== null;
		if ($ok) {
			self::saveState(['token' => $token, 'connected_at' => time(), 'last_ok' => time()]);
		}
		$result = ['url' => self::BASE . '/web/v1/auth/login', 'note' => $ok ? 'Signed in; session saved.' : 'Sign-in refused: ' . (string) ($r['json']['msg'] ?? 'no message')] + self::summary($r);
		// Keep the reply's user role and id, never the token.
		if (is_array($result['json'])) {
			unset($result['json']['data']['accessToken']);
			$result['body'] = (string) json_encode($result['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		}
		return $result;
	}

	/** sha1(server key + sha1(password)), as the portal's sign-in form sends it. */
	public static function passwordHash(string $serverKey, string $password): string
	{
		return sha1($serverKey . sha1($password));
	}

	/**
	 * The portal's "us_authorize": AES-128-CFB of the password with a segment size equal to its length
	 * (aes-js). That is one AES block of the IV XORed into the first 16 bytes; any bytes past 16 stay as
	 * they are. Hex-encoded.
	 */
	public static function usAuthorize(string $password): string
	{
		return bin2hex(self::cfbOneSegment($password));
	}

	/** XOR the first 16 bytes with AES(key, IV). Applying it twice gives the input back. */
	public static function cfbOneSegment(string $bytes): string
	{
		$stream = (string) openssl_encrypt(self::AES_IV, 'aes-128-ecb', self::AES_KEY, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
		$n = min(16, strlen($bytes));
		return (substr($bytes, 0, $n) ^ substr($stream, 0, $n)) . substr($bytes, $n);
	}

	// ---- data -----------------------------------------------------------------------------------

	/** Any portal GET with the saved session (e.g. /web/v1/sites/1/50?name=&filter=). */
	public static function get(string $path): array
	{
		$state = self::state();
		if (empty($state['token'])) {
			return ['url' => self::BASE . $path, 'note' => 'Not connected: use Connect Growatt on the test page.'] + self::summary(self::fail());
		}
		$r = self::request('GET', $path, ['Authorization: ' . $state['token']]);
		if ($r['auth'] !== null) {
			$state['token'] = $r['auth'];
		}
		if ($r['status'] === 401) {
			$note = 'Growatt ended the session (401). Connect again from the test page.';
			$state['token'] = null;
		} else {
			$note = $r['auth'] !== null ? 'Growatt sent a fresh access token; saved.' : null;
			if ($r['status'] === 200) {
				$state['last_ok'] = time();
			}
		}
		self::saveState($state);
		return ['url' => self::BASE . preg_replace('/\?.*/', '', $path), 'note' => $note] + self::summary($r);
	}

	/**
	 * Production for one site over a window: the reply's eacToday is the AC energy (kWh) for the whole
	 * window despite its name, and pmax the site's total inverter AC rating (W). eacTotal changes with
	 * the window, so it is not a lifetime figure and is not used. Without a site the portal returns zeros.
	 *
	 * @return array{kwh:float,pmax_w:?float}
	 */
	public static function production(string $siteId, \DateTimeInterface $start, \DateTimeInterface $end): array
	{
		$path = '/web/v1/inverters/production?' . http_build_query([
			'start' => $start->format(DATE_RFC3339),
			'end' => $end->format(DATE_RFC3339),
			'siteId' => $siteId,
		]);
		$r = self::get($path);
		if ($r['status'] === 0 && !empty(self::state()['token'])) {
			// No reply at all (timeout or dropped connection): wait a moment and try once more.
			sleep(5);
			$r = self::get($path);
		}
		$data = $r['json']['data'] ?? null;
		if ($r['status'] !== 200 || (int) ($r['json']['code'] ?? -1) !== 0 || !is_array($data)) {
			$why = $r['error'] ?? (is_string($data) ? substr($data, 0, 120) : (string) ($r['json']['msg'] ?? ''));
			throw new \RuntimeException(trim(($r['note'] ?? '') . " Production for site $siteId: HTTP " . $r['status'] . ($why !== '' ? ', ' . $why : '')));
		}
		return [
			'kwh' => (float) ($data['eacToday'] ?? 0),
			'pmax_w' => isset($data['pmax']) && (float) $data['pmax'] > 0 ? (float) $data['pmax'] : null,
		];
	}

	// ---- transport ------------------------------------------------------------------------------

	private static function request(string $method, string $path, array $headers = [], string $body = ''): array
	{
		$jar = self::jar();
		$ch = curl_init(self::BASE . $path);
		$respHeaders = [];
		$auth = null;
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_COOKIEFILE => $jar,
			CURLOPT_COOKIEJAR => $jar,
			CURLOPT_ENCODING => '',
			CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TrifectaTracker/1.0)',
			CURLOPT_HTTPHEADER => array_merge([
				'Accept: application/json, text/plain, */*',
				'Content-Type: application/json',
				'Origin: ' . self::BASE,
				'Referer: ' . self::BASE . '/main/sites/site_list',
			], $headers),
			CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders, &$auth): int {
				$t = trim($line);
				if ($t !== '') {
					if (preg_match('/^authorization:\s*(.+)$/i', $t, $m)) {
						$auth = trim($m[1]);
						$t = 'authorization: (new token, hidden)';
					} elseif (preg_match('/^set-cookie:\s*([^=;\s]+)/i', $t, $m)) {
						$t = 'set-cookie: ' . $m[1] . '=(hidden)';
					}
					$respHeaders[] = $t;
				}
				return strlen($line);
			},
		]);
		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$start = microtime(true);
		$raw = curl_exec($ch);
		$ms = (int) round((microtime(true) - $start) * 1000);
		$error = $raw === false ? curl_error($ch) : null;
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch); // writes the cookie file
		@chmod($jar, 0600);
		$raw = $raw === false ? '' : (string) $raw;
		return ['status' => $status, 'headers' => $respHeaders, 'body' => $raw, 'json' => $raw !== '' ? json_decode($raw, true) : null, 'ms' => $ms, 'error' => $error, 'auth' => $auth];
	}

	private static function summary(array $r): array
	{
		$isJson = is_array($r['json']);
		return [
			'status' => $r['status'],
			'headers' => $r['headers'],
			'body' => $isJson ? $r['body'] : '',
			'json' => $isJson ? $r['json'] : null,
			'ms' => $r['ms'],
			'error' => $r['error'] ?? ($isJson || $r['status'] === 0 ? null : 'Not a JSON reply (' . number_format(strlen($r['body'])) . ' bytes)'),
		];
	}

	private static function fail(): array
	{
		return ['status' => 0, 'headers' => [], 'body' => '', 'json' => null, 'ms' => 0, 'error' => null, 'auth' => null];
	}

	/** Expiry (Unix time) of the refresh-token cookie in the jar, or null when there is none. */
	private static function refreshCookieExpiry(): ?int
	{
		foreach (@file(self::jar(), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
			$parts = explode("\t", $line);
			if (count($parts) >= 7 && strcasecmp($parts[5], 'refresh-token') === 0) {
				return (int) $parts[4] ?: null;
			}
		}
		return null;
	}

	private static function state(): array
	{
		$file = self::stateFile();
		$data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
		return is_array($data) ? $data : [];
	}

	private static function saveState(array $state): void
	{
		$file = self::stateFile();
		file_put_contents($file, json_encode($state), LOCK_EX);
		@chmod($file, 0600);
	}

	private static function jar(): string
	{
		return dirname(Db::path()) . '/growatt-session.txt';
	}

	private static function stateFile(): string
	{
		return dirname(Db::path()) . '/growatt-state.json';
	}
}
