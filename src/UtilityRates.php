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

	/**
	 * The utilities OpenEI lists rates for at a point (its service territories), cached for 30 days
	 * by point (about 100 m). Returns OpenEI utility names, most rates first, or null when OpenEI
	 * can't be reached.
	 *
	 * @return list<string>|null
	 */
	public static function utilitiesAt(float $lat, float $lon): ?array
	{
		$key = sprintf('openei-utilities:%.3F,%.3F', $lat, $lon);
		$hit = Db::one('SELECT result, fetched_at FROM geo_cache WHERE address_key = ?', [$key]);
		if ($hit && strtotime($hit['fetched_at']) > time() - 30 * 86400) {
			$list = json_decode($hit['result'], true);
			if (is_array($list)) {
				return $list;
			}
		}
		$r = self::get(['lat' => sprintf('%.4F', $lat), 'lon' => sprintf('%.4F', $lon), 'approved' => 'true', 'detail' => 'minimal', 'limit' => 200]);
		if (!$r['ok']) {
			return null;
		}
		$count = [];
		foreach ($r['items'] as $item) {
			$u = trim((string) ($item['utility'] ?? ''));
			if ($u !== '') {
				$count[$u] = ($count[$u] ?? 0) + 1;
			}
		}
		arsort($count);
		$list = array_keys($count);
		Db::run('INSERT INTO geo_cache (address_key, result, fetched_at) VALUES (?, ?, ?)
				 ON CONFLICT(address_key) DO UPDATE SET result = excluded.result, fetched_at = excluded.fetched_at',
			[$key, json_encode($list), now_utc()]);
		return $list;
	}

	/** Our utilities row for an OpenEI utility name, or null. */
	public static function ourUtility(string $openei): ?array
	{
		foreach (Db::all('SELECT id, name FROM utilities ORDER BY name') as $u) {
			if (self::sameUtility((string) $u['name'], $openei)) {
				return $u;
			}
		}
		return null;
	}

	/**
	 * Every approved version of the rates at a point for one sector (retired ones too), in full,
	 * oldest first, for the rate history check.
	 *
	 * @return array{ok:bool, error?:string, items?:list<array>, ms:int}
	 */
	public static function history(float $lat, float $lon, string $sector): array
	{
		$r = self::get([
			'lat' => sprintf('%.4F', $lat), 'lon' => sprintf('%.4F', $lon),
			'sector' => in_array($sector, self::SECTORS, true) ? $sector : 'Residential',
			'approved' => 'true', 'detail' => 'full', 'limit' => 500, 'orderby' => 'startdate', 'direction' => 'asc',
		]);
		if (!$r['ok']) {
			return $r;
		}
		$items = array_values(array_filter($r['items'], static fn ($i) => is_array($i) && !empty($i['label'])));
		return ['ok' => true, 'items' => $items, 'ms' => $r['ms']];
	}

	/**
	 * A rate's versions as a history: per version its dates and cents per kWh at the use given (from
	 * $bills, label => annual bill), and the yearly change over 3 and 5 years between the version in
	 * effect then and the one in effect now (compound, per year). Null where there is no version that old.
	 */
	public static function trend(array $versions, array $bills, float $monthlyKwh, ?int $now = null): array
	{
		$now ??= time();
		$rows = [];
		foreach ($versions as $v) {
			$bill = $bills[$v['label']] ?? null;
			$rows[] = [
				'label' => $v['label'], 'start' => isset($v['startdate']) ? (int) $v['startdate'] : null,
				'end' => isset($v['enddate']) && is_numeric($v['enddate']) ? (int) $v['enddate'] : null,
				'bill' => $bill, 'cents' => $bill !== null ? $bill / ($monthlyKwh * 12) * 100 : null,
				'sell' => self::sellRates($v), 'uri' => (string) ($v['uri'] ?? ''),
			];
		}
		usort($rows, static fn ($a, $b) => (int) $a['start'] <=> (int) $b['start']);
		$at = static function (int $t) use ($rows): ?array {
			$hit = null;
			foreach ($rows as $r) {
				if ($r['start'] !== null && $r['start'] <= $t && $r['cents'] !== null) {
					$hit = $r;
				}
			}
			return $hit;
		};
		$change = static function (int $years) use ($at, $now): ?array {
			$then = $at((int) strtotime('-' . $years . ' years', $now));
			$today = $at($now);
			if (!$then || !$today || $then['cents'] <= 0) {
				return null;
			}
			return ['pct' => (pow($today['cents'] / $then['cents'], 1 / $years) - 1) * 100, 'from' => $then['cents'], 'to' => $today['cents']];
		};
		return ['rows' => $rows, 'y3' => $change(3), 'y5' => $change(5), 'oldest' => $rows[0]['start'] ?? null];
	}

	/** Sell (export) rates a URDB rate gives on its energy tiers, if any. */
	public static function sellRates(array $item): array
	{
		$out = [];
		foreach ((array) ($item['energyratestructure'] ?? []) as $period) {
			foreach ((array) $period as $t) {
				if (is_array($t) && isset($t['sell']) && is_numeric($t['sell'])) {
					$out[] = (float) $t['sell'];
				}
			}
		}
		return array_values(array_unique($out));
	}

	/** Our utility names as OpenEI spells the company (FirstEnergy's PA companies go by their legal names there). */
	private const ALIASES = [
		'meted' => 'metropolitanedison', 'penelec' => 'pennsylvaniaelectric', 'westpennpower' => 'westpenn',
		'ppl' => 'pplelectric', 'peco' => 'pecoenergy', 'pennpower' => 'pennsylvaniapower', 'duquesne' => 'duquesnelight',
	];

	/** Whether an OpenEI utility name is the utility we call $ours. */
	public static function sameUtility(string $ours, string $openei): bool
	{
		$n = static fn (string $s): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower($s));
		$a = $n($ours);
		$b = $n($openei);
		if ($a === '' || $b === '') {
			return false;
		}
		return str_contains($b, $a) || (isset(self::ALIASES[$a]) && str_contains($b, self::ALIASES[$a]));
	}

	/** One line describing a rate for a picker: energy cents per kWh and the monthly customer charge. */
	public static function describe(array $s): string
	{
		$c = static fn (?float $v): string => $v === null ? '' : rtrim(rtrim(number_format($v * 100, 2, '.', ''), '0'), '.');
		$parts = [];
		if ($s['energy_min'] !== null) {
			$parts[] = ($s['energy_min'] === $s['energy_max'] ? $c($s['energy_min']) : $c($s['energy_min']) . ' to ' . $c($s['energy_max'])) . ' cents/kWh';
		}
		if ($s['fixed'] !== null && $s['fixed'] > 0) {
			$parts[] = '$' . number_format($s['fixed'], 2) . ' ' . ($s['fixed_units'] === '$/month' || $s['fixed_units'] === '' ? 'a month' : $s['fixed_units']);
		}
		if ($s['periods'] > 1) {
			$parts[] = $s['periods'] . ' time periods';
		}
		if ($s['demand']) {
			$parts[] = 'demand charges';
		}
		if ($s['is_default']) {
			$parts[] = 'OpenEI default';
		}
		return implode(', ', $parts);
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
