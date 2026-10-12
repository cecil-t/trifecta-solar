<?php
declare(strict_types=1);

namespace App;

/**
 * Reads SMA plant data the way the Sunny Portal (ennexOS) web app does, signed in as the installer's
 * SMA ID (SMA_USERNAME / SMA_PASSWORD in .env). This is not a published API: it can break when SMA
 * changes their portal. Used because SMA's developer API needs an approved partner account and each
 * plant owner's consent.
 *
 * Sign-in is SMA's standard single sign-on (OpenID Connect with PKCE, the portal's public client):
 * load the sign-in form, post the username and password, take the code from the redirect and trade it
 * for tokens. The access token lasts 5 minutes and the refresh token 2 days; both are kept in a private
 * file next to the database, and a full sign-in happens only when the refresh token has run out.
 * Nothing here puts the password or tokens on a page.
 */
final class Sma
{
	public const AUTH = 'https://login.sma.energy/auth/realms/SMA/protocol/openid-connect';
	public const API = 'https://uiapi.sunnyportal.com/api/v1';
	public const PORTAL = 'https://ennexos.sunnyportal.com';
	private const CLIENT_ID = 'SPpbeOS';
	private const ENERGY = 'Measurement.Metering.TotWhOut.Pv';

	public static function configured(): bool
	{
		return trim((string) Config::get('SMA_USERNAME')) !== '' && trim((string) Config::get('SMA_PASSWORD')) !== '';
	}

	public static function available(): bool
	{
		return function_exists('curl_init');
	}

	/** Saved session times for the test page (no tokens). @return ?array{signed_in_at:int,refresh_until:int} */
	public static function status(): ?array
	{
		$s = self::load();
		return $s ? ['signed_in_at' => (int) ($s['signed_in_at'] ?? 0), 'refresh_until' => (int) ($s['refresh_until'] ?? 0)] : null;
	}

	public static function forget(): void
	{
		@unlink(self::file());
	}

