<?php
declare(strict_types=1);

namespace App;

/**
 * One-line site addresses: split into street, city, state and ZIP (any common order, including
 * monitoring portals' "Main St 12, Town, Pennsylvania, United States, 17601"), then found on the
 * map. Google Geocoding (when the key is set) cleans up the address and gives the point; the Census
 * says which municipality and county contain it (the official boundaries); OpenEI says which
 * utilities serve the point. Every result is a suggestion the user confirms.
 */
final class Address
{
	public const STATES = [
		'pennsylvania' => 'PA', 'maryland' => 'MD', 'delaware' => 'DE', 'new jersey' => 'NJ', 'new york' => 'NY',
		'virginia' => 'VA', 'west virginia' => 'WV', 'ohio' => 'OH',
	];
	private const SUFFIXES = 'st|street|rd|road|ave|avenue|dr|drive|ln|lane|ct|court|pike|way|blvd|boulevard|hwy|highway|pl|place|cir|circle|ter|terrace|pkwy|parkway|tpke|turnpike|trl|trail|run|row';
	private const COUNTRY = ['united states', 'united states of america', 'usa', 'us', 'u.s.', 'u.s.a.'];

	/**
	 * Split a typed or pasted address. Missing parts come back as ''.
	 *
	 * @return array{street:string, city:string, state:string, zip:string}
	 */
	public static function parse(string $one): array
	{
		$one = trim((string) preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "\t"], ', ', $one)));
		$out = ['street' => '', 'city' => '', 'state' => '', 'zip' => ''];
		if ($one === '') {
			return $out;
		}
		$parts = array_values(array_filter(array_map('trim', explode(',', $one)), static fn ($p) => $p !== ''));
		$rest = [];
		foreach ($parts as $p) {
			$low = strtolower(rtrim($p, '.'));
			if (in_array($low, self::COUNTRY, true)) {
				continue;
			}
			// "PA 17601", "Pennsylvania 17601", "17601", "17601-1234"
			if (preg_match('/^(?:([A-Za-z][A-Za-z .]*?)\s+)?(\d{5})(?:-\d{4})?$/', $p, $m)) {
				$out['zip'] = $m[2];
				if (!empty($m[1]) && ($st = self::state($m[1])) !== null) {
					$out['state'] = $st;
				}
				continue;
			}
			if (($st = self::state($p)) !== null && $out['state'] === '') {
				$out['state'] = $st;
				continue;
			}
			$rest[] = $p;
		}
		// No commas: "12 Main St Lancaster PA 17601" keeps only the ZIP and state found at the end
		if (count($rest) === 1 && $out['zip'] === '' && preg_match('/^(.*?)\s+([A-Za-z]{2})\s+(\d{5})(?:-\d{4})?$/', $rest[0], $m) && self::state($m[2]) !== null) {
			$rest = [$m[1]];
			$out['state'] = (string) self::state($m[2]);
			$out['zip'] = $m[3];
		}
		$out['street'] = self::street((string) ($rest[0] ?? ''));
		$out['city'] = (string) ($rest[1] ?? '');
		// "58 Sawmill Rd Wernersville": the city is what follows the last street-type word
		if ($out['city'] === '' && preg_match('/^(\d.*?\b(?:' . self::SUFFIXES . ')\.?)\s+([A-Za-z][A-Za-z .\'-]+)$/i', $out['street'], $m)) {
			$out['street'] = $m[1];
			$out['city'] = $m[2];
		}
		return $out;
	}

	/** Two-letter state for a name or code, or null. */
	public static function state(string $s): ?string
	{
		$s = strtolower(trim($s, " ."));
		if (isset(self::STATES[$s])) {
			return self::STATES[$s];
		}
		$codes = array_flip(array_map('strtolower', self::STATES));
		return strlen($s) === 2 && isset($codes[$s]) ? strtoupper($s) : null;
	}

	/** "Oak Hill Rd 12" (house number last, as some portals write it) becomes "12 Oak Hill Rd". */
	public static function street(string $s): string
	{
		$s = trim($s);
		if ($s !== '' && !preg_match('/^\d/', $s) && preg_match('/^(.*\D)\s+(\d+[A-Za-z]?(?:-\d+)?)$/', $s, $m)) {
			return $m[2] . ' ' . trim($m[1]);
		}
		return $s;
	}

	/**
	 * Find an address: the split parts (Google's when it matched), the map point, the municipality
	 * and county, and the utilities OpenEI lists at the point, with what each came from.
	 */
	public static function lookup(string $one): array
	{
		$parts = self::parse($one);
		$out = ['ok' => true, 'parts' => $parts, 'lat' => null, 'lon' => null, 'source' => 'parsed', 'approx' => false, 'note' => null, 'geo' => null, 'utility' => null];
		$line = Geo::address($parts['street'], $parts['city'], $parts['state'], $parts['zip']);

		if (GoogleMaps::configured() && trim($one) !== '') {
			$g = GoogleMaps::geocode($line !== '' ? $line : $one);
			if ($g['ok'] && in_array($g['parts']['state'], ['PA', 'MD', 'DE', 'NJ', 'NY', 'VA', 'WV', 'OH'], true) && $g['parts']['street'] !== '') {
				$gp = $g['parts'];
				$out['parts'] = ['street' => $gp['street'], 'city' => $gp['city'] ?: $parts['city'], 'state' => $gp['state'], 'zip' => $gp['zip'] ?: $parts['zip']];
				$out['lat'] = $g['lat'];
				$out['lon'] = $g['lon'];
				$out['source'] = 'google';
				$out['note'] = $g['partial'] || !in_array($g['precision'], ['ROOFTOP', 'RANGE_INTERPOLATED'], true)
					? 'Google matched only part of this address; check the point.' : null;
			} elseif ($g['ok']) {
				// The town or ZIP, not the house: close enough for weather (NSRDB cells are about 2 km), not for the municipality
				$out['lat'] = $g['lat'];
				$out['lon'] = $g['lon'];
				$out['source'] = 'google_approx';
				$out['approx'] = true;
				$out['note'] = 'Google found the town or ZIP, not the house. That point is close enough for weather, but check the municipality and utility by hand.';
			} else {
				$out['note'] = $g['error'];
			}
		}

		$p = $out['parts'];
		$geo = null;
		if (Geo::address($p['street'], $p['city'], $p['state'], $p['zip']) !== '') {
			$geo = Geo::lookup($p['street'], $p['city'], $p['state'], $p['zip']);
		}
		if (($geo === null || $geo['status'] !== 'found') && $out['lat'] !== null && empty($out['approx'])) {
			$geo = Geo::atPoint((float) $out['lat'], (float) $out['lon']) ?? $geo;
		}
		if ($geo !== null) {
			$out['geo'] = Geo::forClient($geo);
		}
		if ($geo !== null && $geo['status'] === 'found') {
			if (($out['lat'] === null || $out['approx']) && $geo['lat'] !== null) {
				// the Census (or OpenStreetMap) found the house: better than Google's town or ZIP
				$out['lat'] = $geo['lat'];
				$out['lon'] = $geo['lon'];
				$out['source'] = $geo['source'] ?: 'census';
				$out['approx'] = false;
				$out['note'] = null;
			}
		}

		if ($out['lat'] !== null && UtilityRates::configured()) {
			$names = UtilityRates::utilitiesAt((float) $out['lat'], (float) $out['lon']);
			if ($names !== null) {
				$ours = null;
				foreach ($names as $n) {
					if ($u = UtilityRates::ourUtility($n)) {
						$ours = $u + ['openei' => $n];
						break;
					}
				}
				$out['utility'] = ['ours' => $ours, 'openei' => array_slice($names, 0, 4)];
			}
		}
		if ($out['lat'] !== null) {
			$out['lat'] = round((float) $out['lat'], 6);
			$out['lon'] = round((float) $out['lon'], 6);
		}
		return $out;
	}

	/** Where a point came from, for the page. */
	public static function sourceLabel(?string $source): string
	{
		return match ($source) {
			'google' => 'Google',
			'google_approx' => 'Google, town or ZIP only',
			'census' => 'Census',
			'osm' => 'OpenStreetMap',
			'osm_street' => 'OpenStreetMap (road only)',
			'manual' => 'typed in',
			default => '',
		};
	}
}
