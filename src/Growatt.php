<?php
declare(strict_types=1);

namespace App;

/**
 * Reads Growatt data from the Shiner API (shiner-us.growatt.com), signed in as the installer account
 * (GROWATT_USERNAME / GROWATT_PASSWORD in .env) with HTTP Basic authentication on every request. Growatt
 * documents this for partners ("Production API Guide": site list, device list, site and device energy
 * curves); the same sign-in also answers the portal's alert list and production-by-window call, which
 * the guide does not cover.
 *
 * Nothing is kept between requests: no session, cookie or token. Replies are wrapped as
 * {"code":0,"msg":"SUCCESS","data":...} even where the guide shows the bare data.
 */
final class Growatt
{
	public const BASE = 'https://shiner-us.growatt.com';

	/** Files the earlier captcha sign-in kept next to the database; removed when found. */
	private const OLD_FILES = ['growatt-session.txt', 'growatt-state.json'];

	public static function configured(): bool
	{
		return trim((string) Config::get('GROWATT_USERNAME')) !== '' && (string) Config::get('GROWATT_PASSWORD') !== '';
	}

	public static function available(): bool
	{
		return function_exists('curl_init');
	}

	// ---- data -----------------------------------------------------------------------------------

	/** Any Shiner GET (e.g. /web/v1/sites/1/50?name=&filter=). A request with no reply at all is tried once more. */
	public static function get(string $path): array
	{
		self::dropOldSession();
		$r = self::request($path);
		if ($r['status'] === 0) {
			sleep(5);
			$r = self::request($path);
		}
		$note = match (true) {
			$r['status'] === 401, $r['status'] === 403 => 'Growatt refused the sign-in (HTTP ' . $r['status'] . '). Check GROWATT_USERNAME and GROWATT_PASSWORD in .env.',
			$r['status'] === 200 && is_array($r['json']) && isset($r['json']['code']) && (int) $r['json']['code'] !== 0 => 'Growatt answered with code ' . (int) $r['json']['code'] . ': ' . (string) ($r['json']['msg'] ?? 'no message'),
			default => null,
		};
		return ['url' => self::BASE . preg_replace('/\?.*/', '', $path), 'note' => $note] + self::summary($r);
	}

	/**
	 * Daily production for one site from the site energy curve (type=1d): one bucket per local day in
	 * the window, read from v2_today_production (kWh). Start and end are inclusive and carry the site's
	 * time zone offset; every Trifecta site is set to America/New_York in Shiner, the tracker's own zone.
	 *
	 * Returns the request summary plus 'days' (Y-m-d => kWh) or, when the reply cannot be read, 'days'
	 * null and a 'problem'.
	 */
	public static function curve(string $siteId, \DateTimeInterface $start, \DateTimeInterface $end): array
	{
		$path = '/web/v1/site/' . rawurlencode($siteId) . '/flows/curve/energy?' . http_build_query([
			'startTime' => $start->format(DATE_RFC3339),
			'endTime' => $end->format(DATE_RFC3339),
			'type' => '1d',
		]);
		$r = self::get($path);
		$r['days'] = null;
		$r['problem'] = null;
		if ($r['status'] !== 200 || $r['note'] !== null || !is_array($r['json'])) {
			$r['problem'] = trim(($r['note'] ?? '') . ' HTTP ' . $r['status'] . ($r['error'] ? ', ' . $r['error'] : ''));
			return $r;
		}
		$list = self::bucketList($r['json']);
		if ($list === null) {
			$r['problem'] = 'The reply holds no list of daily buckets.';
			return $r;
		}
		$days = [];
		foreach ($list as $b) {
			if (!is_array($b)) {
				continue;
			}
			$date = self::bucketDate($b['time'] ?? null);
			$kwh = $b['v2_today_production'] ?? null;
			if ($date === null || !is_numeric($kwh)) {
				continue;
			}
			$days[$date] = ($days[$date] ?? 0.0) + (float) $kwh;
		}
		ksort($days);
		$r['days'] = $days;
		return $r;
	}

