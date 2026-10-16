<?php
declare(strict_types=1);

namespace App;

/**
 * Weather for SAM: NSRDB typical-meteorological-year files (National Laboratory of the Rockies,
 * developer.nlr.gov). One file per NSRDB grid cell (about 2 km), downloaded once and kept in
 * data/solar/<dataset>/<location id>.csv, which SAM reads directly. Not backed up: a lost file is
 * downloaded again.
 *
 * Needs NLR_API_KEY and NLR_EMAIL in .env. The CSV endpoint answers with a redirect to the file on S3.
 */
final class SolarResource
{
	/** NSRDB GOES PSM v4 typical year. Pinned so estimates stay comparable; change on purpose. */
	public const DATASET = 'tmy-2024';
	private const ENDPOINT = 'https://developer.nlr.gov/api/nsrdb/v2/solar/nsrdb-GOES-tmy-v4-0-0-download.csv';
	private const TIMEOUT = 90;

	public static function configured(): bool
	{
		return (string) Config::get('NLR_API_KEY', '') !== '' && (string) Config::get('NLR_EMAIL', '') !== '';
	}

	/** Problem with a typed point, or null when it is usable (NSRDB GOES covers the Americas; checked loosely). */
	public static function pointError(?float $lat, ?float $lon): ?string
	{
		if ($lat === null || $lon === null) {
			return 'Type a latitude and longitude, e.g. 40.1600 and -76.3900.';
		}
		if ($lat < -60 || $lat > 61 || $lon < -180 || $lon > -20) {
			return 'That point is outside NSRDB coverage (the Americas). West longitudes are negative.';
		}
		return null;
	}

