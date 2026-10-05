<?php
declare(strict_types=1);

namespace App;

/**
 * Which township / borough / city and county a site address is in, from the US Census geocoder
 * (free, no key). When the Census can't match the address (common on rural roads), OpenStreetMap's
 * Nominatim finds the point and the Census says which municipality contains it.
 *
 * In PA every address is in a county subdivision, which is the township, borough or city.
 * In MD and DE those subdivisions aren't governments, so the municipality is the incorporated
 * place when there is one and otherwise none (the county).
 *
 * Results are cached by address in geo_cache: found for a year, no match for 30 days.
 */
final class Geo
{
	private const CENSUS = 'https://geocoding.geo.census.gov/geocoder/geographies/';
	private const NOMINATIM = 'https://nominatim.openstreetmap.org/search';
	private const LAYERS = 'Counties,County Subdivisions,Incorporated Places';
	private const STATE_FIPS = ['42' => 'PA', '24' => 'MD', '10' => 'DE'];
	private const TIMEOUT = 6;
	public const SOURCES = ['census' => 'Census address match', 'osm' => 'OpenStreetMap address, Census boundaries', 'osm_street' => 'approximate: OpenStreetMap found the road, not the house'];

	/** One-line address for lookups and change detection, or '' when there isn't enough to look up. */
	public static function address(?string $street, ?string $city, ?string $state, ?string $zip): string
	{
		$clean = static fn ($s) => trim((string) preg_replace('/\s+/', ' ', (string) $s));
		[$street, $city, $state, $zip] = [$clean($street), $clean($city), strtoupper($clean($state)), $clean($zip)];
		if ($street === '' || !preg_match('/\d/', $street) || ($city === '' && $zip === '') || ($state === '' && $zip === '')) {
			return '';
		}
		return trim($street . ', ' . $city . ', ' . trim($state . ' ' . $zip), ', ');
	}

	public static function addressOf(array $p): string
	{
		return self::address($p['site_street'] ?? null, $p['site_city'] ?? null, $p['site_state'] ?? null, $p['site_zip'] ?? null);
	}

	/**
	 * @return array{status:string, muni:?string, county:?string, state:?string, lat:?float, lon:?float, source:?string, matched:?string}|null
	 *         null when the services couldn't be reached (try again later); status is found or no_match.
	 */
	public static function lookup(string $street, string $city, string $state, string $zip): ?array
	{
		$address = self::address($street, $city, $state, $zip);
		if ($address === '') {
			return null;
		}
		$key = strtolower($address);
		$hit = Db::one('SELECT result, fetched_at FROM geo_cache WHERE address_key = ?', [$key]);
		if ($hit) {
			$r = json_decode($hit['result'], true);
			$maxAge = ($r['status'] ?? '') === 'found' ? 365 : 30;
			if (is_array($r) && strtotime($hit['fetched_at']) > time() - $maxAge * 86400) {
				return $r;
			}
		}

		$r = self::census('onelineaddress', ['address' => $address]);
		if ($r === null) {
			return null;
		}
		if ($r['status'] !== 'found') {
			$point = self::nominatim($street, $city, $state, $zip);
			if ($point) {
				$byPoint = self::census('coordinates', ['x' => $point[1], 'y' => $point[0]]);
				if ($byPoint && $byPoint['status'] === 'found') {
					$r = ['lat' => $point[0], 'lon' => $point[1], 'source' => $point[3], 'matched' => $point[2]] + $byPoint;
				}
			}
		}
		Db::run('INSERT INTO geo_cache (address_key, result, fetched_at) VALUES (?, ?, ?)
				 ON CONFLICT(address_key) DO UPDATE SET result = excluded.result, fetched_at = excluded.fetched_at',
			[$key, json_encode($r), now_utc()]);
		return $r;
	}

	/** Query the Census geocoder: an address match, or the geographies at a point. */
	private static function census(string $kind, array $params): ?array
	{
		$url = self::CENSUS . $kind . '?' . http_build_query($params + [
			'benchmark' => 'Public_AR_Current', 'vintage' => 'Current_Current', 'layers' => self::LAYERS, 'format' => 'json',
		]);
		$json = self::get($url);
		if ($json === null || !isset($json['result'])) {
			return null;
		}
		if ($kind === 'onelineaddress') {
			$m = $json['result']['addressMatches'][0] ?? null;
			if (!$m) {
				return self::noMatch();
			}
			$geo = $m['geographies'] ?? [];
			$extra = ['lat' => (float) $m['coordinates']['y'], 'lon' => (float) $m['coordinates']['x'], 'source' => 'census', 'matched' => (string) $m['matchedAddress']];
		} else {
			$geo = $json['result']['geographies'] ?? [];
			$extra = ['lat' => (float) $params['y'], 'lon' => (float) $params['x'], 'source' => 'census', 'matched' => null];
		}
		return self::fromGeographies($geo) + $extra;
	}

	/** Turn the Census layers into municipality, county and state. Public so it can be tested with a fixture. */
	public static function fromGeographies(array $geo): array
	{
		$county = $geo['Counties'][0] ?? null;
		$state = self::STATE_FIPS[$county['STATE'] ?? ''] ?? null;
		if (!$county || !$state) {
			return self::noMatch(); // outside the states the app covers
		}
		$muni = $state === 'PA'
			? ($geo['County Subdivisions'][0]['NAME'] ?? null)
			: ($geo['Incorporated Places'][0]['NAME'] ?? null);
		return ['status' => 'found', 'muni' => $muni, 'county' => (string) $county['BASENAME'], 'state' => $state];
	}

	private static function noMatch(): array
	{
		return ['status' => 'no_match', 'muni' => null, 'county' => null, 'state' => null, 'lat' => null, 'lon' => null, 'source' => null, 'matched' => null];
	}

