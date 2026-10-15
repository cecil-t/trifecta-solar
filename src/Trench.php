<?php
declare(strict_types=1);

namespace App;

/**
 * Trench builder: sizes the wire and conduit for the circuits a salesperson lays out through
 * trench segments, and prices the job from catalog parts (category trench_parts). Pure apart
 * from reading the catalog and settings; the build sheet adds the lines it returns to the
 * pricing like any other line.
 *
 * The sizing is an estimate for quoting, not a design: the engineer's planset decides. Tables are
 * from the NEC (2023): ampacity Table 310.16, small conductor limits 240.4(D), conductor DC
 * resistance Chapter 9 Table 8, conductor area Chapter 9 Table 5 (THWN-2), PVC Schedule 40 area
 * Chapter 9 Table 4 (40% fill), adjustment for more than three current-carrying conductors
 * 310.15(C)(1), equipment grounding conductors Table 250.122.
 */
final class Trench
{
	public const METHODS = ['open' => 'Open trench', 'bore' => 'Linear bore', 'customer' => 'Customer trench'];
	public const KINDS = ['ac' => 'AC', 'dc' => 'DC', 'comms' => 'Comms'];
	public const VOLTAGES = ['240' => '240 V single-phase', '208' => '208 V three-phase', '480' => '480 V three-phase'];

	/** Catalog parts the builder prices with: key => [label, unit]. Conduit and wire also carry a size (and wire a material). */
	public const PARTS = [
		'mob_trench'  => ['Trenching mobilization (once per job)', 'ea'],
		'mob_bore'    => ['Boring setup (once per job)', 'ea'],
		'inspection'  => ['Trench inspection', 'ea'],
		'dig'         => ['Open trench, dig and backfill', 'ft'],
		'bore'        => ['Linear bore (borer supplies the conduit)', 'ft'],
		'conduit'     => ['PVC conduit', 'ft'],
		'wire'        => ['Wire', 'ft'],
		'comms'       => ['Communications cable', 'ft'],
		'pull'        => ['Wire pull labor, per circuit foot', 'ft'],
		'termination' => ['Termination (each circuit end and each side of a bore)', 'ea'],
	];

	public const SIZES = ['14', '12', '10', '8', '6', '4', '3', '2', '1', '1/0', '2/0', '3/0', '4/0', '250', '300', '350', '400', '500', '600'];
	public const MATERIALS = ['cu' => 'Copper', 'al' => 'Aluminum'];

