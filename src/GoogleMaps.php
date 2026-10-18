<?php
declare(strict_types=1);

namespace App;

/**
 * Google Maps Platform, called only from the server (the key never reaches a browser, so the
 * Content Security Policy stays as is): Geocoding (address to point and parts), Solar API Building
 * Insights (roof segments with pitch, azimuth, area and sun), Maps Static (an aerial image, passed
 * through to the browser, never stored) and Map Tiles (satellite tiles for the site layout, passed
 * through the same way; only the tile session token is kept, in a file next to the database).
 *
 * Needs GOOGLE_MAPS_API_KEY in .env: a key restricted to the server's IP and to those APIs.
 */
final class GoogleMaps
{
	private const GEOCODE = 'https://maps.googleapis.com/maps/api/geocode/json';
	private const BUILDING = 'https://solar.googleapis.com/v1/buildingInsights:findClosest';
	private const STATIC_MAP = 'https://maps.googleapis.com/maps/api/staticmap';
	private const TILE_SESSION = 'https://tile.googleapis.com/v1/createSession';
	private const TILES = 'https://tile.googleapis.com/v1/2dtiles/';
	private const VIEWPORT = 'https://tile.googleapis.com/tile/v1/viewport';
	private const TIMEOUT = 30;

	public static function configured(): bool
	{
		return (string) Config::get('GOOGLE_MAPS_API_KEY', '') !== '';
	}

	/**
	 * Address to point. The best match only, with its parts.
	 *
	 * @return array{ok:bool, error?:string, address?:string, lat?:float, lon?:float, precision?:string, parts?:array, ms:int}
	 */
	public static function geocode(string $address): array
	{
		$r = self::get(self::GEOCODE, ['address' => $address, 'region' => 'us']);
		if (!$r['ok']) {
			return $r;
		}
		$j = $r['json'];
		$status = (string) ($j['status'] ?? '');
		if ($status === 'ZERO_RESULTS') {
			return ['ok' => false, 'error' => 'Google found no match for that address.', 'ms' => $r['ms']];
		}
		if ($status !== 'OK' || empty($j['results'][0])) {
			return ['ok' => false, 'error' => 'Geocoding said: ' . $status . (isset($j['error_message']) ? ', ' . self::mask((string) $j['error_message']) : ''), 'ms' => $r['ms']];
		}
		$best = $j['results'][0];
		$parts = [];
		foreach ((array) ($best['address_components'] ?? []) as $c) {
			foreach ((array) ($c['types'] ?? []) as $t) {
				$parts[$t] ??= $t === 'administrative_area_level_1' ? (string) ($c['short_name'] ?? '') : (string) ($c['long_name'] ?? '');
			}
		}
		return [
			'ok' => true,
			'address' => (string) ($best['formatted_address'] ?? ''),
			'lat' => (float) ($best['geometry']['location']['lat'] ?? 0),
			'lon' => (float) ($best['geometry']['location']['lng'] ?? 0),
			'precision' => (string) ($best['geometry']['location_type'] ?? ''),
			'partial' => !empty($best['partial_match']),
			'parts' => [
				'street' => trim(($parts['street_number'] ?? '') . ' ' . ($parts['route'] ?? '')),
				'city' => $parts['locality'] ?? $parts['sublocality'] ?? $parts['postal_town'] ?? $parts['administrative_area_level_3'] ?? '',
				'township' => $parts['administrative_area_level_3'] ?? '',
				'county' => $parts['administrative_area_level_2'] ?? '',
				'state' => $parts['administrative_area_level_1'] ?? '',
				'zip' => $parts['postal_code'] ?? '',
			],
			'ms' => $r['ms'],
		];
	}