	/**
	 * Sign in fresh and save the tokens. The result is for the test page: status, where it stopped
	 * and why, never the tokens.
	 */
	public static function login(): array
	{
		self::forget();
		$jar = dirname(Db::path()) . '/.sma-login-cookies.txt';
		@unlink($jar);
		$start = microtime(true);
		try {
			$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
			$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
			$state = bin2hex(random_bytes(16));
			$redirect = self::PORTAL . '/';
			$page = self::curl('GET', self::AUTH . '/auth?' . http_build_query([
				'response_type' => 'code',
				'client_id' => self::CLIENT_ID,
				'state' => $state,
				'redirect_uri' => $redirect,
				'scope' => 'openid profile',
				'code_challenge' => $challenge,
				'code_challenge_method' => 'S256',
				'nonce' => bin2hex(random_bytes(16)),
			]), [], '', $jar);
			if ($page['status'] !== 200 || !preg_match('/<form[^>]*name="loginForm"[^>]*action="([^"]+)"/s', $page['body'], $m)) {
				return self::loginResult($page, 'Could not read the SMA sign-in form.', $start);
			}
			$post = self::curl('POST', html_entity_decode($m[1]), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
				'username' => trim((string) Config::get('SMA_USERNAME')),
				'password' => (string) Config::get('SMA_PASSWORD'),
				'credentialId' => '',
			]), $jar);
			$location = self::header($post['headers'], 'location');
			parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $q);
			if (!in_array($post['status'], [301, 302, 303], true) || !str_starts_with((string) $location, $redirect) || ($q['state'] ?? '') !== $state || empty($q['code'])) {
				// Still on SMA's pages: a wrong password shows a message; a second step (code) shows another form.
				$why = 'SMA did not accept the sign-in.';
				if (preg_match('/<(?:span|div)[^>]*class="[^"]*(?:alert|error|kc-feedback)[^"]*"[^>]*>\s*(?:<[^>]+>\s*)*([^<]{3,200})/s', $post['body'], $err)) {
					$why .= ' It said: ' . trim(html_entity_decode($err[1]));
				} elseif (preg_match('/name="(otp|totp|code)"/', $post['body'])) {
					$why .= ' It asked for a second step (a code), which the collector cannot do.';
				}
				return self::loginResult($post, $why, $start);
			}
			$token = self::curl('POST', self::AUTH . '/token', ['Content-Type: application/x-www-form-urlencoded', 'Origin: ' . self::PORTAL], http_build_query([
				'grant_type' => 'authorization_code',
				'code' => (string) $q['code'],
				'redirect_uri' => $redirect,
				'code_verifier' => $verifier,
				'client_id' => self::CLIENT_ID,
			]));
			if (!self::saveTokens($token, true)) {
				return self::loginResult($token, 'SMA did not issue tokens.', $start);
			}
			return self::loginResult($token, 'Signed in.', $start);
		} finally {
			@unlink($jar);
		}
	}

	/** The portal's status list: every plant the account sees, with today's yield, yesterday's specific yield and state. */
	public static function plants(): array
	{
		return self::call('GET', '/statuslist/plants?' . http_build_query(['todayDate' => date('Y-m-d'), 'parentId' => '']));
	}

	/** One plant's properties (peak power and more; the reply also holds owner contact details, never stored). */
	public static function plant(string $plantId): array
	{
		return self::call('GET', '/plants/' . self::id($plantId));
	}

	/**
	 * Energy produced per day (OneDay) or per month (OneMonth) for several plants in one request, between
	 * two local dates (end exclusive). SMA labels each value with the END of its interval, so a day's value
	 * carries the next midnight and a month's the first of the next month; this returns them keyed by the
	 * day (Y-m-d) or month (Y-m) they cover.
	 *
	 * @param array<int, string> $plantIds
	 * @return array{result:array, values:array<string, array<string, float>>}
	 */
	public static function energy(array $plantIds, string $resolution, \DateTimeImmutable $from, \DateTimeImmutable $to): array
	{
		if (!in_array($resolution, ['OneDay', 'OneMonth'], true)) {
			throw new \InvalidArgumentException('Bad resolution');
		}
		$tz = date_default_timezone_get();
		$utc = new \DateTimeZone('UTC');
		$items = array_map(static fn (string $id): array => [
			'componentId' => self::id($id),
			'channelId' => self::ENERGY,
			'resolution' => $resolution,
			'timezone' => $tz,
			'aggregate' => 'Dif',
			'multiAggregate' => 'Sum',
		], array_values($plantIds));
		$r = self::call('POST', '/measurements/search', (string) json_encode([
			'queryItems' => $items,
			'dateTimeBegin' => $from->setTimezone($utc)->format('Y-m-d\TH:i:s.000\Z'),
			'dateTimeEnd' => $to->setTimezone($utc)->format('Y-m-d\TH:i:s.000\Z'),
		]));
		$values = [];
		$local = new \DateTimeZone($tz);
		foreach (is_array($r['json']) && array_is_list($r['json']) ? $r['json'] : [] as $series) {
			if (($series['channelId'] ?? '') !== self::ENERGY) {
				continue;
			}
			$plant = (string) ($series['componentId'] ?? '');
			foreach ($series['values'] ?? [] as $v) {
				if (!isset($v['time']) || $v['value'] === null) {
					continue;
				}
				$end = (new \DateTimeImmutable((string) $v['time']))->setTimezone($local);
				$key = $resolution === 'OneDay' ? $end->modify('-1 day')->format('Y-m-d') : $end->modify('-1 month')->format('Y-m');
				$values[$plant][$key] = ($values[$plant][$key] ?? 0.0) + (float) $v['value'];
			}
		}
		return ['result' => $r, 'values' => $values];
	}

	/** A portal API call with a valid access token: refresh it, or sign in again, when needed. */
	public static function call(string $method, string $path, string $body = ''): array
	{
		$note = null;
		$token = self::accessToken($note);
		if ($token === null) {
			return ['url' => self::API . $path, 'note' => $note, 'status' => 0, 'headers' => [], 'body' => '', 'json' => null, 'ms' => 0, 'error' => $note];
		}
		$r = self::api($method, $path, $body, $token);
		if ($r['status'] === 401) {
			$login = self::login();
			if ($login['note'] !== 'Signed in.') {
				return $login;
			}
			$note = 'The saved session had ended; signed in again.';
			$r = self::api($method, $path, $body, (string) self::load()['access_token']);
		}
		return ['url' => self::API . $path, 'note' => $note] + $r;
	}

	private static function api(string $method, string $path, string $body, string $token): array
	{
		$headers = [
			'Accept: application/json, text/plain, */*',
			'Authorization: Bearer ' . $token,
			'Origin: ' . self::PORTAL,
			'Referer: ' . self::PORTAL . '/',
		];
		if ($method === 'POST') {
			$headers[] = 'Content-Type: application/json';
		}
		$r = self::curl($method, self::API . $path, $headers, $body);
		$isJson = $r['json'] !== null || trim($r['body']) === 'null';
		return [
			'status' => $r['status'],
			'headers' => $r['headers'],
			'body' => $isJson ? $r['body'] : '',
			'json' => $r['json'],
			'ms' => $r['ms'],
			'error' => $r['error'] ?? ($r['status'] === 200 ? null : 'HTTP ' . $r['status']),
		];
	}

	/** A usable access token: the saved one, a refreshed one, or one from a fresh sign-in. */
	private static function accessToken(?string &$note): ?string
	{
		$s = self::load();
		if ($s && (int) $s['access_until'] > time() + 30) {
			return (string) $s['access_token'];
		}
		if ($s && (int) $s['refresh_until'] > time() + 30) {
			$r = self::curl('POST', self::AUTH . '/token', ['Content-Type: application/x-www-form-urlencoded', 'Origin: ' . self::PORTAL], http_build_query([
				'grant_type' => 'refresh_token',
				'refresh_token' => (string) $s['refresh_token'],
				'client_id' => self::CLIENT_ID,
			]));
			if (self::saveTokens($r, false)) {
				return (string) self::load()['access_token'];
			}
		}
		$login = self::login();
		if ($login['note'] !== 'Signed in.') {
			$note = (string) $login['note'];
			return null;
		}
		$note = 'Signed in first.';
		return (string) self::load()['access_token'];
	}

	private static function saveTokens(array $r, bool $fresh): bool
	{
		$j = $r['json'];
		if ($r['status'] !== 200 || !is_array($j) || empty($j['access_token']) || empty($j['refresh_token'])) {
			return false;
		}
		$old = self::load();
		$data = [
			'access_token' => (string) $j['access_token'],
			'access_until' => time() + (int) ($j['expires_in'] ?? 300),
			'refresh_token' => (string) $j['refresh_token'],
			'refresh_until' => time() + (int) ($j['refresh_expires_in'] ?? 1800),
			'signed_in_at' => $fresh ? time() : (int) ($old['signed_in_at'] ?? time()),
		];
		$file = self::file();
		file_put_contents($file, (string) json_encode($data), LOCK_EX);
		@chmod($file, 0600);
		return true;
	}

	private static function load(): ?array
	{
		$raw = @file_get_contents(self::file());
		$s = $raw ? json_decode($raw, true) : null;
		return is_array($s) && isset($s['access_token'], $s['refresh_token']) ? $s : null;
	}

	/** Result of a sign-in for the test page: no page HTML (it can echo form tokens) and no tokens. */
	private static function loginResult(array $r, string $note, float $start): array
	{
		$headers = array_values(array_filter(array_map(
			static fn (string $h): string => preg_match('/^set-cookie:\s*([^=;\s]+)/i', $h, $m) ? 'set-cookie: ' . $m[1] . '=(hidden)' : (preg_match('/^location:/i', $h) ? 'location: (hidden)' : $h),
			$r['headers']
		)));
		return [
			'url' => self::AUTH,
			'note' => $note,
			'status' => $r['status'],
			'headers' => $headers,
			'body' => '',
			'json' => null,
			'ms' => (int) round((microtime(true) - $start) * 1000),
			'error' => $note === 'Signed in.' ? null : $note,
		];
	}

	/** One curl request; redirects are not followed (the sign-in needs the redirect's address). */
	private static function curl(string $method, string $url, array $headers, string $body = '', ?string $jar = null): array
	{
		$ch = curl_init($url);
		$respHeaders = [];
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => 45,
			CURLOPT_ENCODING => '',
			CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TrifectaTracker/1.0)',
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
				$t = trim($line);
				if ($t !== '') {
					$respHeaders[] = $t;
				}
				return strlen($line);
			},
		];
		if ($jar !== null) {
			$opts[CURLOPT_COOKIEFILE] = $jar;
			$opts[CURLOPT_COOKIEJAR] = $jar;
		}
		curl_setopt_array($ch, $opts);
		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$start = microtime(true);
		$raw = curl_exec($ch);
		$ms = (int) round((microtime(true) - $start) * 1000);
		$error = $raw === false ? curl_error($ch) : null;
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		if ($jar !== null) {
			@chmod($jar, 0600);
		}
		$raw = $raw === false ? '' : (string) $raw;
		return ['status' => $status, 'headers' => $respHeaders, 'body' => $raw, 'json' => $raw !== '' ? json_decode($raw, true) : null, 'ms' => $ms, 'error' => $error];
	}

	private static function header(array $headers, string $name): ?string
	{
		foreach ($headers as $h) {
			if (stripos($h, $name . ':') === 0) {
				return trim(substr($h, strlen($name) + 1));
			}
		}
		return null;
	}

	private static function id(string $id): string
	{
		if (!preg_match('/^\d{1,12}$/', $id)) {
			throw new \InvalidArgumentException('Bad SMA plant id');
		}
		return $id;
	}

	private static function file(): string
	{
		return dirname(Db::path()) . '/sma-session.json';
	}
}