	/** NEC Table 310.16 at 75°C (terminations) and 90°C (adjustment for many conductors in one conduit). */
	private const AMP75 = [
		'cu' => ['14' => 20, '12' => 25, '10' => 35, '8' => 50, '6' => 65, '4' => 85, '3' => 100, '2' => 115, '1' => 130, '1/0' => 150, '2/0' => 175, '3/0' => 200, '4/0' => 230, '250' => 255, '300' => 285, '350' => 310, '400' => 335, '500' => 380, '600' => 420],
		'al' => ['12' => 20, '10' => 30, '8' => 40, '6' => 50, '4' => 65, '3' => 75, '2' => 90, '1' => 100, '1/0' => 120, '2/0' => 135, '3/0' => 155, '4/0' => 180, '250' => 205, '300' => 230, '350' => 250, '400' => 270, '500' => 310, '600' => 340],
	];
	private const AMP90 = [
		'cu' => ['14' => 25, '12' => 30, '10' => 40, '8' => 55, '6' => 75, '4' => 95, '3' => 115, '2' => 130, '1' => 145, '1/0' => 170, '2/0' => 195, '3/0' => 225, '4/0' => 260, '250' => 290, '300' => 320, '350' => 350, '400' => 380, '500' => 430, '600' => 475],
		'al' => ['12' => 25, '10' => 35, '8' => 45, '6' => 55, '4' => 75, '3' => 85, '2' => 100, '1' => 115, '1/0' => 135, '2/0' => 150, '3/0' => 175, '4/0' => 205, '250' => 230, '300' => 260, '350' => 280, '400' => 305, '500' => 350, '600' => 385],
	];
	/** 240.4(D): small conductors are limited to these breaker sizes. */
	private const SMALL_LIMIT = ['cu' => ['14' => 15, '12' => 20, '10' => 30], 'al' => ['12' => 15, '10' => 25]];
	/** Chapter 9 Table 8: DC resistance, ohms per 1,000 ft at 75°C, stranded, uncoated copper. */
	private const OHMS = [
		'cu' => ['14' => 3.14, '12' => 1.98, '10' => 1.24, '8' => 0.778, '6' => 0.491, '4' => 0.308, '3' => 0.245, '2' => 0.194, '1' => 0.154, '1/0' => 0.122, '2/0' => 0.0967, '3/0' => 0.0766, '4/0' => 0.0608, '250' => 0.0515, '300' => 0.0429, '350' => 0.0367, '400' => 0.0321, '500' => 0.0258, '600' => 0.0214],
		'al' => ['12' => 3.25, '10' => 2.04, '8' => 1.28, '6' => 0.808, '4' => 0.508, '3' => 0.403, '2' => 0.319, '1' => 0.253, '1/0' => 0.201, '2/0' => 0.159, '3/0' => 0.126, '4/0' => 0.100, '250' => 0.0847, '300' => 0.0707, '350' => 0.0605, '400' => 0.0529, '500' => 0.0424, '600' => 0.0353],
	];
	/** Chapter 9 Table 5: THWN-2 area, square inches (used for aluminum XHHW-2 too; close enough to estimate). */
	private const AREA = ['14' => 0.0097, '12' => 0.0133, '10' => 0.0211, '8' => 0.0366, '6' => 0.0507, '4' => 0.0824, '3' => 0.0973, '2' => 0.1158, '1' => 0.1562,
		'1/0' => 0.1855, '2/0' => 0.2223, '3/0' => 0.2679, '4/0' => 0.3237, '250' => 0.3970, '300' => 0.4608, '350' => 0.5242, '400' => 0.5863, '500' => 0.7073, '600' => 0.8676];
	/** Chapter 9 Table 4: PVC Schedule 40 total internal area, square inches; 40% may be filled. */
	public const CONDUITS = ['1/2' => 0.285, '3/4' => 0.508, '1' => 0.832, '1-1/4' => 1.453, '1-1/2' => 1.986, '2' => 3.291, '2-1/2' => 4.695, '3' => 7.268, '3-1/2' => 9.737, '4' => 12.554];
	private const BREAKERS = [15, 20, 25, 30, 35, 40, 45, 50, 60, 70, 80, 90, 100, 110, 125, 150, 175, 200, 225, 250, 300, 350, 400, 450, 500, 600, 700, 800, 1000, 1200];
	/** Table 250.122: largest breaker => [copper, aluminum] equipment grounding conductor. */
	private const EGC = [15 => ['14', '12'], 20 => ['12', '10'], 60 => ['10', '8'], 100 => ['8', '6'], 200 => ['6', '4'], 300 => ['4', '2'], 400 => ['3', '1'],
		500 => ['2', '1/0'], 600 => ['1', '2/0'], 800 => ['1/0', '3/0'], 1000 => ['2/0', '4/0'], 1200 => ['3/0', '250']];

	/** Conduit trade sizes as strings, smallest first ("1" and "2" come back as integers from array_keys). */
	public static function conduitSizes(): array
	{
		return array_map('strval', array_keys(self::CONDUITS));
	}

