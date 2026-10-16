<?php
declare(strict_types=1);

namespace App;

/**
 * Google Maps Platform, called only from the server (the key never reaches a browser, so the
 * Content Security Policy stays as is): Geocoding (address to point and parts), Solar API Building
 * Insights (roof segments with pitch, azimuth, area and sun) and Maps Static (an aerial image, passed
 * through to the browser, never stored).
 *
 * Needs GOOGLE_MAPS_API_KEY in .env: a key restricted to the server's IP and to those APIs.
 */
final class GoogleMaps
{
	private const GEOCODE = 'https://maps.googleapis.com/maps/api/geocode/json';
	private const BUILDING = 'https://solar.googleapis.com/v1/buildingInsights:findClosest';
	private const STATIC_MAP = 'https://maps.googleapis.com/maps/api/staticmap';
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
			];
		}
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

	/** @return array{0:int, 1:string, 2:string} status, content type, body */
	private static function fetch(string $url): array
	{
		$ctx = stream_context_create(['http' => [
			'method' => 'GET',
			'timeout' => self::TIMEOUT,
			'ignore_errors' => true,
			'header' => "User-Agent: TrifectaTracker/1.0\r\n",
		]]);
		$body = @file_get_contents($url, false, $ctx);
		$status = 0;
		$type = '';
		foreach ($http_response_header ?? [] as $h) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
				$status = (int) $m[1];
			} elseif (stripos($h, 'Content-Type:') === 0) {
				$type = strtolower(trim(substr($h, 13)));
			}
		}
		return [$status, $type, $body === false ? '' : $body];
	}

	private static function mask(string $msg): string
	{
		$key = (string) Config::get('GOOGLE_MAPS_API_KEY', '');
		return $key !== '' ? str_replace($key, '[key]', $msg) : $msg;
	}
}