	/**
	 * The weather file for a point: from the cache, or downloaded now.
	 *
	 * @return array{ok:bool, error?:string, cached?:bool, row?:array, path?:string, ms?:int}
	 */
	public static function forPoint(float $lat, float $lon, bool $download = true): array
	{
		$start = microtime(true);
		$done = static fn (array $r): array => $r + ['ms' => (int) round((microtime(true) - $start) * 1000)];
		$latKey = number_format($lat, 3, '.', '');
		$lonKey = number_format($lon, 3, '.', '');
		$hit = Db::one('SELECT f.* FROM solar_resource_lookups l JOIN solar_resource_files f ON f.dataset = l.dataset AND f.location_id = l.location_id
			WHERE l.dataset = ? AND l.lat_key = ? AND l.lon_key = ?', [self::DATASET, $latKey, $lonKey]);
		if ($hit && is_file(self::path($hit))) {
			return $done(['ok' => true, 'cached' => true, 'row' => $hit, 'path' => self::path($hit)]);
		}
		if (!$download) {
			return $done(['ok' => false, 'error' => 'Not downloaded yet.']);
		}
		if (!self::configured()) {
			return $done(['ok' => false, 'error' => 'Add NLR_API_KEY and NLR_EMAIL to the server .env file.']);
		}

		$url = self::ENDPOINT . '?' . http_build_query([
			'api_key' => Config::get('NLR_API_KEY'),
			'email' => Config::get('NLR_EMAIL'),
			'wkt' => sprintf('POINT(%.4F %.4F)', $lon, $lat),
			'names' => self::DATASET,
			'interval' => 60,
			'utc' => 'false',
			'leap_day' => 'false',
			// no attributes: every column (SAM uses GHI, DNI, DHI, temperature, wind, albedo, pressure, dew point, humidity)
		]);
		$ctx = stream_context_create(['http' => [
			'method' => 'GET',
			'timeout' => self::TIMEOUT,
			'ignore_errors' => true,
			'follow_location' => 1,
			'max_redirects' => 5,
			'header' => "User-Agent: TrifectaTracker/1.0\r\n",
		]]);
		$body = @file_get_contents($url, false, $ctx);
		$headers = $http_response_header ?? [];
		$status = 0;
		foreach ($headers as $h) {   // the last status line is the final response after redirects
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
				$status = (int) $m[1];
			}
		}
		if ($body === false || $body === '') {
			return $done(['ok' => false, 'error' => 'No answer from NSRDB' . ($status ? ' (HTTP ' . $status . ')' : '') . '.']);
		}
		if ($status !== 200 || !str_starts_with($body, 'Source,')) {
			return $done(['ok' => false, 'error' => 'NSRDB said: ' . self::apiError($body, $status)]);
		}

		$meta = self::meta($body);
		if ($meta === null) {
			return $done(['ok' => false, 'error' => 'NSRDB sent a file this app cannot read (no Location ID).']);
		}
		$rel = 'solar/' . self::DATASET . '/' . preg_replace('/[^0-9A-Za-z_-]/', '', $meta['location_id']) . '.csv';
		$abs = self::dataDir() . '/' . $rel;
		if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0775, true) && !is_dir(dirname($abs))) {
			return $done(['ok' => false, 'error' => 'Could not create ' . dirname($rel) . ' in the data folder.']);
		}
		if (file_put_contents($abs . '.tmp', $body) === false || !rename($abs . '.tmp', $abs)) {
			return $done(['ok' => false, 'error' => 'Could not save the weather file.']);
		}
		$row = ['dataset' => self::DATASET, 'location_id' => $meta['location_id'], 'lat' => $meta['lat'], 'lon' => $meta['lon'],
			'elevation' => $meta['elevation'], 'tz' => $meta['tz'], 'file' => $rel, 'bytes' => strlen($body), 'fetched_at' => now_utc()];
		Db::transaction(static function () use ($row, $latKey, $lonKey): void {
			Db::run('INSERT INTO solar_resource_files (dataset, location_id, lat, lon, elevation, tz, file, bytes, fetched_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
				ON CONFLICT (dataset, location_id) DO UPDATE SET lat = excluded.lat, lon = excluded.lon, elevation = excluded.elevation, tz = excluded.tz,
				file = excluded.file, bytes = excluded.bytes, fetched_at = excluded.fetched_at', array_values($row));
			Db::run('INSERT OR REPLACE INTO solar_resource_lookups (dataset, lat_key, lon_key, location_id) VALUES (?, ?, ?, ?)',
				[$row['dataset'], $latKey, $lonKey, $row['location_id']]);
		});
		$saved = Db::one('SELECT * FROM solar_resource_files WHERE dataset = ? AND location_id = ?', [self::DATASET, $meta['location_id']]);
		return $done(['ok' => true, 'cached' => false, 'row' => $saved, 'path' => $abs]);
	}

	/** Files downloaded so far, newest first. */
	public static function files(int $limit = 20): array
	{
		return Db::all('SELECT f.*, (SELECT COUNT(*) FROM solar_resource_lookups l WHERE l.dataset = f.dataset AND l.location_id = f.location_id) AS lookups
			FROM solar_resource_files f ORDER BY f.fetched_at DESC LIMIT ' . max(1, $limit));
	}

	public static function path(array $row): string
	{
		return self::dataDir() . '/' . $row['file'];
	}

	private static function dataDir(): string
	{
		return dirname(Db::path());
	}

	/** Location ID, point, elevation and time zone from the file's first two lines. */
	private static function meta(string $csv): ?array
	{
		$lines = explode("\n", $csv, 3);
		if (count($lines) < 3) {
			return null;
		}
		$keys = str_getcsv(trim($lines[0]), ',', '"', '');
		$vals = str_getcsv(trim($lines[1]), ',', '"', '');
		$m = @array_combine(array_slice($keys, 0, count($vals)), array_slice($vals, 0, count($keys)));
		if (!$m || !isset($m['Location ID']) || $m['Location ID'] === '') {
			return null;
		}
		$num = static fn (string $k): ?float => isset($m[$k]) && is_numeric($m[$k]) ? (float) $m[$k] : null;
		return ['location_id' => (string) $m['Location ID'], 'lat' => $num('Latitude'), 'lon' => $num('Longitude'),
			'elevation' => $num('Elevation'), 'tz' => $num('Local Time Zone') ?? $num('Time Zone')];
	}

	/** A readable message from an error reply (JSON errors list, or the start of the body). The key is never shown. */
	private static function apiError(string $body, int $status): string
	{
		$j = json_decode($body, true);
		$msg = '';
		if (is_array($j)) {
			$errs = $j['errors'] ?? ($j['error']['message'] ?? null);
			$msg = is_array($errs) ? implode(' ', array_map('strval', $errs)) : (string) ($errs ?? '');
		}
		if ($msg === '') {
			$msg = trim(substr(strip_tags($body), 0, 300));
		}
		$key = (string) Config::get('NLR_API_KEY', '');
		if ($key !== '') {
			$msg = str_replace($key, '[key]', $msg);
		}
		return ($status ? 'HTTP ' . $status . ', ' : '') . $msg;
	}
}