	/** Settings the builder uses, as stored on the Rates page (copied into a build sheet's basis). */
	public static function currentSettings(): array
	{
		return [
			'vd_ac' => Presales::num('trench_vd_ac'),
			'vd_dc' => Presales::num('trench_vd_dc'),
			'al_from' => Presales::setting('trench_al_from'),
			'allowance' => Presales::num('trench_allowance_ft'),
			'dc_amps' => Presales::num('trench_dc_amps'),
			'dc_volts' => Presales::num('trench_dc_volts'),
			'neutral' => Presales::setting('trench_ac_neutral') === '1',
			'comms_conduit' => Presales::setting('trench_comms_conduit'),
		];
	}

	/** "conduit|1", "wire|1/0|al", "dig": how a catalog item is found by the builder. */
	public static function partKey(string $part, ?string $size = null, ?string $material = null): string
	{
		return match ($part) {
			'conduit' => 'conduit|' . $size,
			'wire' => 'wire|' . $size . '|' . $material,
			default => $part,
		};
	}

	public static function itemKey(array $item): ?string
	{
		$part = (string) ($item['trench_part'] ?? '');
		if (!isset(self::PARTS[$part])) {
			return null;
		}
		return self::partKey($part, $item['part_size'] ?? null, $item['material'] ?? null);
	}

	/** A readable name for a part key: 'PVC conduit 1-1/4"', 'Wire, aluminum #1/0', '250 kcmil'. */
	public static function partLabel(string $key): string
	{
		$p = explode('|', $key);
		return match ($p[0]) {
			'conduit' => 'PVC conduit ' . $p[1] . '"',
			'wire' => 'Wire, ' . strtolower(self::MATERIALS[$p[2]] ?? $p[2]) . ' ' . self::sizeLabel($p[1]),
			default => self::PARTS[$p[0]][0] ?? $key,
		};
	}

	public static function sizeLabel(string $size): string
	{
		return is_numeric($size) && (int) $size >= 250 ? $size . ' kcmil' : '#' . $size;
	}

	/** Active trench part items by part key (the first by catalog order when there are two). */
	public static function catalogParts(): array
	{
		$out = [];
		foreach (Db::all("SELECT * FROM catalog_items WHERE category = 'trench_parts' AND is_active = 1 ORDER BY sort_order, name") as $it) {
			$k = self::itemKey($it);
			if ($k !== null && !isset($out[$k])) {
				$out[$k] = $it;
			}
		}
		return $out;
	}

	private static function idx(string $size): int
	{
		$i = array_search($size, self::SIZES, true);
		return $i === false ? -1 : $i;
	}

	private static function nextBreaker(float $amps): int
	{
		foreach (self::BREAKERS as $b) {
			if ($b >= $amps - 1e-9) {
				return $b;
			}
		}
		return (int) end(self::BREAKERS);
	}

	private static function egc(int $breaker, string $mat): string
	{
		foreach (self::EGC as $max => [$cu, $al]) {
			if ($breaker <= $max) {
				return $mat === 'al' ? $al : $cu;
			}
		}
		return $mat === 'al' ? '250' : '3/0';
	}

	/** 310.15(C)(1): ampacity factor for this many current-carrying conductors in one conduit. */
	private static function adjust(int $ccc): float
	{
		return match (true) {
			$ccc <= 3 => 1.0,
			$ccc <= 6 => 0.8,
			$ccc <= 9 => 0.7,
			$ccc <= 20 => 0.5,
			$ccc <= 30 => 0.45,
			$ccc <= 40 => 0.4,
			default => 0.35,
		};
	}

	/** Can this size carry the current (with 125% for continuous load) at this many conductors per conduit? */
	private static function carries(string $size, string $mat, float $need, int $ccc): bool
	{
		$a75 = self::AMP75[$mat][$size] ?? null;
		$a90 = self::AMP90[$mat][$size] ?? null;
		if ($a75 === null || $a90 === null) {
			return false;
		}
		$limit = self::SMALL_LIMIT[$mat][$size] ?? INF;
		return min($a75, $limit) >= $need - 1e-9 && $a90 * self::adjust($ccc) >= $need - 1e-9;
	}

