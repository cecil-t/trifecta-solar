<?php
declare(strict_types=1);

namespace App;

/**
 * Utility rates from OpenEI's Utility Rate Database (URDB, api.openei.org). OpenEI uses the National
 * Laboratory of the Rockies developer key system, so NLR_API_KEY works here too; OPENEI_API_KEY, when
 * set, is used instead (its own rate limit, revoked separately).
 *
 * search() lists the rates in effect at a point; rate() fetches one rate in full, in the URDB
 * format that SAM reads (PySAM's UtilityRateTools.URDBv8_to_ElectricityRates). Nothing is stored yet.
 */
final class UtilityRates
{
	private const ENDPOINT = 'https://api.openei.org/utility_rates';
	public const SECTORS = ['Residential', 'Commercial', 'Industrial'];
	private const TIMEOUT = 60;

	public static function configured(): bool
	{
		return self::key() !== '';
	}

	/** OPENEI_API_KEY, or NLR_API_KEY (the same developer key system). */
	private static function key(): string
	{
		return (string) (Config::get('OPENEI_API_KEY', '') ?: Config::get('NLR_API_KEY', ''));
	}

	/**
	 * Approved rates in effect at a point for one sector, newest first.
	 *
	 * @return array{ok:bool, error?:string, rates?:array, ms:int}
	 */
	public static function search(float $lat, float $lon, string $sector): array
	{
		$r = self::get([
			'lat' => sprintf('%.4F', $lat),
			'lon' => sprintf('%.4F', $lon),
			'sector' => in_array($sector, self::SECTORS, true) ? $sector : 'Residential',
			'approved' => 'true',
			'detail' => 'full',
			'limit' => 200,
			'orderby' => 'startdate',
			'direction' => 'desc',
		]);
		if (!$r['ok']) {
			return $r;
		}
		$now = time();
		$rates = [];
		foreach ($r['items'] as $item) {
			if (!is_array($item) || empty($item['label'])) {
				continue;
			}
			$end = isset($item['enddate']) && is_numeric($item['enddate']) ? (int) $item['enddate'] : null;
			if ($end !== null && $end < $now) {
				continue;   // retired rate
			}
			$rates[] = self::summary($item);
		}
		return ['ok' => true, 'rates' => $rates, 'ms' => $r['ms']];
	}

	/**
	 * One rate in full, by its URDB label.
	 *
	 * @return array{ok:bool, error?:string, rate?:array, ms:int}
	 */
	public static function rate(string $label): array
	{
		if (!preg_match('/^[0-9a-f]{24}$/', $label)) {
			return ['ok' => false, 'error' => 'That is not a URDB rate label.', 'ms' => 0];
		}
		$r = self::get(['getpage' => $label, 'detail' => 'full']);
		if (!$r['ok']) {
			return $r;
		}
		$item = $r['items'][0] ?? null;
		if (!is_array($item)) {
			return ['ok' => false, 'error' => 'OpenEI has no rate ' . $label . '.', 'ms' => $r['ms']];
		}
		return ['ok' => true, 'rate' => $item, 'ms' => $r['ms']];
	}

	/** The fields the admin page lists for a rate. */
	public static function summary(array $item): array
	{
		$tiers = [];
		foreach ((array) ($item['energyratestructure'] ?? []) as $period) {
			foreach ((array) $period as $t) {
				if (is_array($t) && isset($t['rate'])) {
					$tiers[] = (float) $t['rate'] + (float) ($t['adj'] ?? 0);
				}
			}
		}
		$periods = count((array) ($item['energyratestructure'] ?? []));
		return [
			'label' => (string) $item['label'],
			'utility' => (string) ($item['utility'] ?? ''),
			'name' => (string) ($item['name'] ?? ''),
			'sector' => (string) ($item['sector'] ?? ''),
			'start' => isset($item['startdate']) && is_numeric($item['startdate']) ? (int) $item['startdate'] : null,
			'is_default' => !empty($item['is_default']),
			'fixed' => isset($item['fixedchargefirstmeter']) ? (float) $item['fixedchargefirstmeter'] : null,
			'fixed_units' => (string) ($item['fixedchargeunits'] ?? ''),
			'energy_min' => $tiers ? min($tiers) : null,
			'energy_max' => $tiers ? max($tiers) : null,
			'periods' => $periods,
			'demand' => !empty($item['demandratestructure']) || !empty($item['flatdemandstructure']),
			'uri' => (string) ($item['uri'] ?? ''),
		];
	}

	/** GET the API; returns ok and items, or ok false and a readable error. The key is never shown. */
	private static function get(array $query): array
	{
		$start = microtime(true);
		$done = static fn (array $r): array => $r + ['ms' => (int) round((microtime(true) - $start) * 1000)];
		if (!self::configured()) {
			return $done(['ok' => false, 'error' => 'Add NLR_API_KEY (or OPENEI_API_KEY) to the server .env file.']);
		}
		$url = self::ENDPOINT . '?' . http_build_query(['version' => 'latest', 'format' => 'json', 'api_key' => self::key()] + $query);
		$ctx = stream_context_create(['http' => [
			'method' => 'GET',
			'timeout' => self::TIMEOUT,
			'ignore_errors' => true,
			'header' => "User-Agent: TrifectaTracker/1.0\r\nAccept: application/json\r\n",
		]]);
		$body = @file_get_contents($url, false, $ctx);
		$status = 0;
		foreach ($http_response_header ?? [] as $h) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
				$status = (int) $m[1];
			}
		}
		if ($body === false || $body === '') {
			return $done(['ok' => false, 'error' => 'No answer from OpenEI' . ($status ? ' (HTTP ' . $status . ')' : '') . '.']);
		}
		$j = json_decode($body, true);
		if ($status !== 200 || !is_array($j) || !isset($j['items']) || !is_array($j['items'])) {
			$msg = '';
			if (is_array($j)) {
				$err = $j['error'] ?? $j['errors'] ?? null;
				$msg = is_array($err) ? (string) ($err['message'] ?? implode(' ', array_map('strval', array_filter($err, 'is_scalar')))) : (string) ($err ?? '');
			}
			if ($msg === '') {
				$msg = trim(substr(strip_tags($body), 0, 300));
			}
			$msg = str_replace(self::key(), '[key]', $msg);
			return $done(['ok' => false, 'error' => 'OpenEI said: ' . ($status ? 'HTTP ' . $status . ', ' : '') . $msg]);
		}
		return $done(['ok' => true, 'items' => $j['items']]);
	}
}
