<?php
declare(strict_types=1);

namespace App;

/**
 * The site layout's live rough production estimate. bin/sam.py's "yield_grid" action gives PVWatts
 * v8 kWh per kW DC at the site for a grid of tilts and azimuths (one grid per mount, cached next to
 * the weather file); the layout page interpolates it for each array as panels are drawn
 * (public/assets/js/layout.js, the same math as kwh() here).
 *
 * The grid knows nothing about the inverters, clipping or the detailed model, so it is scaled to
 * the build sheet's own production run when there is one: ratio = the run's total in the model the
 * build sheet uses / the grid's estimate for the arrays that run had. With no run yet, the ratio
 * comes from a check run of the build sheet as it stands (not saved on the build sheet; its totals
 * are cached next to the weather file by the run's inputs key), when it has what production needs;
 * otherwise the grid's PVWatts number is shown as it is.
 */
final class YieldEstimate
{
	/** Bump when yield_grid's math changes, so cached grids are made again. */
	private const VERSION = 1;

	public const MOUNTS = ['roof', 'ground', 'tracker'];

	/**
	 * One mount's grid at a point, from the cache or made now (about 6 seconds).
	 *
	 * @return array{ok:bool, error?:string, grid?:array}
	 */
	public static function grid(string $mount, float $lat, float $lon): array
	{
		$file = SolarResource::forPoint($lat, $lon);
		if (!$file['ok']) {
			return ['ok' => false, 'error' => 'Weather: ' . $file['error']];
		}
		$losses = ['other' => Presales::num('production_other_loss')];
		$key = substr(sha1(json_encode([self::VERSION, $mount, $losses, SolarResource::DATASET])), 0, 10);
		$cache = preg_replace('/\.csv$/', '', $file['path']) . '.yield-' . $mount . '-' . $key . '.json';
		$hit = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
		if (is_array($hit)) {
			return ['ok' => true, 'grid' => $hit];
		}
		$reply = Sam::call(['action' => 'yield_grid', 'weather_file' => $file['path'], 'mount' => $mount, 'losses' => $losses, 'inverter_eff' => 96], 120);
		if (!$reply['ok']) {
			return ['ok' => false, 'error' => 'SAM: ' . ($reply['error'] ?? 'no answer')];
		}
		$grid = $mount === 'tracker' ? ['value' => (float) $reply['value']]
			: ['tilts' => $reply['tilts'], 'azimuths' => $reply['azimuths'], 'grid' => $reply['grid']];
		@file_put_contents($cache . '.tmp', json_encode($grid)) && @rename($cache . '.tmp', $cache);
		return ['ok' => true, 'grid' => $grid];
	}

	/** kWh per kW DC for one orientation: bilinear between grid points, azimuth wrapping around. */
	public static function perKw(array $grids, string $mount, float $tilt, float $azimuth): ?float
	{
		$mount = self::mount($mount);
		if ($mount === 'tracker') {
			return isset($grids['tracker']['value']) ? (float) $grids['tracker']['value'] : null;
		}
		$g = $grids[$mount] ?? null;
		if (!$g) {
			return null;
		}
		$tilts = $g['tilts'];
		$azs = $g['azimuths'];
		$tStep = $tilts[1] - $tilts[0];
		$tilt = max($tilts[0], min($tilts[count($tilts) - 1], $tilt));
		$ti = min(count($tilts) - 2, (int) floor(($tilt - $tilts[0]) / $tStep));
		$tf = ($tilt - $tilts[$ti]) / $tStep;
		$aStep = $azs[1] - $azs[0];
		$az = fmod(fmod($azimuth, 360) + 360, 360);
		$ai = ((int) floor($az / $aStep)) % count($azs);
		$af = ($az - $azs[$ai]) / $aStep;
		$aj = ($ai + 1) % count($azs);
		$row = static fn (int $r): float => $g['grid'][$r][$ai] * (1 - $af) + $g['grid'][$r][$aj] * $af;
		return $row($ti) * (1 - $tf) + $row($ti + 1) * $tf;
	}