	/** Voltage drop in percent: k = 2 for single-phase and DC, sqrt(3) for three-phase; length one way in feet. */
	private static function drop(string $size, string $mat, float $amps, float $feet, float $volts, float $k): float
	{
		$ohms = self::OHMS[$mat][$size] ?? null;
		if ($ohms === null || $volts <= 0) {
			return INF;
		}
		return $k * $amps * $ohms * $feet / 1000 / $volts * 100;
	}

	/** Smallest size that carries the current and keeps the drop at or under the target; null if none does. */
	private static function size(string $mat, float $need, float $amps, float $feet, float $volts, float $k, float $target, int $ccc, string $from): ?string
	{
		foreach (array_slice(self::SIZES, max(0, self::idx($from))) as $s) {
			if (!isset(self::AMP75[$mat][$s])) {
				continue;
			}
			if (self::carries($s, $mat, $need, $ccc) && self::drop($s, $mat, $amps, $feet, $volts, $k) <= $target + 1e-9) {
				return $s;
			}
		}
		return null;
	}

	/** Smallest PVC conduit holding this conductor area at 40% fill; null if more than 4". */
	private static function conduitFor(float $area): ?string
	{
		foreach (self::CONDUITS as $size => $total) {
			if ($total * 0.4 >= $area - 1e-9) {
				return (string) $size;   // "1", "2" are integer keys in PHP
			}
		}
		return null;
	}