	/**
	 * The curve's days, or an exception the collector logs as the run's failure.
	 *
	 * @return array<string, float> Y-m-d => kWh
	 */
	public static function daily(string $siteId, \DateTimeInterface $start, \DateTimeInterface $end): array
	{
		$r = self::curve($siteId, $start, $end);
		if ($r['days'] === null) {
			throw new \RuntimeException("Daily energy for site $siteId: " . $r['problem']);
		}
		return $r['days'];
	}

	/**
	 * Production for one site over a window, from the portal's production call: used only for pmax, the
	 * site's total inverter AC rating (W), because Shiner's "capacity" is not filled in. Its eacToday is the
	 * AC energy (kWh) for the whole window despite its name. Without a site the portal returns zeros.
	 *
	 * @return array{kwh:float,pmax_w:?float}
	 */
	public static function production(string $siteId, \DateTimeInterface $start, \DateTimeInterface $end): array
	{
		$r = self::get('/web/v1/inverters/production?' . http_build_query([
			'start' => $start->format(DATE_RFC3339),
			'end' => $end->format(DATE_RFC3339),
			'siteId' => $siteId,
		]));
		$data = $r['json']['data'] ?? null;
		if ($r['status'] !== 200 || $r['note'] !== null || !is_array($data)) {
			$why = $r['note'] ?? $r['error'] ?? (is_string($data) ? substr($data, 0, 120) : (string) ($r['json']['msg'] ?? ''));
			throw new \RuntimeException("Production for site $siteId: HTTP " . $r['status'] . ($why !== '' ? ', ' . $why : ''));
		}
		return [
			'kwh' => (float) ($data['eacToday'] ?? 0),
			'pmax_w' => isset($data['pmax']) && (float) $data['pmax'] > 0 ? (float) $data['pmax'] : null,
		];
	}

	// ---- reply parsing --------------------------------------------------------------------------

	/** The bucket array from a curve reply: bare (as the guide shows), under data, or under data.list. */
	private static function bucketList(array $json): ?array
	{
		$data = array_is_list($json) ? $json : ($json['data'] ?? null);
		if (is_array($data) && !array_is_list($data)) {
			$data = $data['list'] ?? $data['curve'] ?? $data['items'] ?? null;
		}
		return is_array($data) && array_is_list($data) ? $data : null;
	}

	/**
	 * A bucket's local date. A date-time string is read as written (the bucket starts at local midnight,
	 * with the offset of the zone it was asked in); an epoch number (seconds or milliseconds) is converted
	 * to the tracker's zone.
	 */
	private static function bucketDate(mixed $time): ?string
	{
		if (is_string($time) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $time, $m)) {
			return $m[1];
		}
		if (is_numeric($time) && (float) $time > 0) {
			$ts = (float) $time > 1e11 ? (int) ((float) $time / 1000) : (int) $time;
			return date('Y-m-d', $ts);
		}
		return null;
	}

	// ---- transport ------------------------------------------------------------------------------

	private static function request(string $path): array
	{
		$ch = curl_init(self::BASE . $path);
		$respHeaders = [];
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_ENCODING => '',
			CURLOPT_USERAGENT => 'TrifectaTracker/1.0',
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
			CURLOPT_USERPWD => trim((string) Config::get('GROWATT_USERNAME')) . ':' . (string) Config::get('GROWATT_PASSWORD'),
			CURLOPT_HTTPHEADER => ['Accept: application/json'],
			CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
				$t = trim($line);
				if ($t !== '') {
					if (preg_match('/^(authorization|set-cookie):/i', $t, $m)) {
						$t = strtolower($m[1]) . ': (hidden)';
					}
					$respHeaders[] = $t;
				}
				return strlen($line);
			},
		]);
		$start = microtime(true);
		$raw = curl_exec($ch);
		$ms = (int) round((microtime(true) - $start) * 1000);
		$error = $raw === false ? curl_error($ch) : null;
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		$raw = $raw === false ? '' : (string) $raw;
		return ['status' => $status, 'headers' => $respHeaders, 'body' => $raw, 'json' => $raw !== '' ? json_decode($raw, true) : null, 'ms' => $ms, 'error' => $error];
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

	private static function dropOldSession(): void
	{
		foreach (self::OLD_FILES as $name) {
			$file = dirname(Db::path()) . '/' . $name;
			if (is_file($file)) {
				@unlink($file);
			}
		}
	}
}