	/**
	 * Solar API Building Insights for the building closest to a point (any imagery quality).
	 *
	 * @return array{ok:bool, error?:string, building?:array, ms:int}
	 */
	public static function buildingInsights(float $lat, float $lon): array
	{
		$r = self::get(self::BUILDING, ['location.latitude' => sprintf('%.6F', $lat), 'location.longitude' => sprintf('%.6F', $lon), 'requiredQuality' => 'LOW']);
		if (!$r['ok']) {
			return $r;
		}
		$j = $r['json'];
		$sp = (array) ($j['solarPotential'] ?? []);
		$segments = [];
		foreach ((array) ($sp['roofSegmentStats'] ?? []) as $s) {
			$pitch = (float) ($s['pitchDegrees'] ?? 0);
			$q = (array) ($s['stats']['sunshineQuantiles'] ?? []);
			$segments[] = [
				'pitch' => $pitch,
				'pitch_12' => 12 * tan(deg2rad($pitch)),
				'azimuth' => (float) ($s['azimuthDegrees'] ?? 0),
				'area_m2' => (float) ($s['stats']['areaMeters2'] ?? 0),
				'sun_median' => $q ? (float) $q[intdiv(count($q), 2)] : null,
				'height_m' => isset($s['planeHeightAtCenterMeters']) ? (float) $s['planeHeightAtCenterMeters'] : null,
				'center' => isset($s['center']['latitude']) ? [(float) $s['center']['latitude'], (float) $s['center']['longitude']] : null,
				'box' => isset($s['boundingBox']['sw']['latitude'], $s['boundingBox']['ne']['latitude'])
					? [(float) $s['boundingBox']['sw']['latitude'], (float) $s['boundingBox']['sw']['longitude'], (float) $s['boundingBox']['ne']['latitude'], (float) $s['boundingBox']['ne']['longitude']]
					: null,
			];
		}
		$hulls = self::faceOutlines($sp, $segments);
		foreach ($segments as $i => &$seg) {
			$seg['hull'] = $hulls[$i] ?? null;
		}
		unset($seg);
		usort($segments, static fn ($a, $b) => $b['area_m2'] <=> $a['area_m2']);
		$date = (array) ($j['imageryDate'] ?? []);
		return ['ok' => true, 'building' => [
			'center_lat' => (float) ($j['center']['latitude'] ?? 0),
			'center_lon' => (float) ($j['center']['longitude'] ?? 0),
			'quality' => (string) ($j['imageryQuality'] ?? ''),
			'imagery_date' => $date ? sprintf('%04d-%02d-%02d', (int) ($date['year'] ?? 0), (int) ($date['month'] ?? 0), (int) ($date['day'] ?? 0)) : '',
			'roof_m2' => isset($sp['wholeRoofStats']['areaMeters2']) ? (float) $sp['wholeRoofStats']['areaMeters2'] : null,
			'max_panels' => isset($sp['maxArrayPanelsCount']) ? (int) $sp['maxArrayPanelsCount'] : null,
			'panel_watts' => isset($sp['panelCapacityWatts']) ? (float) $sp['panelCapacityWatts'] : null,
			'max_sun_hours' => isset($sp['maxSunshineHoursPerYear']) ? (float) $sp['maxSunshineHoursPerYear'] : null,
			'segments' => $segments,
		], 'ms' => $r['ms']];
	}

