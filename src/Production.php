<?php
declare(strict_types=1);

namespace App;

/**
 * A build sheet's production estimate: SAM's detailed model and PVWatts v8 on the design's own
 * module, inverters and arrays at the opportunity's map point, with NSRDB typical-year weather.
 *
 * inputs() says what is still missing before it can run (as the sales rep fills the build sheet),
 * and builds the request for bin/sam.py's "system" action once nothing is. run() calls SAM and keeps
 * the reply on the design with a key of its inputs, so a changed build sheet shows the estimate is
 * out of date.
 */
final class Production
{
	public const MODELS = ['detailed' => 'SAM detailed model', 'pvwatts' => 'PVWatts v8 basic model'];

	/** Short names for the proposal's second production tile. */
	public const MODEL_SHORT = ['detailed' => 'Detailed model', 'pvwatts' => 'Basic model'];

	/**
	 * Each array's share of year-one production (kWh), by build sheet array index, or null for a
	 * run from before SAM reported its groups. Arrays in one SAM group split its output by panels,
	 * less their shading.
	 *
	 * @return array<int, float>|null
	 */
	public static function arrayKwh(array $prod, array $arrays, string $model): ?array
	{
		$groups = $prod['groups'] ?? [];
		if (!$groups || !array_key_exists('kwh_' . $model, $groups[0]) || !isset($groups[0]['members'])) {
			return null;
		}
		$sent = [];   // index sent to SAM => build sheet index (inputs() skips arrays without panels)
		foreach (array_values($arrays) as $i => $a) {
			if ((int) ($a['panels'] ?? 0) > 0) {
				$sent[] = $i;
			}
		}
		$weight = static fn (array $a): float => (int) $a['panels'] * (1 - min(90.0, max(0.0, (float) ($a['shading_pct'] ?? 0))) / 100);
		$out = [];
		$arrays = array_values($arrays);
		foreach ($groups as $g) {
			$members = array_values(array_filter(array_map(static fn ($k) => $sent[(int) $k] ?? null, (array) $g['members']), static fn ($x) => $x !== null));
			$total = array_sum(array_map(static fn ($i) => $weight($arrays[$i]), $members));
			foreach ($members as $i) {
				$out[$i] = $total > 0 ? (float) $g['kwh_' . $model] * $weight($arrays[$i]) / $total : 0.0;
			}
		}
		return count($out) === count($sent) ? $out : null;
	}

	/** The SAM release behind a run ("2026.7.3.r0.ssc.308" gives "2026.7.3"), or null. */
	public static function samVersion(array $prod): ?string
	{
		return preg_match('/^(\d{4}\.\d+\.\d+)/', (string) ($prod['library_release'] ?? ''), $m) ? $m[1] : null;
	}