	/** @return array{0:float,1:float,2:string,3:string}|null lat, lon, display name, source (osm, or osm_street when only the road was found) */
	private static function nominatim(string $street, string $city, string $state, string $zip): ?array
	{
		$url = self::NOMINATIM . '?' . http_build_query(array_filter([
			'street' => $street, 'city' => $city, 'state' => $state, 'postalcode' => $zip,
			'countrycodes' => 'us', 'format' => 'jsonv2', 'limit' => 1, 'addressdetails' => 0,
		], static fn ($v) => $v !== ''));
		$json = self::get($url);
		$hit = $json[0] ?? null;
		// A town, ZIP code or county centroid says nothing about the site, so those don't count
		if (!$hit || in_array($hit['addresstype'] ?? '', ['city', 'town', 'village', 'hamlet', 'municipality', 'county', 'postcode', 'state', 'country'], true)
			|| in_array($hit['category'] ?? '', ['boundary', 'place'], true) && ($hit['type'] ?? '') !== 'house') {
			return null;
		}
		$source = ($hit['category'] ?? '') === 'highway' ? 'osm_street' : 'osm';
		return [(float) $hit['lat'], (float) $hit['lon'], (string) ($hit['display_name'] ?? ''), $source];
	}

	private static function get(string $url): ?array
	{
		$ctx = stream_context_create(['http' => [
			'timeout' => self::TIMEOUT,
			'header' => 'User-Agent: TrifectaOpsTracker/1.0 (+' . Version::REPO_URL . ")\r\nAccept: application/json\r\n",
		]]);
		$raw = @file_get_contents($url, false, $ctx);
		if ($raw === false) {
			return null;
		}
		$json = json_decode($raw, true);
		return is_array($json) ? $json : null;
	}

	// ------------------------------------------------------------------ matching to the app's lists

	/**
	 * The app's municipality and county for a lookup result.
	 * @return array{municipality_id:?int, municipality_label:?string, county_id:?int, name:?string, county_label:?string}
	 */
	public static function match(array $r): array
	{
		$out = ['municipality_id' => null, 'municipality_label' => null, 'county_id' => null, 'name' => null, 'county_label' => null];
		if (($r['status'] ?? '') !== 'found') {
			return $out;
		}
		$county = Db::one('SELECT id, name, state_code FROM counties WHERE name = ? COLLATE NOCASE AND state_code = ?', [$r['county'], $r['state']]);
		$out['county_id'] = $county ? (int) $county['id'] : null;
		$out['county_label'] = $r['county'] . ' Co., ' . $r['state'];
		if ($r['muni'] !== null) {
			$out['name'] = Municipalities::displayName((string) $r['muni']);
			if ($county) {
				$m = Db::one('SELECT id, name FROM municipalities WHERE name_key = ? AND county_id = ?', [Municipalities::nameKey((string) $r['muni']), $county['id']]);
				if ($m) {
					$out['municipality_id'] = (int) $m['id'];
					$out['municipality_label'] = $m['name'];
				}
			}
		}
		return $out;
	}

	/** Lookup result from a project's stored geo_* columns, or null when it hasn't been looked up. */
	public static function stored(array $p): ?array
	{
		if (empty($p['geo_checked_at'])) {
			return null;
		}
		return [
			'status' => $p['geo_status'], 'muni' => $p['geo_muni'], 'county' => $p['geo_county'], 'state' => $p['geo_state'],
			'lat' => $p['geo_lat'] !== null ? (float) $p['geo_lat'] : null, 'lon' => $p['geo_lon'] !== null ? (float) $p['geo_lon'] : null,
			'source' => $p['geo_source'], 'matched' => null,
		];
	}

	/** Columns to store a lookup result on a project. */
	public static function columns(array $r, string $address): array
	{
		return [
			'geo_address' => $address, 'geo_checked_at' => now_utc(), 'geo_status' => $r['status'],
			'geo_muni' => $r['muni'], 'geo_county' => $r['county'], 'geo_state' => $r['state'],
			'geo_lat' => $r['lat'], 'geo_lon' => $r['lon'], 'geo_source' => $r['source'],
		];
	}

	/**
	 * Does the project's municipality disagree with its lookup? False when there's no usable lookup,
	 * no municipality chosen, or the user kept their choice (muni_confirmed_id).
	 */
	public static function mismatch(array $p, ?array $r = null): bool
	{
		$r ??= self::stored($p);
		if (!$r || $r['status'] !== 'found' || empty($p['municipality_id'])) {
			return false;
		}
		if ((int) ($p['muni_confirmed_id'] ?? 0) === (int) $p['municipality_id']) {
			return false;
		}
		$have = Db::one('SELECT m.name_key, c.name AS county, c.state_code FROM municipalities m JOIN counties c ON c.id = m.county_id WHERE m.id = ?', [$p['municipality_id']]);
		if (!$have) {
			return false;
		}
		$sameCounty = strcasecmp($have['county'], (string) $r['county']) === 0 && $have['state_code'] === $r['state'];
		if ($r['muni'] === null) {
			return !$sameCounty; // MD / DE outside a town: only the county can be checked
		}
		return !$sameCounty || $have['name_key'] !== Municipalities::nameKey((string) $r['muni']);
	}

	/** What the browser needs to show a lookup: the result, its match in the app's lists, and wording. */
	public static function forClient(?array $r): array
	{
		if ($r === null) {
			return ['ok' => false];
		}
		$m = self::match($r);
		return [
			'ok' => true, 'status' => $r['status'], 'source' => $r['source'],
			'label' => $r['status'] !== 'found' ? null : ($m['name'] !== null ? $m['name'] . ', ' . $m['county_label'] : 'No municipality, ' . $m['county_label']),
		] + $m;
	}
}