	/**
	 * Size and price the trenches.
	 *
	 * @param array $trenches   rows: key, name, method, length_ft
	 * @param array $circuits   rows: key, name, kind, ac_kw, voltage, strings, segments (list of trench keys), size_override, override_reason
	 * @param bool  $inspection charge a trench inspection when there is an open or customer trench
	 * @param array $basis      ['settings' => [...], 'prices' => [part key => [...]]]; missing pieces come from today's rates and catalog
	 * @param float $designAcKw the build sheet's AC kW, used for an AC circuit with no kW typed
	 * @return array lines (for Pricing), segments and circuits (results by key), parts, warnings, missing (part keys with no catalog item), basis (with any new prices)
	 */
	public static function calculate(array $trenches, array $circuits, bool $inspection, array $basis, float $designAcKw, ?array $catalog = null): array
	{
		$set = ($basis['settings'] ?? null) ?: self::currentSettings();
		$basis['settings'] = $set;
		$basis['prices'] = $basis['prices'] ?? [];
		$allow = max(0.0, (float) $set['allowance']);

		$segs = [];
		foreach ($trenches as $t) {
			$len = (float) ($t['length_ft'] ?? 0);
			if ($len <= 0) {
				continue;
			}
			$segs[$t['key']] = ['key' => $t['key'], 'name' => (string) ($t['name'] ?: 'Trench'), 'method' => $t['method'], 'length' => $len, 'conduits' => [], 'circuits' => []];
		}
		$qty = [];
		$add = static function (string $key, float $q) use (&$qty): void {
			if ($q > 0) {
				$qty[$key] = ($qty[$key] ?? 0) + $q;
			}
		};
		$warnings = [];
		$results = [];

		foreach ($circuits as $c) {
			$key = (string) $c['key'];
			$name = trim((string) ($c['name'] ?? '')) ?: (self::KINDS[$c['kind']] ?? 'Circuit');
			$through = array_values(array_filter((array) ($c['segments'] ?? []), static fn ($k) => isset($segs[$k])));
			$r = ['key' => $key, 'name' => $name, 'kind' => $c['kind'], 'length' => null, 'text' => '', 'conduit' => '', 'drop' => null, 'warn' => []];
			if (!$through) {
				$r['warn'][] = 'Pick the trench segments it runs through.';
				$results[$key] = $r;
				continue;
			}
			$run = array_sum(array_map(static fn ($k) => $segs[$k]['length'], $through));
			$length = $run + 2 * $allow;
			$r['length'] = $length;
			$conduits = [];   // [count, size]
			$override = in_array((string) ($c['size_override'] ?? ''), self::SIZES, true) && trim((string) ($c['override_reason'] ?? '')) !== '' ? (string) $c['size_override'] : null;
			if (in_array((string) ($c['size_override'] ?? ''), self::SIZES, true) && $override === null) {
				$r['warn'][] = 'Type a reason to use the wire size override.';
			}

			if ($c['kind'] === 'ac') {
				$kw = $c['ac_kw'] !== null && $c['ac_kw'] !== '' ? (float) $c['ac_kw'] : $designAcKw;
				if ($kw <= 0) {
					$r['warn'][] = 'Needs the AC kW (none typed, and the build sheet has no inverters yet).';
					$results[$key] = $r;
					continue;
				}
				$volts = (float) (isset(self::VOLTAGES[(string) ($c['voltage'] ?? '')]) ? $c['voltage'] : '240');
				$three = $volts !== 240.0;
				$k = $three ? sqrt(3) : 2.0;
				$amps = $kw * 1000 / ($volts * ($three ? sqrt(3) : 1));
				$need = 1.25 * $amps;
				$breaker = self::nextBreaker($need);
				$hots = $three ? 3 : 2;
				$ccc = $hots;   // a neutral carrying only unbalanced current does not count
				$target = (float) $set['vd_ac'];
				$mat = 'cu';
				$size = self::size('cu', $need, $amps, $length, $volts, $k, $target, $ccc, '14');
				$alFrom = (string) $set['al_from'];
				if ($alFrom !== 'never' && self::idx($alFrom) >= 0 && ($size === null || self::idx($size) >= self::idx($alFrom))) {
					$mat = 'al';
					$size = self::size('al', $need, $amps, $length, $volts, $k, $target, $ccc, '6');
				}
				if ($size === null) {
					$size = '600';
					$r['warn'][] = 'Too much current or too long for one set of wires; the designer will run parallel sets. Priced as 600 kcmil.';
				}
				if ($override !== null) {
					if (!isset(self::AMP75[$mat][$override])) {
						$r['warn'][] = self::sizeLabel($override) . ' is not made in ' . strtolower(self::MATERIALS[$mat]) . '; kept ' . self::sizeLabel($size) . '.';
					} else {
						if (!self::carries($override, $mat, $need, $ccc)) {
							$r['warn'][] = self::sizeLabel($override) . ' ' . strtolower(self::MATERIALS[$mat]) . ' is too small for ' . round($need) . ' A.';
						}
						$size = $override;
					}
				}
				$drop = self::drop($size, $mat, $amps, $length, $volts, $k);
				$neutral = !empty($set['neutral']) ? 1 : 0;
				$egc = self::egc($breaker, $mat);
				$count = $hots + $neutral;
				$area = $count * self::AREA[$size] + self::AREA[$egc];
				$conduit = self::conduitFor($area);
				if ($conduit === null) {
					$n = (int) ceil($area / (self::CONDUITS['4'] * 0.4));
					$conduits[] = [$n, '4'];
					$r['warn'][] = 'Needs more than one 4" conduit; priced as ' . $n . '.';
				} else {
					$conduits[] = [1, $conduit];
				}
				$add(self::partKey('wire', $size, $mat), $count * $length);
				$add(self::partKey('wire', $egc, $mat), $length);
				$r['text'] = round($amps, 1) . ' A, ' . $breaker . ' A breaker: ' . $count . ' x ' . self::sizeLabel($size) . ' ' . strtolower(self::MATERIALS[$mat])
					. ($neutral ? ' (incl. neutral)' : '') . ' + ' . self::sizeLabel($egc) . ' ground';
				$r['drop'] = $drop;
				if ($drop > $target + 1e-9) {
					$r['warn'][] = 'Voltage drop ' . number_format($drop, 1) . '% is over the ' . rtrim(rtrim(number_format($target, 1), '0'), '.') . '% target.';
				}
				$r['size'] = $size;
				$r['material'] = $mat;
				$r['kw'] = $kw;
			} elseif ($c['kind'] === 'dc') {
				$n = (int) ($c['strings'] ?? 0);
				if ($n <= 0) {
					$r['warn'][] = 'Needs the number of strings.';
					$results[$key] = $r;
					continue;
				}
				$amps = (float) $set['dc_amps'];
				$need = 1.25 * $amps;
				$volts = (float) $set['dc_volts'];
				$target = (float) $set['vd_dc'];
				// as many string pairs per conduit as #10 copper carries after the adjustment for many conductors
				$per = 1;
				for ($p = $n; $p >= 1; $p--) {
					if (self::carries('10', 'cu', $need, 2 * $p)) {
						$per = $p;
						break;
					}
				}
				$groups = (int) ceil($n / $per);
				$size = self::size('cu', $need, $amps, $length, $volts, 2.0, $target, 2 * $per, '10') ?? '600';
				if ($override !== null) {
					if (!self::carries($override, 'cu', $need, 2 * $per)) {
						$r['warn'][] = self::sizeLabel($override) . ' copper is too small for these strings.';
					}
					$size = $override;
				}
				$drop = self::drop($size, 'cu', $amps, $length, $volts, 2.0);
				$egc = self::idx($size) > self::idx('10') ? $size : '10';
				$egc = self::idx($egc) > self::idx('6') ? '6' : $egc;
				$area = 2 * $per * self::AREA[$size] + self::AREA[$egc];
				$conduit = self::conduitFor($area) ?? '4';
				$conduits[] = [$groups, $conduit];
				$add(self::partKey('wire', $size, 'cu'), 2 * $n * $length);
				$add(self::partKey('wire', $egc, 'cu'), $groups * $length);
				$r['text'] = $n . ' string' . ($n > 1 ? 's' : '') . ': ' . (2 * $n) . ' x ' . self::sizeLabel($size) . ' copper + ' . self::sizeLabel($egc) . ' ground'
					. ($groups > 1 ? ' in ' . $groups . ' conduits (' . $per . ' strings each at most)' : '');
				$r['drop'] = $drop;
				if ($drop > $target + 1e-9) {
					$r['warn'][] = 'Voltage drop ' . number_format($drop, 1) . '% is over the ' . rtrim(rtrim(number_format($target, 1), '0'), '.') . '% target.';
				}
				$r['size'] = $size;
				$r['material'] = 'cu';
				$r['strings'] = $n;
			} else {
				$conduits[] = [1, isset(self::CONDUITS[(string) $set['comms_conduit']]) ? (string) $set['comms_conduit'] : '1'];
				$add('comms', $length);
				$r['text'] = 'Communications cable';
			}

			$add('pull', $length);
			$add('termination', 2);
			foreach ($through as $sk) {
				$seg = &$segs[$sk];
				$seg['circuits'][] = $key;
				foreach ($conduits as [$count, $size]) {
					$seg['conduits'][] = ['circuit' => $name, 'kind' => $c['kind'], 'count' => $count, 'size' => $size];
					if ($seg['method'] === 'bore') {
						$add('termination', 2 * $count);   // extend and terminate on each side of the bore
					} else {
						$add(self::partKey('conduit', $size), $count * $seg['length']);
					}
				}
				unset($seg);
			}
			$r['conduit'] = implode(' + ', array_map(static fn ($x) => ($x[0] > 1 ? $x[0] . ' x ' : '') . $x[1] . '" PVC', $conduits));
			$results[$key] = $r;
		}

		// The digging itself, set up once per job
		$open = $bore = $customer = 0.0;
		foreach ($segs as $s) {
			match ($s['method']) {
				'bore' => $bore += $s['length'],
				'customer' => $customer += $s['length'],
				default => $open += $s['length'],
			};
		}
		$add('dig', $open);
		$add('bore', $bore);
		$add('mob_trench', $open > 0 ? 1 : 0);
		$add('mob_bore', $bore > 0 ? 1 : 0);
		$add('inspection', $inspection && ($open + $customer) > 0 ? 1 : 0);

		// Price every part from the basis, else today's catalog (and remember it)
		$catalog ??= self::catalogParts();
		$order = array_keys(self::PARTS);
		uksort($qty, static function ($a, $b) use ($order) {
			$pa = explode('|', $a);
			$pb = explode('|', $b);
			$oa = array_search($pa[0], $order, true);
			$ob = array_search($pb[0], $order, true);
			if ($oa !== $ob) {
				return $oa <=> $ob;
			}
			if (($pa[2] ?? '') !== ($pb[2] ?? '')) {
				return strcmp($pa[2] ?? '', $pb[2] ?? '');
			}
			$sizes = $pa[0] === 'conduit' ? self::conduitSizes() : self::SIZES;
			return array_search($pa[1] ?? '', $sizes, true) <=> array_search($pb[1] ?? '', $sizes, true);
		});
		$lines = [];
		$missing = [];
		foreach ($qty as $pk => $q) {
			if (!isset($basis['prices'][$pk]) && isset($catalog[$pk])) {
				$it = $catalog[$pk];
				$basis['prices'][$pk] = ['id' => (int) $it['id'], 'name' => $it['name'], 'price' => (float) $it['price'], 'shipping' => (float) $it['shipping'],
					'tax_class' => $it['tax_class'], 'price_date' => $it['price_updated_at']];
			}
			$p = $basis['prices'][$pk] ?? null;
			if ($p === null) {
				$missing[] = $pk;
			}
			$unit = self::PARTS[explode('|', $pk)[0]][1];
			$lines[] = [
				'category' => 'trench_parts', 'catalog_item_id' => $p['id'] ?? null, 'name' => $p['name'] ?? self::partLabel($pk),
				'qty' => $unit === 'ft' ? round($q, 1) : $q, 'qty_rule' => 'manual', 'price' => $p['price'] ?? 0.0, 'shipping' => $p['shipping'] ?? 0.0,
				'setup_cost' => 0, 'min_total' => null, 'tax_class' => $p['tax_class'] ?? 'bos', 'watts' => null, 'ac_kw' => null, 'kwh' => null, 'kw' => null,
				'ext_warranty' => 0, 'ext_warranty_years' => null, 'ext_warranty_cost' => null, 'siw_cost' => null, 'warranty_text' => null,
				'price_date' => $p['price_date'] ?? null, 'note' => null, 'price_override' => null, 'override_reason' => null,
				'trench' => true, 'trench_key' => $pk, 'trench_unit' => $unit,
			];
		}
		if ($missing) {
			$warnings[] = 'No catalog price yet for: ' . implode(', ', array_map([self::class, 'partLabel'], $missing)) . '. Add ' . (count($missing) > 1 ? 'them' : 'it') . ' under Trench builder in the catalog; priced at $0 until then.';
		}

		return ['lines' => $lines, 'segments' => $segs, 'circuits' => $results, 'warnings' => $warnings, 'missing' => $missing, 'basis' => $basis, 'settings' => $set];
	}

	/** Part keys whose catalog price differs from the build sheet's basis today (for the "Catalog price changed" banner). */
	public static function changedParts(array $basis, array $usedKeys, ?array $catalog = null): array
	{
		$catalog ??= self::catalogParts();
		$out = [];
		foreach ($usedKeys as $k) {
			$p = $basis['prices'][$k] ?? null;
			$c = $catalog[$k] ?? null;
			if (!$p || !$c) {
				continue;
			}
			if (abs((float) $c['price'] - (float) $p['price']) > 0.00001 || abs((float) $c['shipping'] - (float) $p['shipping']) > 0.00001 || $c['tax_class'] !== $p['tax_class']) {
				$out[] = $k;
			}
		}
		return $out;
	}
}