	/**
	 * What the estimate needs, from the build sheet as it stands.
	 *
	 * @param array $o      presales_opportunities row (site_lat, site_lon)
	 * @param array $lines  build sheet lines (snapshots), in the order Pricing calculated them
	 * @param array $calc   Designs::calc() for those lines
	 * @return array{missing: list<string>, notes: list<string>, request: ?array, key: ?string}
	 */
	public static function inputs(array $o, array $lines, array $arrays, array $calc): array
	{
		$missing = [];
		$notes = [];
		$catalog = [];
		$ids = array_values(array_unique(array_filter(array_map(static fn ($l) => (int) ($l['catalog_item_id'] ?? 0), $lines))));
		if ($ids) {
			foreach (Db::all('SELECT * FROM catalog_items WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $c) {
				$catalog[(int) $c['id']] = $c;
			}
		}
		// The SAM model of a line: its own snapshot, else the catalog item as it is now
		$model = static function (array $l) use ($catalog): ?array {
			$c = $catalog[(int) ($l['catalog_item_id'] ?? 0)] ?? [];
			$cec = $l['cec_name'] ?? null ?: ($c['cec_name'] ?? null);
			$spec = $l['spec'] ?? null ?: ($c['spec'] ?? null);
			if ($cec) {
				return ['cec' => (string) $cec, 'units' => max(1, (int) ($l['cec_units'] ?? null ?: ($c['cec_units'] ?? 1)))];
			}
			$s = json_decode((string) $spec, true);
			return is_array($s) && $s ? ['spec' => $s, 'units' => 1] : null;
		};
		$qty = static fn (int $i): float => (float) ($calc['lines'][$i]['qty_resolved'] ?? 0);

		// Site
		if ($o['site_lat'] === null || $o['site_lon'] === null) {
			$missing[] = 'The site\'s map point: edit the opportunity and Look up the site address (or type the point).';
		}

		// Module: one type
		$mods = [];
		foreach ($lines as $i => $l) {
			if ($l['category'] === 'modules' && $qty($i) > 0) {
				$mods[] = [$i, $l];
			}
		}
		$moduleReq = null;
		$moduleCount = 0;
		$moduleW = 0.0;
		if (!$mods) {
			$missing[] = 'A module line with a quantity.';
		} elseif (count(array_unique(array_map(static function ($m) use ($model) {
			// Lines of the same module (domestic content or B-grade versions of one panel) model as one
			$mm = $model($m[1]);
			return $mm === null ? 'line:' . ($m[1]['catalog_item_id'] ?? $m[1]['name']) : (isset($mm['cec']) ? 'cec:' . $mm['cec'] : 'spec:' . json_encode($mm['spec']));
		}, $mods))) > 1) {
			$missing[] = 'One module model: production models a single module, and this build sheet\'s modules are different models (SAM CEC model or spec sheet).';
		} else {
			[$i, $l] = $mods[0];
			$moduleCount = (int) round(array_sum(array_map(static fn ($m) => $qty($m[0]), $mods)));
			$moduleW = (float) $l['watts'];
			if (count($mods) > 1) {
				$notes[] = count($mods) . ' module lines of the same model are modeled together as ' . $l['name'] . '.';
			}
			$m = $model($l);
			if (!$m) {
				$missing[] = 'A SAM model for the module "' . $l['name'] . '": set its SAM (CEC) model, or enter its spec sheet, in the catalog.';
			} else {
				$moduleReq = isset($m['cec']) ? $m['cec'] : ['spec' => $m['spec'] + ['watts' => $moduleW]];
			}
		}

		// Inverters or microinverters
		$inv = [];
		$micro = [];
		$optimizers = false;
		foreach ($lines as $i => $l) {
			if ($qty($i) <= 0) {
				continue;
			}
			if ($l['category'] === 'inverters') {
				$inv[] = [$i, $l];
				$optimizers = $optimizers || !empty($l['needs_mlpe']);
			} elseif ($l['category'] === 'mlpe') {
				if ((float) ($l['ac_kw'] ?? 0) > 0) {
					$micro[] = [$i, $l];
				} elseif (preg_match('/optimi[sz]er/i', (string) $l['name'])) {
					$optimizers = true;
				}
			}
		}
		$invReq = null;
		$invCount = 0;
		$mlpe = 'none';
		$pick = static function (array $set) use ($qty): array {
			usort($set, static fn ($a, $b) => $qty($b[0]) * (float) $b[1]['ac_kw'] <=> $qty($a[0]) * (float) $a[1]['ac_kw']);
			return $set[0];
		};
		if ($inv && $micro) {
			$missing[] = 'Either string inverters or microinverters: this build sheet has both.';
		} elseif (!$inv && !$micro) {
			$missing[] = 'An inverter, or microinverters, with a quantity.';
		} else {
			$set = $inv ?: $micro;
			$mlpe = $inv ? ($optimizers ? 'optimizers' : 'none') : 'micro';
			[$i, $l] = $pick($set);
			$m = $model($l);
			if (!$m) {
				$missing[] = 'A SAM model for "' . $l['name'] . '": set its SAM (CEC) model, or enter its spec sheet, in the catalog.';
			} elseif (!(float) $l['ac_kw']) {
				$missing[] = 'The AC kW of "' . $l['name'] . '" in the catalog.';
			} else {
				$unitAc = (float) $l['ac_kw'] / $m['units'];
				$totalAc = array_sum(array_map(static fn ($x) => $qty($x[0]) * (float) $x[1]['ac_kw'], $set));
				$invCount = max(1, (int) round($totalAc / $unitAc));
				if (count(array_unique(array_map(static fn ($x) => (string) ($x[1]['catalog_item_id'] ?? $x[1]['name']), $set))) > 1) {
					$notes[] = 'Mixed inverters are modeled as ' . $invCount . ' x ' . $l['name'] . ($m['units'] > 1 ? ' units' : '') . ' (the same total AC).';
				} elseif ($m['units'] > 1) {
					$notes[] = $l['name'] . ' is modeled as ' . $invCount . ' library units of ' . rtrim(rtrim(number_format($unitAc, 2, '.', ''), '0'), '.') . ' kW.';
				}
				$invReq = isset($m['cec']) ? $m['cec'] : ['spec' => $m['spec'] + ['ac_w' => $unitAc * 1000]];
			}
		}

		// Arrays
		$arr = [];
		$panels = 0;
		foreach ($arrays as $a) {
			$n = (int) ($a['panels'] ?? 0);
			if ($n <= 0) {
				continue;
			}
			$panels += $n;
			$mount = (string) ($a['mount'] ?? 'roof');
			$name = trim((string) ($a['name'] ?? '')) ?: 'an array';
			if ($mount !== 'tracker' && ($a['azimuth'] === null || $a['azimuth'] === '' || $a['tilt'] === null || $a['tilt'] === '')) {
				$missing[] = 'Azimuth and tilt on ' . $name . '.';
				continue;
			}
			$arr[] = ['azimuth' => $mount === 'tracker' ? 180 : (float) $a['azimuth'], 'tilt' => $mount === 'tracker' ? 0 : (float) $a['tilt'],
				'modules' => $n, 'mount' => $mount, 'shading' => (float) ($a['shading_pct'] ?? 0)];
		}
		if ($panels === 0) {
			$missing[] = 'Arrays with their panels, azimuth and tilt.';
		} elseif ($moduleCount && $panels !== $moduleCount) {
			$missing[] = 'Panels on arrays (' . $panels . ') to match the module count (' . $moduleCount . ').';
		}

		if (!Sam::installed()) {
			$missing[] = 'SAM on the server (rebuild the Docker image).';
		}
		if (!SolarResource::configured()) {
			$missing[] = 'NSRDB weather (NLR_API_KEY and NLR_EMAIL in the server .env).';
		}
		if ($missing) {
			return ['missing' => $missing, 'notes' => $notes, 'request' => null, 'key' => null];
		}
		$request = [
			'module' => $moduleReq,
			'inverter' => $invReq,
			'inverter_count' => $invCount,
			'mlpe' => $mlpe,
			'arrays' => $arr,
			'losses' => ['other' => Presales::num('production_other_loss'), 'inverter' => 0,
				'optimizer' => Presales::num('production_optimizer_loss')],
			'sam_adjust' => Presales::num('production_sam_adjust'),
		];
		$point = [round((float) $o['site_lat'], 5), round((float) $o['site_lon'], 5)];
		return ['missing' => [], 'notes' => $notes, 'request' => $request,
			'key' => substr(sha1(json_encode([$request, $point, SolarResource::DATASET])), 0, 16)];
	}

	/** Run SAM for a ready build sheet and keep the result on it. Returns the reply (ok false with error on failure). */
	public static function run(array $o, array $d, array $in): array
	{
		$file = SolarResource::forPoint((float) $o['site_lat'], (float) $o['site_lon']);
		if (!$file['ok']) {
			return ['ok' => false, 'error' => 'Weather: ' . $file['error']];
		}
		$reply = Sam::call(['action' => 'system', 'weather_file' => $file['path']] + $in['request'], 120);
		if (!$reply['ok']) {
			return $reply;
		}
		unset($reply['weather_columns'], $reply['trace']);
		$reply['notes'] = $in['notes'];
		$reply['weather'] = ['dataset' => SolarResource::DATASET, 'location_id' => $file['row']['location_id'] ?? null];
		$reply['settings'] = ['model' => Presales::setting('production_model'), 'other_loss' => Presales::num('production_other_loss'),
			'optimizer_loss' => Presales::num('production_optimizer_loss'), 'sam_adjust' => Presales::num('production_sam_adjust')];
		Db::update('presales_designs', (int) $d['id'], ['production_json' => json_encode($reply), 'production_at' => now_utc(), 'production_key' => $in['key']]);
		return $reply;
	}

	/** The saved estimate, or null. */
	public static function saved(array $d): ?array
	{
		$r = json_decode((string) ($d['production_json'] ?? ''), true);
		return is_array($r) && !empty($r['ok']) ? $r : null;
	}

	/** The model the proposal uses ('detailed' or 'pvwatts') for a saved estimate. */
	public static function chosen(array $r): string
	{
		$m = (string) ($r['settings']['model'] ?? 'detailed');
		return isset(self::MODELS[$m]) ? $m : 'detailed';
	}
}