	/**
	 * Year-one kWh of arrays from the grids, before scaling.
	 *
	 * @param list<array{mount:string, tilt:float, azimuth:float, kw:float, shading:float}> $arrays
	 */
	public static function kwh(array $grids, array $arrays): ?float
	{
		$sum = 0.0;
		foreach ($arrays as $a) {
			$y = self::perKw($grids, $a['mount'], (float) $a['tilt'], (float) $a['azimuth']);
			if ($y === null) {
				return null;
			}
			$sum += $y * (float) $a['kw'] * (1 - min(90.0, max(0.0, (float) $a['shading'])) / 100);
		}
		return $sum;
	}

	public static function mount(string $m): string
	{
		return in_array($m, self::MOUNTS, true) ? $m : ($m === '' ? 'roof' : 'ground');
	}

	/**
	 * What the layout page needs: the grids for the mounts asked for, the scale to the build sheet's
	 * model, a line saying what the number is based on, and the yearly use (null when unknown).
	 */
	public static function forLayout(array $o, array $d, array $mounts): array
	{
		if ($o['site_lat'] === null || $o['site_lon'] === null) {
			return ['ok' => false, 'error' => 'The opportunity has no map point.'];
		}
		if (!Sam::installed()) {
			return ['ok' => false, 'error' => 'SAM is not installed on this server.'];
		}
		[$lat, $lon] = [(float) $o['site_lat'], (float) $o['site_lon']];
		$model = Savings::model($d);
		$modelName = Production::MODELS[$model];

		// Scale: the build sheet's saved run, else a check run of it as it stands
		$run = Production::saved($d);
		$source = 'saved';
		if (!$run || empty($run['groups'])) {
			$run = null;
			$lines = Designs::lines((int) $d['id']);
			$arrays = Designs::arrays((int) $d['id']);
			$in = Production::inputs($o, $lines, $arrays, Designs::calc($d, $lines, $arrays));
			$file = $in['missing'] ? null : SolarResource::forPoint($lat, $lon);
			if ($file && $file['ok']) {
				// Kept by the inputs' key, so the same build sheet isn't run again on every visit
				$cache = preg_replace('/\.csv$/', '', $file['path']) . '.check-' . $in['key'] . '.json';
				$run = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
				if (!is_array($run)) {
					$r = Production::compute($o, $in);
					$run = $r['ok'] ? ['groups' => $r['groups'], 'module_w' => $r['module_w'],
						'detailed' => ['annual_kwh' => $r['detailed']['annual_kwh']], 'pvwatts' => ['annual_kwh' => $r['pvwatts']['annual_kwh']]] : null;
					if ($run) {
						@file_put_contents($cache . '.tmp', json_encode($run)) && @rename($cache . '.tmp', $cache);
					}
				}
				$source = 'check';
			}
		}
		$runArrays = [];
		foreach ($run['groups'] ?? [] as $g) {
			$runArrays[] = ['mount' => self::mount((string) $g['mount']), 'tilt' => (float) $g['tilt'], 'azimuth' => (float) $g['azimuth'],
				'kw' => (int) $g['modules'] * (float) ($run['module_w'] ?? 0) / 1000, 'shading' => (float) $g['shading']];
		}

		$need = array_values(array_unique(array_merge(
			array_values(array_intersect(self::MOUNTS, $mounts)) ?: ['roof'],
			array_map(static fn ($a) => $a['mount'], $runArrays),
		)));
		$grids = [];
		foreach ($need as $m) {
			$g = self::grid($m, $lat, $lon);
			if (!$g['ok']) {
				return ['ok' => false, 'error' => $g['error']];
			}
			$grids[$m] = $g['grid'];
		}

		$ratio = 1.0;
		$basis = 'PVWatts v8 from the panels\' directions, before inverters.'
			. ($model === 'detailed' ? ' Run production on the build sheet to match the ' . $modelName . '.' : '');
		$rough = $runArrays ? self::kwh($grids, $runArrays) : null;
		if ($run && $rough && (float) ($run[$model]['annual_kwh'] ?? 0) > 0) {
			$ratio = (float) $run[$model]['annual_kwh'] / $rough;
			$basis = $source === 'saved'
				? $modelName . ', scaled from this build sheet\'s production run.'
				: $modelName . ', scaled from a check run of the build sheet\'s equipment.';
		}
		$usage = Savings::usage($o);
		return ['ok' => true, 'grids' => $grids, 'ratio' => round($ratio, 5), 'basis' => $basis,
			'usage' => $usage ? round($usage['annual']) : null, 'model' => $model];
	}
}