	/**
	 * An outline of each roof face: the convex hull of the panels Google fits on it (Building
	 * Insights' solarPanels, by segment index), as [lat, lon] points. Faces with no panels get none.
	 *
	 * @return array<int, list<array{0:float, 1:float}>>
	 */
	private static function faceOutlines(array $sp, array $segments): array
	{
		$h = (float) ($sp['panelHeightMeters'] ?? 0);
		$w = (float) ($sp['panelWidthMeters'] ?? 0);
		$panels = (array) ($sp['solarPanels'] ?? []);
		if ($h <= 0 || $w <= 0 || !$panels) {
			return [];
		}
		$lat0 = (float) ($panels[0]['center']['latitude'] ?? 0);
		$lon0 = (float) ($panels[0]['center']['longitude'] ?? 0);
		$my = 111320.0;
		$mx = 111320.0 * cos(deg2rad($lat0));
		$pts = [];
		foreach ($panels as $p) {
			$i = (int) ($p['segmentIndex'] ?? -1);
			if (!isset($segments[$i], $p['center']['latitude'])) {
				continue;
			}
			$az = deg2rad($segments[$i]['azimuth']);
			[$along, $slope] = ($p['orientation'] ?? '') === 'PORTRAIT' ? [$w, $h] : [$h, $w];
			$slope *= cos(deg2rad($segments[$i]['pitch']));
			// Meters east and north; e runs along the eave, d down the slope
			$x = ((float) $p['center']['longitude'] - $lon0) * $mx;
			$y = ((float) $p['center']['latitude'] - $lat0) * $my;
			$e = [cos($az), -sin($az)];
			$d = [sin($az), cos($az)];
			foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$a, $b]) {
				$pts[$i][] = [$x + $e[0] * $along / 2 * $a + $d[0] * $slope / 2 * $b, $y + $e[1] * $along / 2 * $a + $d[1] * $slope / 2 * $b];
			}
		}
		$out = [];
		foreach ($pts as $i => $list) {
			$out[$i] = array_map(static fn ($q) => [round($lat0 + $q[1] / $my, 7), round($lon0 + $q[0] / $mx, 7)], self::hull($list));
		}
		return $out;
	}

	/** Convex hull (monotone chain) of [x, y] points. */
	private static function hull(array $p): array
	{
		usort($p, static fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
		if (count($p) < 3) {
			return $p;
		}
		$cross = static fn ($o, $a, $b) => ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);
		$lower = $upper = [];
		foreach ($p as $q) {
			while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $q) <= 0) {
				array_pop($lower);
			}
			$lower[] = $q;
		}
		foreach (array_reverse($p) as $q) {
			while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $q) <= 0) {
				array_pop($upper);
			}
			$upper[] = $q;
		}
		array_pop($lower);
		array_pop($upper);
		return array_merge($lower, $upper);
	}

	/**
	 * An aerial (satellite) image centred on a point, as bytes for passing straight to the browser.
	 *
	 * @return array{ok:bool, error?:string, type?:string, bytes?:string}
	 */
	public static function aerial(float $lat, float $lon, int $zoom = 20): array
	{
		if (!self::configured()) {
			return ['ok' => false, 'error' => 'Add GOOGLE_MAPS_API_KEY to the server .env file.'];
		}
		$url = self::STATIC_MAP . '?' . http_build_query(['center' => sprintf('%.6F,%.6F', $lat, $lon), 'zoom' => max(15, min(21, $zoom)),
			'size' => '640x400', 'scale' => 2, 'maptype' => 'satellite', 'key' => Config::get('GOOGLE_MAPS_API_KEY')]);
		[$status, $type, $body] = self::fetch($url);
		if ($status !== 200 || !str_starts_with($type, 'image/') || $body === '') {
			return ['ok' => false, 'error' => 'Maps Static said: HTTP ' . $status . ', ' . self::mask(trim(substr(strip_tags($body), 0, 300)))];
		}
		return ['ok' => true, 'type' => $type, 'bytes' => $body];
	}

	/**
	 * One satellite map tile (Web Mercator, 256 px) for the site layout, as bytes for passing to the
	 * browser, with Google's cache header. Starts a tile session when there is none or it expired.
	 *
	 * @return array{ok:bool, status:int, error?:string, type?:string, bytes?:string, cache?:string}
	 */
	public static function tile(int $z, int $x, int $y): array
	{
		if (!self::configured()) {
			return ['ok' => false, 'status' => 503, 'error' => 'Add GOOGLE_MAPS_API_KEY to the server .env file.'];
		}
		for ($try = 0; $try < 2; $try++) {
			$session = self::tileSession($try > 0);
			if ($session === null) {
				return ['ok' => false, 'status' => 502, 'error' => 'Google would not start a Map Tiles session. Is the Map Tiles API enabled for the key?'];
			}
			[$status, $type, $body, $headers] = self::fetch(self::TILES . $z . '/' . $x . '/' . $y . '?' . http_build_query(['session' => $session, 'key' => Config::get('GOOGLE_MAPS_API_KEY')]));
			if ($status === 200 && str_starts_with($type, 'image/') && $body !== '') {
				return ['ok' => true, 'status' => 200, 'type' => $type, 'bytes' => $body, 'cache' => (string) ($headers['cache-control'] ?? '')];
			}
			if (!in_array($status, [400, 401, 403], true)) {
				break;  // 404: no imagery at this zoom here
			}
		}
		return ['ok' => false, 'status' => $status === 404 ? 404 : 502, 'error' => 'Map Tiles said: HTTP ' . $status];
	}

	/**
	 * Attribution and the deepest imagery zoom for an area (Map Tiles viewport information).
	 *
	 * @return array{ok:bool, error?:string, copyright?:string, max_zoom?:int}
	 */
	public static function tileViewport(int $zoom, float $north, float $south, float $east, float $west): array
	{
		$session = self::configured() ? self::tileSession() : null;
		if ($session === null) {
			return ['ok' => false, 'error' => self::configured() ? 'No Map Tiles session.' : 'Add GOOGLE_MAPS_API_KEY to the server .env file.'];
		}
		$r = self::get(self::VIEWPORT, ['session' => $session, 'zoom' => $zoom, 'north' => sprintf('%.6F', $north), 'south' => sprintf('%.6F', $south),
			'east' => sprintf('%.6F', $east), 'west' => sprintf('%.6F', $west)]);
		if (!$r['ok']) {
			return $r;
		}
		$max = null;
		$lat = ($north + $south) / 2;
		$lon = ($east + $west) / 2;
		foreach ((array) ($r['json']['maxZoomRects'] ?? []) as $rect) {
			if ($lat <= (float) ($rect['north'] ?? 0) && $lat >= (float) ($rect['south'] ?? 0) && $lon <= (float) ($rect['east'] ?? 0) && $lon >= (float) ($rect['west'] ?? 0)) {
				$max = max($max ?? 0, (int) ($rect['maxZoom'] ?? 0));
			}
		}
		return ['ok' => true, 'copyright' => (string) ($r['json']['copyright'] ?? ''), 'max_zoom' => $max];
	}

	/** The Map Tiles session token (satellite), kept until shortly before it expires. */
	private static function tileSession(bool $fresh = false): ?string
	{
		$file = dirname(Db::path()) . '/google-tiles-session.json';
		$have = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
		if (!$fresh && is_array($have) && ($have['expiry'] ?? 0) > time() + 3600 && ($have['session'] ?? '') !== '') {
			return (string) $have['session'];
		}
		[$status, , $body] = self::fetch(self::TILE_SESSION . '?' . http_build_query(['key' => Config::get('GOOGLE_MAPS_API_KEY')]), 'POST',
			(string) json_encode(['mapType' => 'satellite', 'language' => 'en-US', 'region' => 'US']));
		$j = json_decode($body, true);
		if ($status !== 200 || !is_array($j) || empty($j['session'])) {
			return null;
		}
		@file_put_contents($file . '.tmp', (string) json_encode(['session' => $j['session'], 'expiry' => (int) ($j['expiry'] ?? time() + 86400)]))
			&& @rename($file . '.tmp', $file);
		return (string) $j['session'];
	}

	/** GET a JSON API; ok and json, or ok false and a readable error. The key is never shown. */
	private static function get(string $endpoint, array $query): array
	{
		$start = microtime(true);
		$done = static fn (array $r): array => $r + ['ms' => (int) round((microtime(true) - $start) * 1000)];
		if (!self::configured()) {
			return $done(['ok' => false, 'error' => 'Add GOOGLE_MAPS_API_KEY to the server .env file.']);
		}
		[$status, , $body] = self::fetch($endpoint . '?' . http_build_query($query + ['key' => Config::get('GOOGLE_MAPS_API_KEY')]));
		if ($body === '') {
			return $done(['ok' => false, 'error' => 'No answer from Google' . ($status ? ' (HTTP ' . $status . ')' : '') . '.']);
		}
		$j = json_decode($body, true);
		if (!is_array($j)) {
			return $done(['ok' => false, 'error' => 'Google sent something this app cannot read (HTTP ' . $status . ').']);
		}
		if ($status !== 200) {
			$msg = (string) ($j['error']['message'] ?? $j['error_message'] ?? $j['status'] ?? '');
			if ($status === 404 && str_contains($endpoint, 'solar.googleapis.com')) {
				$msg = 'No Solar API data for a building at that point (Google has no roof model there yet).';
			}
			return $done(['ok' => false, 'error' => 'Google said: HTTP ' . $status . ($msg !== '' ? ', ' . self::mask($msg) : '')]);
		}
		return $done(['ok' => true, 'json' => $j]);
	}

	/** @return array{0:int, 1:string, 2:string, 3:array<string,string>} status, content type, body, headers (lowercase names) */
	private static function fetch(string $url, string $method = 'GET', ?string $json = null): array
	{
		$ctx = stream_context_create(['http' => [
			'method' => $method,
			'timeout' => self::TIMEOUT,
			'ignore_errors' => true,
			'header' => "User-Agent: TrifectaTracker/1.0\r\n" . ($json !== null ? "Content-Type: application/json\r\n" : ''),
		] + ($json !== null ? ['content' => $json] : [])]);
		$body = @file_get_contents($url, false, $ctx);
		$status = 0;
		$headers = [];
		foreach ($http_response_header ?? [] as $h) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
				$status = (int) $m[1];
				$headers = [];
			} elseif (str_contains($h, ':')) {
				[$k, $v] = explode(':', $h, 2);
				$headers[strtolower(trim($k))] = trim($v);
			}
		}
		return [$status, strtolower($headers['content-type'] ?? ''), $body === false ? '' : $body, $headers];
	}

	private static function mask(string $msg): string
	{
		$key = (string) Config::get('GOOGLE_MAPS_API_KEY', '');
		return $key !== '' ? str_replace($key, '[key]', $msg) : $msg;
	}
}
