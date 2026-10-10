<?php
declare(strict_types=1);

namespace App;

/**
 * Reads Enphase system data the way the Enlighten Manager web app does, signed in as an installer
 * user (ENPHASE_ENLIGHTEN_EMAIL / ENPHASE_ENLIGHTEN_PASSWORD in .env). This is not a published API:
 * it can break when Enphase changes their site. Used because the free developer API needs each
 * homeowner's approval and the installer (Partner) API needs 10+ installs.
 *
 * The signed-in session lives in a private cookie file next to the database and is reused until
 * Enphase ends it; then the next call signs in again. Nothing here puts the password or session
 * on a page.
 */
final class Enlighten
{
	public const BASE = 'https://enlighten.enphaseenergy.com';

	/** Columns of the Enlighten Manager systems table, as the web app requests them. */
	public const COLUMNS = ['name', 'id', 'status', 'status_since', 'city', 'state', 'today_production', 'lifetime_production', 'connection_type', 'issue_count'];

	public static function configured(): bool
	{
		return trim((string) Config::get('ENPHASE_ENLIGHTEN_EMAIL')) !== '' && trim((string) Config::get('ENPHASE_ENLIGHTEN_PASSWORD')) !== '';
	}

	public static function available(): bool
	{
		return function_exists('curl_init');
	}

	public static function hasSession(): bool
	{
		return self::cookieNames() !== [];
	}

	public static function forget(): void
	{
		@unlink(self::jar());
	}

	/**
	 * Sign in fresh: load the sign-in page for its form token, then post the form and follow the
	 * redirects. The result reports the final address and which cookies were set (names only).
	 */
	public static function login(): array
	{
		self::forget();
		$page = self::request('GET', self::BASE . '/login');
		if ($page['status'] !== 200 || !preg_match('/<form[^>]*id="login_form_tag".*?name="authenticity_token" value="([^"]+)"/s', $page['body'], $m)) {
			$result = ['url' => self::BASE . '/login', 'note' => 'Could not read the sign-in form.'] + self::summary($page);
			self::forget();
			return $result;
		}
		$post = self::request('POST', self::BASE . '/login/login', [
			'Content-Type: application/x-www-form-urlencoded',
			'Origin: ' . self::BASE,
			'Referer: ' . self::BASE . '/login',
		], http_build_query([
			'utf8' => "\u{2713}",
			'authenticity_token' => html_entity_decode($m[1]),
			'user' => [
				'email' => trim((string) Config::get('ENPHASE_ENLIGHTEN_EMAIL')),
				'password' => (string) Config::get('ENPHASE_ENLIGHTEN_PASSWORD'),
			],
			'secured_user' => 'true',
			'locale' => 'en',
			'commit' => 'Sign In',
		]));
		$signedIn = $post['status'] === 200 && !str_contains($post['final_url'], '/login') && !str_contains($post['body'], 'id="login_form_tag"');
		$result = ['url' => self::BASE . '/login/login', 'note' => $signedIn ? 'Signed in.' : 'Sign-in failed (still on the sign-in page).'] + self::summary($post);
		if (!$signedIn) {
			self::forget();
		}
		return $result;
	}

	/** The Enlighten Manager systems table: every system the installer account sees, one request. */
	public static function systems(): array
	{
		$body = json_encode([
			'page' => 0,
			'per_page' => 100,
			'sort' => [],
			'columns' => self::COLUMNS,
			'filters' => [],
			'access' => 'INSTALLER',
			'require_total_count' => true,
		]);
		return self::serviceCall('POST', '/service/site_search/sites/search/data?locale=en', (string) $body);
	}

	/** A service call with the saved session, signing in first (or again) when there is none or it ended. */
	private static function serviceCall(string $method, string $path, string $body): array
	{
		$headers = [
			'Content-Type: application/json',
			'Accept: application/json, text/plain, */*',
			'Origin: ' . self::BASE,
			'Referer: ' . self::BASE . '/app/fleet_dashboard',
		];
		$note = null;
		if (!self::hasSession()) {
			$login = self::login();
			if ($login['note'] !== 'Signed in.') {
				return $login;
			}
			$note = 'Signed in first.';
		}
		$r = self::request($method, self::BASE . $path, $headers, $body);
		if (in_array($r['status'], [401, 403], true) || !is_array($r['json'])) {
			$login = self::login();
			if ($login['note'] !== 'Signed in.') {
				return $login;
			}
			$note = 'The saved session had ended; signed in again.';
			$r = self::request($method, self::BASE . $path, $headers, $body);
		}
		return ['url' => self::BASE . $path, 'note' => $note] + self::summary($r);
	}

	/** One curl request with the cookie file, following redirects. */
	private static function request(string $method, string $url, array $headers = [], string $body = ''): array
	{
		$jar = self::jar();
		$ch = curl_init($url);
		$respHeaders = [];
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 8,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_COOKIEFILE => $jar,
			CURLOPT_COOKIEJAR => $jar,
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
		$final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		curl_close($ch); // writes the cookie file
		@chmod($jar, 0600);
		$raw = $raw === false ? '' : (string) $raw;
		return [
			'status' => $status,
			'headers' => $respHeaders,
			'body' => $raw,
			'json' => $raw !== '' ? json_decode($raw, true) : null,
			'ms' => $ms,
			'error' => $error,
			'final_url' => $final,
		];
	}

	/** Result for the test page: no cookie values, and no page HTML (it can echo form tokens). */
	private static function summary(array $r): array
	{
		$headers = array_map(
			static fn (string $h): string => preg_match('/^set-cookie:\s*([^=;\s]+)/i', $h, $m) ? 'set-cookie: ' . $m[1] . '=(hidden)' : $h,
			$r['headers']
		);
		$isJson = is_array($r['json']);
		return [
			'status' => $r['status'],
			'headers' => $headers,
			'body' => $isJson ? $r['body'] : '',
			'json' => $isJson ? $r['json'] : null,
			'ms' => $r['ms'],
			'error' => $r['error'] ?? ($isJson ? null : 'Not a JSON reply (' . number_format(strlen($r['body'])) . ' bytes, ended at ' . preg_replace('/\?.*/', '', $r['final_url']) . ')'),
			'cookies' => self::cookieNames(),
		];
	}

	/** @return array<int, string> names of the cookies in the saved session */
	private static function cookieNames(): array
	{
		$names = [];
		foreach (@file(self::jar(), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
			if ($line === '' || ($line[0] === '#' && !str_starts_with($line, '#HttpOnly_'))) {
				continue;
			}
			$parts = explode("\t", $line);
			if (count($parts) >= 7) {
				$names[] = $parts[5];
			}
		}
		return array_values(array_unique($names));
	}

	private static function jar(): string
	{
		return dirname(Db::path()) . '/enlighten-session.txt';
	}
}
