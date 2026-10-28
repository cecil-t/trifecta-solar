<?php
declare(strict_types=1);

namespace App;

/**
 * A build sheet's site layout: panels drawn on the satellite view (public/assets/js/layout.js draws
 * and edits it; this class checks what it sends, keeps the build sheet's arrays in step and draws
 * the layout for the proposal).
 *
 * Coordinates are meters on a flat local map around the layout's origin point: x east, y south (as
 * on screen). An array is a grid of panel spots turned to face its azimuth: local x runs along the
 * eave, local y runs up the slope, and spot (row, column) holds a panel when it is in the array's
 * cells. Trackers are drawn flat (as stowed). Seen from above, a sloped run is shorter than it is on the roof, so the up-slope size of
 * each panel and row gap is multiplied by cos(pitch); along the eave nothing changes.
 */
final class Layout
{
	public const MAX_ARRAYS = 60;
	public const MAX_PANELS = 20000;
	public const MAX_MEASURES = 100;
	private const EARTH = 156543.03392804097;   // meters per pixel at zoom 0 on the equator (256 px tiles)
	/** Width over height of the proposal's aerial box (7.3 by 3.1 in); the layout page's proposal view frame uses it too. */
	public const PROPOSAL_ASPECT = 7.3 / 3.1;

	/** Which module a line is: its catalog item, or (a custom line) its name. */
	public static function moduleKey(array $line): string
	{
		return !empty($line['catalog_item_id']) ? 'c' . (int) $line['catalog_item_id'] : 'n' . substr(md5((string) $line['name']), 0, 10);
	}

	/**
	 * The build sheet's modules, one entry per distinct module (lines of the same item add up), with
	 * the size in meters from the line, its spec values, the catalog item or SAM's library (null when
	 * none of them has it) and the quantity typed on the build sheet.
	 *
	 * @return list<array{key:string, name:string, watts:float, length:?float, width:?float, qty:float, manual:bool}>
	 */
	public static function modules(array $lines): array
	{
		$out = [];
		foreach ($lines as $l) {
			if (($l['category'] ?? '') !== 'modules') {
				continue;
			}
			$key = self::moduleKey($l);
			if (isset($out[$key])) {
				$out[$key]['qty'] += (float) ($l['qty'] ?? 0);
				continue;
			}
			$out[$key] = ['key' => $key] + self::size($l) + ['name' => (string) $l['name'], 'watts' => (float) ($l['watts'] ?? 0),
				'qty' => (float) ($l['qty'] ?? 0), 'manual' => ($l['qty_rule'] ?? 'manual') === 'manual'];
		}
		return array_values($out);
	}

	/** The first module (what older layouts were drawn with), or null. */
	public static function module(array $lines): ?array
	{
		return self::modules($lines)[0] ?? null;
	}

	/** @return array{length:?float, width:?float} meters */
	private static function size(array $m): array
	{
		$len = (float) ($m['length_mm'] ?? 0);
		$wid = (float) ($m['width_mm'] ?? 0);
		if (!$len || !$wid) {
			$spec = json_decode((string) ($m['spec'] ?? ''), true) ?: [];
			$len = (float) ($spec['length_mm'] ?? 0);
			$wid = (float) ($spec['width_mm'] ?? 0);
		}
		if ((!$len || !$wid) && !empty($m['catalog_item_id'])) {
			// Lines added before the catalog had the size: it isn't a price, so today's catalog value is fine
			$c = Db::one('SELECT length_mm, width_mm FROM catalog_items WHERE id = ?', [(int) $m['catalog_item_id']]);
			$len = (float) ($c['length_mm'] ?? 0);
			$wid = (float) ($c['width_mm'] ?? 0);
		}
		if ((!$len || !$wid) && ($m['cec_name'] ?? '') !== '') {
			[$len, $wid] = CecLibrary::moduleSize((string) $m['cec_name']) ?? [0, 0];
		}
		$ok = $len >= 300 && $wid >= 300;
		return ['length' => $ok ? max($len, $wid) / 1000 : null, 'width' => $ok ? min($len, $wid) / 1000 : null];
	}

	/** The module an array is drawn with (older layouts have one for all). */
	public static function moduleOf(array $layout, array $a): array
	{
		return $layout['modules'][$a['mod'] ?? ''] ?? $layout['module'];
	}

	/**
	 * Panels drawn per module key. Arrays from before modules had keys count as the first module.
	 *
	 * @return array<string, int>
	 */
	public static function counts(?array $layout, string $firstKey = ''): array
	{
		$out = [];
		foreach ($layout['arrays'] ?? [] as $a) {
			$k = $a['mod'] ?? $firstKey;
			$out[$k] = ($out[$k] ?? 0) + count($a['cells'] ?? []);
		}
		return $out;
	}

	/**
	 * The layout is the master for module counts: once it has panels, each module line with a typed
	 * quantity gets the number drawn with it (0 when none). Lines of the same module share it: the
	 * first gets the count, the rest 0. Returns the lines.
	 */
	public static function applyCounts(?array $layout, array $lines): array
	{
		if (!self::panels($layout)) {
			return $lines;
		}
		$first = self::module($lines)['key'] ?? '';
		$counts = self::counts($layout, $first);
		$done = [];
		foreach ($lines as &$l) {
			if (($l['category'] ?? '') !== 'modules' || ($l['qty_rule'] ?? 'manual') !== 'manual') {
				continue;
			}
			$k = self::moduleKey($l);
			$l['qty'] = isset($done[$k]) ? 0 : ($counts[$k] ?? 0);
			$done[$k] = true;
		}
		unset($l);
		return $lines;
	}

	/** A saved layout, or null. */
	public static function decode(?string $json): ?array
	{
		$l = json_decode((string) $json, true);
		return is_array($l) && isset($l['arrays']) ? $l : null;
	}

	/** Total panels drawn. */
	public static function panels(?array $layout): int
	{
		$n = 0;
		foreach ($layout['arrays'] ?? [] as $a) {
			$n += count($a['cells'] ?? []);
		}
		return $n;
	}

	/**
	 * Read and check what the layout page sent. Throws with a readable message.
	 */
	public static function fromInput(string $json): array
	{
		$in = json_decode($json, true);
		if (!is_array($in)) {
			throw new \InvalidArgumentException('The layout could not be read. Reload the page and try again.');
		}
		$num = static function (mixed $v, float $low, float $high, string $what): float {
			if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
				throw new \InvalidArgumentException('The layout has a bad value (' . $what . ').');
			}
			$v = (float) $v;
			if (!is_finite($v) || $v < $low || $v > $high) {
				throw new \InvalidArgumentException('The layout has a value out of range (' . $what . ').');
			}
			return $v;
		};
		$out = [
			'v' => 1,
			'origin' => ['lat' => $num($in['origin']['lat'] ?? null, -85, 85, 'origin'), 'lon' => $num($in['origin']['lon'] ?? null, -180, 180, 'origin')],
			'module' => [
				'name' => mb_substr(trim((string) ($in['module']['name'] ?? '')), 0, 200),
				'watts' => $num($in['module']['watts'] ?? 0, 0, 2000, 'module watts'),
				'length' => $num($in['module']['length'] ?? null, 0.3, 4, 'module length'),
				'width' => $num($in['module']['width'] ?? null, 0.3, 4, 'module width'),
			],
			'gap' => ['panel' => $num($in['gap']['panel'] ?? 0, 0, 0.3, 'panel gap'), 'row' => $num($in['gap']['row'] ?? 0, 0, 3.05, 'row gap')],
			'view' => ['cx' => $num($in['view']['cx'] ?? 0, -5000, 5000, 'view'), 'cy' => $num($in['view']['cy'] ?? 0, -5000, 5000, 'view'),
				'mpp' => $num($in['view']['mpp'] ?? 0.05, 0.002, 20, 'view')],
			'maxz' => (int) $num($in['maxz'] ?? 21, 10, 23, 'imagery zoom'),
			'arrays' => [],
			'measures' => [],
			'roof' => null,
			'roof_shown' => !array_key_exists('roof_shown', $in) || !empty($in['roof_shown']),
			'pview' => self::pviewFrom($in['pview'] ?? null),
		];
		if ($out['module']['width'] > $out['module']['length']) {
			[$out['module']['length'], $out['module']['width']] = [$out['module']['width'], $out['module']['length']];
		}
		$out['modules'] = [];
		foreach (array_slice((array) ($in['modules'] ?? []), 0, 12, true) as $k => $m) {
			if (!is_array($m) || !preg_match('/^(c\d{1,10}|n[0-9a-f]{10})$/', (string) $k)) {
				continue;
			}
			$len = $num($m['length'] ?? null, 0.3, 4, 'module length');
			$wid = $num($m['width'] ?? null, 0.3, 4, 'module width');
			$out['modules'][(string) $k] = ['name' => mb_substr(trim((string) ($m['name'] ?? '')), 0, 200), 'watts' => $num($m['watts'] ?? 0, 0, 2000, 'module watts'),
				'length' => max($len, $wid), 'width' => min($len, $wid)];
		}
		$firstMod = (string) (array_key_first($out['modules']) ?? '');
		$keys = [];
		$names = [];
		$total = 0;
		foreach ((array) ($in['arrays'] ?? []) as $a) {
			if (!is_array($a)) {
				continue;
			}
			$key = (string) ($a['key'] ?? '');
			if (!preg_match('/^[a-z0-9]{1,16}$/', $key) || isset($keys[$key])) {
				throw new \InvalidArgumentException('The layout has two arrays with the same key. Reload the page and try again.');
			}
			$cells = [];
			foreach ((array) ($a['cells'] ?? []) as $c) {
				if (!is_array($c) || count($c) !== 2) {
					continue;
				}
				$r = (int) $num($c[0], -500, 500, 'panel row');
				$col = (int) $num($c[1], -500, 500, 'panel column');
				$cells[$r . ',' . $col] = [$r, $col];
			}
			if (!$cells) {
				continue;  // an array with no panels left is dropped
			}
			$keys[$key] = true;
			$total += count($cells);
			$name = trim(preg_replace('/\s+/', ' ', (string) ($a['name'] ?? '')) ?? '');
			if ($name !== '' && isset($names[mb_strtolower($name)])) {
				throw new \InvalidArgumentException('Two arrays are called "' . $name . '". Give each array its own name.');
			}
			$names[mb_strtolower($name)] = true;
			$out['arrays'][] = [
				'key' => $key,
				'name' => mb_substr($name !== '' ? $name : 'Array ' . (count($out['arrays']) + 1), 0, 60),
				'mount' => in_array($a['mount'] ?? '', ['ground', 'tracker'], true) ? $a['mount'] : 'roof',
				'pitch' => ($a['mount'] ?? '') === 'tracker' ? 0.0 : round($num($a['pitch'] ?? 0, 0, 75, 'pitch'), 2),
				'az' => round(fmod(fmod($num($a['az'] ?? 180, -720, 720, 'azimuth'), 360) + 360, 360), 2),
				'x' => round($num($a['x'] ?? 0, -5000, 5000, 'position'), 4),
				'y' => round($num($a['y'] ?? 0, -5000, 5000, 'position'), 4),
				'orient' => ($a['orient'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
				'mod' => isset($out['modules'][(string) ($a['mod'] ?? '')]) ? (string) $a['mod'] : $firstMod,
				'face' => isset($a['face']) && $a['face'] !== null && $a['face'] !== '' ? (int) $num($a['face'], 0, 199, 'roof face') : null,
				'material' => array_key_exists((string) ($a['material'] ?? ''), Projects::ROOF_MATERIALS) ? (string) $a['material'] : null,
				'shade' => isset($a['shade']) && $a['shade'] !== null && $a['shade'] !== '' ? round($num($a['shade'], 0, 90, 'shading (0 to 90%)'), 1) : null,
				'shade_note' => mb_substr(trim((string) ($a['shade_note'] ?? '')), 0, 200) ?: null,
				'cells' => array_values($cells),
			];
		}
		if (count($out['arrays']) > self::MAX_ARRAYS) {
			throw new \InvalidArgumentException('A layout can have up to ' . self::MAX_ARRAYS . ' arrays.');
		}
		if ($total > self::MAX_PANELS) {
			throw new \InvalidArgumentException('A layout can have up to ' . number_format(self::MAX_PANELS) . ' panels.');
		}
		foreach (array_slice((array) ($in['measures'] ?? []), 0, self::MAX_MEASURES) as $m) {
			if (!is_array($m)) {
				continue;
			}
			$out['measures'][] = [
				'x1' => round($num($m['x1'] ?? null, -5000, 5000, 'measurement'), 4), 'y1' => round($num($m['y1'] ?? null, -5000, 5000, 'measurement'), 4),
				'x2' => round($num($m['x2'] ?? null, -5000, 5000, 'measurement'), 4), 'y2' => round($num($m['y2'] ?? null, -5000, 5000, 'measurement'), 4),
				'pitch' => round($num($m['pitch'] ?? 0, 0, 75, 'measurement pitch'), 2), 'az' => round($num($m['az'] ?? 0, -720, 720, 'measurement azimuth'), 2),
				'src' => preg_match('/^(flat|typed|arr:[a-z0-9]{1,16}|face:\d{1,3})$/', (string) ($m['src'] ?? '')) ? (string) $m['src'] : 'flat',
			];
			if (($m['show'] ?? false) === true) {   // drawn on the proposal's aerial
				$out['measures'][array_key_last($out['measures'])]['show'] = true;
			}
		}
		$roof = $in['roof'] ?? null;
		if (is_array($roof) && is_array($roof['segments'] ?? null)) {
			$segs = [];
			foreach (array_slice($roof['segments'], 0, 200) as $s) {
				if (!is_array($s) || !is_array($s['box'] ?? null) || count($s['box']) !== 4) {
					continue;
				}
				$segs[] = ['pitch' => round($num($s['pitch'] ?? 0, 0, 90, 'roof pitch'), 2), 'azimuth' => round($num($s['azimuth'] ?? 0, 0, 360, 'roof azimuth'), 2),
					'area_m2' => round($num($s['area_m2'] ?? 0, 0, 1e6, 'roof area'), 1),
					'box' => array_map(static fn ($v) => round($num($v, -180, 180, 'roof box'), 7), array_values($s['box'])),
					'hull' => is_array($s['hull'] ?? null) ? array_map(static fn ($pt) => !is_array($pt) ? throw new \InvalidArgumentException('The layout has a bad roof outline.') : [round($num($pt[0] ?? null, -85, 85, 'roof outline'), 7),
						round($num($pt[1] ?? null, -180, 180, 'roof outline'), 7)], array_slice(array_values($s['hull']), 0, 80)) : null,
					'bld' => preg_match('/^-?\d{1,3}\.\d{1,7},-?\d{1,3}\.\d{1,7}$/', (string) ($s['bld'] ?? '')) ? (string) $s['bld'] : null];
			}
			$blds = [];
			foreach (array_slice((array) ($roof['buildings'] ?? []), 0, 20) as $b) {
				if (is_array($b)) {
					$blds[] = ['lat' => $num($b['lat'] ?? null, -85, 85, 'building point'), 'lon' => $num($b['lon'] ?? null, -180, 180, 'building point'),
						'quality' => mb_substr((string) ($b['quality'] ?? ''), 0, 20), 'date' => mb_substr((string) ($b['date'] ?? ''), 0, 10)];
				}
			}
			$out['roof'] = ['quality' => mb_substr((string) ($roof['quality'] ?? ''), 0, 20), 'date' => mb_substr((string) ($roof['date'] ?? ''), 0, 10),
				'lat' => $num($roof['lat'] ?? 0, -85, 85, 'roof point'), 'lon' => $num($roof['lon'] ?? 0, -180, 180, 'roof point'), 'segments' => $segs] + ($blds ? ['buildings' => $blds] : []);
		}
		return $out;
	}

	/**
	 * One array's grid on the plan: panel size along the eave (u) and up the slope as seen from
	 * above (v), and the step from one spot to the next.
	 *
	 * @return array{u:float, v:float, su:float, sv:float}
	 */
	public static function grid(array $layout, array $a): array
	{
		$m = self::moduleOf($layout, $a);
		[$along, $slope] = $a['orient'] === 'landscape' ? [$m['length'], $m['width']] : [$m['width'], $m['length']];
		$cos = cos(deg2rad((float) $a['pitch']));
		$v = $slope * $cos;
		return ['u' => $along, 'v' => $v, 'su' => $along + $layout['gap']['panel'], 'sv' => $v + $layout['gap']['row'] * $cos];
	}

	/** Local (array) point to map meters. */
	public static function toMap(array $a, float $lx, float $ly): array
	{
		$t = deg2rad((float) $a['az']);
		return [$a['x'] + $lx * cos($t) - $ly * sin($t), $a['y'] + $lx * sin($t) + $ly * cos($t)];
	}

	/** The four corners of every panel, in map meters: [array index => list of 4 [x, y]]. */
	public static function outlines(array $layout): array
	{
		$out = [];
		foreach ($layout['arrays'] as $i => $a) {
			$g = self::grid($layout, $a);
			foreach ($a['cells'] as [$r, $c]) {
				$x0 = $c * $g['su'];
				$y0 = $r * $g['sv'];
				$out[$i][] = [self::toMap($a, $x0, $y0), self::toMap($a, $x0 + $g['u'], $y0), self::toMap($a, $x0 + $g['u'], $y0 + $g['v']), self::toMap($a, $x0, $y0 + $g['v'])];
			}
		}
		return $out;
	}

	/**
	 * An array's perimeter in meters, as a tape measure around its panels would read: the gaps
	 * between neighboring panels count, up-slope lengths are true (not shortened), and holes left by
	 * removed panels inside it don't add to it. (layout.js has the same.)
	 */
	public static function perimeter(array $layout, array $a): float
	{
		$m = self::moduleOf($layout, $a);
		[$u, $v] = ($a['orient'] ?? '') === 'landscape' ? [$m['length'], $m['width']] : [$m['width'], $m['length']];
		$su = $u + $layout['gap']['panel'];
		$sv = $v + $layout['gap']['row'];
		$has = [];
		foreach ($a['cells'] as [$r, $c]) {
			$has[$r . ',' . $c] = true;
		}
		$on = static fn (int $r, int $c): bool => isset($has[$r . ',' . $c]);
		$rects = [];
		foreach ($a['cells'] as [$r, $c]) {
			$rects[] = [$c * $su, $c * $su + $u, $r * $sv, $r * $sv + $v];
			if ($on($r, $c + 1)) {
				$rects[] = [$c * $su + $u, ($c + 1) * $su, $r * $sv, $r * $sv + $v];
			}
			if ($on($r + 1, $c)) {
				$rects[] = [$c * $su, $c * $su + $u, $r * $sv + $v, ($r + 1) * $sv];
			}
			if ($on($r, $c + 1) && $on($r + 1, $c) && $on($r + 1, $c + 1)) {
				$rects[] = [$c * $su + $u, ($c + 1) * $su, $r * $sv + $v, ($r + 1) * $sv];
			}
		}
		if (!$rects) {
			return 0.0;
		}
		$key = static fn (float $x): string => sprintf('%.6F', $x);
		$axis = static function (array $vals) use ($key): array {
			$u = [];
			foreach ($vals as $x) {
				$u[$key($x)] = (float) $key($x);
			}
			sort($u);
			return array_values($u);
		};
		$xs = $axis(array_merge(array_column($rects, 0), array_column($rects, 1)));
		$ys = $axis(array_merge(array_column($rects, 2), array_column($rects, 3)));
		$xi = array_flip(array_map($key, $xs));
		$yi = array_flip(array_map($key, $ys));
		$w = count($xs) + 1;
		$h = count($ys) + 1;
		$fill = array_fill(0, $w * $h, false);
		foreach ($rects as [$x1, $x2, $y1, $y2]) {
			for ($i = $xi[$key($x1)] + 1; $i < $xi[$key($x2)] + 1; $i++) {
				for ($j = $yi[$key($y1)] + 1; $j < $yi[$key($y2)] + 1; $j++) {
					$fill[$j * $w + $i] = true;
				}
			}
		}
		$out = array_fill(0, $w * $h, false);
		$out[0] = true;
		$stack = [0];
		while ($stack) {
			$k = array_pop($stack);
			$i = $k % $w;
			$j = intdiv($k, $w);
			foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$di, $dj]) {
				$ni = $i + $di;
				$nj = $j + $dj;
				$nk = $nj * $w + $ni;
				if ($ni >= 0 && $nj >= 0 && $ni < $w && $nj < $h && !$out[$nk] && !$fill[$nk]) {
					$out[$nk] = true;
					$stack[] = $nk;
				}
			}
		}
		$cw = static fn (int $i): float => $i <= 0 || $i >= $w - 1 ? 0.0 : $xs[$i] - $xs[$i - 1];
		$ch = static fn (int $j): float => $j <= 0 || $j >= $h - 1 ? 0.0 : $ys[$j] - $ys[$j - 1];
		$len = 0.0;
		for ($j = 0; $j < $h; $j++) {
			for ($i = 0; $i < $w; $i++) {
				if (!$fill[$j * $w + $i]) {
					continue;
				}
				$len += ($i + 1 < $w && $out[$j * $w + $i + 1] ? $ch($j) : 0) + ($i > 0 && $out[$j * $w + $i - 1] ? $ch($j) : 0)
					+ ($j + 1 < $h && $out[($j + 1) * $w + $i] ? $cw($i) : 0) + ($j > 0 && $out[($j - 1) * $w + $i] ? $cw($i) : 0);
			}
		}
		return $len;
	}

	/**
	 * Panel area and perimeter of each build sheet array (by index) and their totals, in square
	 * meters and meters. Area is the panels' own surface (count x module length x width, true size
	 * on a slope); drawn arrays use their module, typed ones the first module. Perimeter needs the
	 * drawing, so typed arrays have none.
	 *
	 * @return array{rows: array<int, array{area: ?float, perimeter: ?float}>, area: ?float, perimeter: ?float}
	 */
	public static function arrayStats(?array $layout, array $arrays, array $lines): array
	{
		$drawn = [];
		foreach ($layout['arrays'] ?? [] as $la) {
			$drawn[$la['key']] = $la;
		}
		$first = self::module($lines);
		$rows = [];
		foreach (array_values($arrays) as $i => $a) {
			$la = $drawn[$a['layout_key'] ?? ''] ?? null;
			if ($la) {
				$m = self::moduleOf($layout, $la);
				$rows[$i] = ['area' => $m['length'] && $m['width'] ? count($la['cells']) * $m['length'] * $m['width'] : null,
					'perimeter' => self::perimeter($layout, $la)];
			} else {
				$rows[$i] = ['area' => $first && $first['length'] && $first['width'] ? (int) ($a['panels'] ?? 0) * $first['length'] * $first['width'] : null,
					'perimeter' => null];
			}
		}
		$sum = static function (string $k) use ($rows): ?float {
			$v = array_column($rows, $k);
			return $v && !in_array(null, $v, true) ? (float) array_sum($v) : null;
		};
		return ['rows' => $rows, 'area' => $sum('area'), 'perimeter' => $sum('perimeter')];
	}

	/** Square feet, whole. */
	public static function sqft(float $m2): string
	{
		return number_format($m2 * 10.7639104) . ' sq ft';
	}

	/** Feet and inches, rounded down to the inch, like the layout page's measurements ("0' 7"" reads 7"). */
	public static function feetDown(float $m): string
	{
		$in = (int) floor($m / 0.0254 + 1e-6);
		return $in >= 12 ? intdiv($in, 12) . "' " . ($in % 12) . '"' : $in . '"';
	}

	/**
	 * A measurement's length in meters along the roof, as layout.js trueLength() reads it: the
	 * saved slope (pitch and the direction it faces) stretches the part of the line running up it.
	 * A typed pitch slopes up the line itself.
	 */
	public static function measureLength(array $m): float
	{
		$dx = (float) $m['x2'] - (float) $m['x1'];
		$dy = (float) $m['y2'] - (float) $m['y1'];
		$src = (string) ($m['src'] ?? 'flat');
		$pitch = $src === 'flat' ? 0.0 : (float) ($m['pitch'] ?? 0);
		if ($pitch <= 0) {
			return hypot($dx, $dy);
		}
		$t = $src === 'typed' ? atan2(-$dx, $dy) : deg2rad((float) ($m['az'] ?? 0));
		$across = $dx * cos($t) + $dy * sin($t);
		$up = -$dx * sin($t) + $dy * cos($t);
		return hypot($across, $up / cos(deg2rad($pitch)));
	}

	/**
	 * Measurements ticked "Show on proposal".
	 *
	 * @return list<array{0:float, 1:float, 2:float, 3:float, 4:string}>  x1, y1, x2, y2 in map meters, label
	 */
	public static function shownMeasures(array $layout): array
	{
		$out = [];
		foreach ((array) ($layout['measures'] ?? []) as $m) {
			if (is_array($m) && !empty($m['show']) && isset($m['x1'], $m['y1'], $m['x2'], $m['y2'])) {
				$out[] = [(float) $m['x1'], (float) $m['y1'], (float) $m['x2'], (float) $m['y2'], self::feetDown(self::measureLength($m))];
			}
		}
		return $out;
	}

	/** Feet and inches, rounded up to the inch (material lengths). */
	public static function feetUp(float $m): string
	{
		$in = (int) ceil($m / 0.0254 - 1e-6);
		return intdiv($in, 12) . "' " . ($in % 12) . '"';
	}

	/**
	 * A tracker's sweep: the circle its panel table can reach as it turns, centered on the table
	 * and out to its farthest panel corner. [x, y, radius] in map meters.
	 *
	 * @return list<array{0:float, 1:float, 2:float}>
	 */
	public static function sweeps(array $layout): array
	{
		$out = [];
		foreach (self::outlines($layout) as $i => $panels) {
			if (($layout['arrays'][$i]['mount'] ?? '') !== 'tracker') {
				continue;
			}
			$pts = array_merge(...$panels);
			$cx = (min(array_column($pts, 0)) + max(array_column($pts, 0))) / 2;
			$cy = (min(array_column($pts, 1)) + max(array_column($pts, 1))) / 2;
			$r = 0.0;
			foreach ($pts as [$x, $y]) {
				$r = max($r, hypot($x - $cx, $y - $cy));
			}
			$out[] = [$cx, $cy, $r];
		}
		return $out;
	}

	/**
	 * Bring the build sheet in line with the layout, which is the master once it has panels: drawn
	 * arrays (name, mount, direction, tilt, panels, roof material and shading) are updated, added and
	 * removed, typed arrays are removed, and module lines with a typed quantity get the count drawn
	 * with that module. Returns what changed, for the log.
	 *
	 * @return list<string>
	 */
	public static function syncArrays(array $d, array $layout): array
	{
		$id = (int) $d['id'];
		$have = [];
		foreach (Db::all('SELECT * FROM presales_design_arrays WHERE design_id = ? AND layout_key IS NOT NULL', [$id]) as $r) {
			$have[$r['layout_key']] = $r;
		}
		$changes = [];
		$sort = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) FROM presales_design_arrays WHERE design_id = ?', [$id]);
		$seen = [];
		foreach ($layout['arrays'] as $a) {
			$seen[$a['key']] = true;
			$fixed = $a['mount'] !== 'tracker';  // trackers have no fixed tilt or azimuth
			$row = ['name' => $a['name'], 'mount' => $a['mount'], 'azimuth' => $fixed ? ((int) round($a['az'])) % 360 : null,
				'tilt' => $fixed ? round((float) $a['pitch'], 1) : null, 'panels' => count($a['cells']),
				'material' => $a['mount'] === 'roof' ? ($a['material'] ?? null) : null, 'shading_pct' => $a['shade'] ?? null, 'shading_note' => $a['shade_note'] ?? null];
			$old = $have[$a['key']] ?? null;
			if (!$old) {
				Db::insert('presales_design_arrays', $row + ['design_id' => $id, 'layout_key' => $a['key'], 'sort_order' => ++$sort]);
				$changes[] = 'added ' . $a['name'] . ' (' . $row['panels'] . ' panels)';
				continue;
			}
			$diff = [];
			if ((string) $old['name'] !== $row['name']) {
				$diff[] = 'renamed from ' . $old['name'];
			}
			if ((int) $old['panels'] !== $row['panels']) {
				$diff[] = (int) $old['panels'] . ' to ' . $row['panels'] . ' panels';
			}
			$oldAz = $old['azimuth'] === null ? null : (int) $old['azimuth'];
			$tiltMoved = ($old['tilt'] === null) !== ($row['tilt'] === null) || abs((float) $old['tilt'] - (float) $row['tilt']) > 0.05;
			if ($oldAz !== $row['azimuth'] || $tiltMoved || $old['mount'] !== $row['mount']) {
				$diff[] = 'tilt or direction changed';
			}
			if ((string) $old['shading_pct'] !== (string) $row['shading_pct'] && !($old['shading_pct'] !== null && $row['shading_pct'] !== null && abs((float) $old['shading_pct'] - $row['shading_pct']) < 0.05)) {
				$diff[] = 'shading ' . ($old['shading_pct'] === null ? 'none' : (float) $old['shading_pct'] . '%') . ' to ' . ($row['shading_pct'] === null ? 'none' : $row['shading_pct'] . '%');
			}
			$quiet = (string) $old['material'] !== (string) $row['material'] || (string) $old['shading_note'] !== (string) $row['shading_note'];
			if ($diff || $quiet) {
				Db::update('presales_design_arrays', (int) $old['id'], $row);
			}
			if ($diff) {
				$changes[] = $a['name'] . ' ' . implode(', ', $diff);
			}
		}
		foreach ($have as $key => $old) {
			if (!isset($seen[$key])) {
				Db::run('DELETE FROM presales_design_arrays WHERE id = ?', [(int) $old['id']]);
				$changes[] = 'removed ' . $old['name'];
			}
		}
		if (self::panels($layout)) {
			// The drawing is the master: typed arrays go, and module quantities follow it
			foreach (Db::all('SELECT id, name FROM presales_design_arrays WHERE design_id = ? AND layout_key IS NULL', [$id]) as $t) {
				Db::run('DELETE FROM presales_design_arrays WHERE id = ?', [(int) $t['id']]);
				$changes[] = 'removed typed array ' . ($t['name'] ?: '(no name)');
			}
			$lines = Db::all('SELECT * FROM presales_design_lines WHERE design_id = ? ORDER BY sort_order, id', [$id]);
			foreach (self::applyCounts($layout, $lines) as $i => $l) {
				if ($l['category'] === 'modules' && (float) $l['qty'] !== (float) $lines[$i]['qty']) {
					Db::update('presales_design_lines', (int) $l['id'], ['qty' => $l['qty']]);
					$changes[] = $l['name'] . ' ' . (int) round((float) $lines[$i]['qty']) . ' to ' . (int) $l['qty'];
				}
			}
		}
		return $changes;
	}

	/** Meters per pixel of a zoom level's tiles at a latitude. */
	public static function resolution(int $z, float $lat): float
	{
		return self::EARTH * cos(deg2rad($lat)) / (2 ** $z);
	}

	/** Web Mercator pixel position of a point at a zoom level. */
	public static function world(float $lat, float $lon, int $z): array
	{
		$n = 256 * (2 ** $z);
		$s = sin(deg2rad(max(-85.05, min(85.05, $lat))));
		return [($lon + 180) / 360 * $n, (0.5 - log((1 + $s) / (1 - $s)) / (4 * M_PI)) * $n];
	}

	/** Map meters back to latitude and longitude. */
	public static function latLon(array $origin, float $x, float $y): array
	{
		$z = 20;
		[$wx0, $wy0] = self::world($origin['lat'], $origin['lon'], $z);
		$k = self::resolution($z, $origin['lat']);
		$n = 256 * (2 ** $z);
		$wx = $wx0 + $x / $k;
		$wy = $wy0 + $y / $k;
		return [rad2deg(atan(sinh(M_PI * (1 - 2 * $wy / $n)))), $wx / $n * 360 - 180];
	}

	/**
	 * The proposal view saved on the layout page: the center and width (map meters) of what the
	 * proposal's aerial shows. Null means automatic framing on the arrays. Throws when it can't be read.
	 *
	 * @return array{cx:float, cy:float, w:float}|null
	 */
	public static function pviewFrom(mixed $v): ?array
	{
		if ($v === null || $v === '' || $v === false) {
			return null;
		}
		$ok = static fn (mixed $n, float $lo, float $hi): bool => (is_int($n) || is_float($n) || (is_string($n) && is_numeric($n))) && is_finite((float) $n) && (float) $n >= $lo && (float) $n <= $hi;
		if (!is_array($v) || !$ok($v['cx'] ?? null, -5000, 5000) || !$ok($v['cy'] ?? null, -5000, 5000) || !$ok($v['w'] ?? null, 5, 2000)) {
			throw new \InvalidArgumentException('The proposal view could not be read (it can show 5 m to 2 km across).');
		}
		return ['cx' => round((float) $v['cx'], 3), 'cy' => round((float) $v['cy'], 3), 'w' => round((float) $v['w'], 3)];
	}

	/**
	 * The box the proposal's aerial shows, in map meters [x, y, width, height]: the saved proposal
	 * view, else the arrays (tracker sweep circles and measurements shown on the proposal too) with
	 * a margin, widened to the aspect.
	 * Null when there are no panels. (layout.js has the same automatic framing.)
	 */
	public static function proposalBox(array $layout, float $aspect = self::PROPOSAL_ASPECT): ?array
	{
		$outlines = self::outlines($layout);
		if (!$outlines) {
			return null;
		}
		$pv = $layout['pview'] ?? null;
		if (is_array($pv) && isset($pv['cx'], $pv['cy'], $pv['w'])) {
			$w = (float) $pv['w'];
			$h = $w / $aspect;
			return [(float) $pv['cx'] - $w / 2, (float) $pv['cy'] - $h / 2, $w, $h];
		}
		$xs = $ys = [];
		foreach ($outlines as $panels) {
			foreach ($panels as $p) {
				foreach ($p as [$x, $y]) {
					$xs[] = $x;
					$ys[] = $y;
				}
			}
		}
		foreach (self::sweeps($layout) as [$cx, $cy, $r]) {
			array_push($xs, $cx - $r, $cx + $r);
			array_push($ys, $cy - $r, $cy + $r);
		}
		foreach (self::shownMeasures($layout) as [$x1, $y1, $x2, $y2]) {
			array_push($xs, $x1, $x2);
			array_push($ys, $y1, $y2);
		}
		$pad = max(4.0, 0.15 * max(max($xs) - min($xs), max($ys) - min($ys)));
		$x0 = min($xs) - $pad;
		$y0 = min($ys) - $pad;
		$w = max($xs) - min($xs) + 2 * $pad;
		$h = max($ys) - min($ys) + 2 * $pad;
		if ($w / $h < $aspect) {
			$x0 -= ($h * $aspect - $w) / 2;
			$w = $h * $aspect;
		} else {
			$y0 -= ($w / $aspect - $h) / 2;
			$h = $w / $aspect;
		}
		return [$x0, $y0, $w, $h];
	}

	/** Panels that fall outside the saved proposal view (0 with automatic framing). */
	public static function panelsOutsideView(array $layout): int
	{
		$box = !empty($layout['pview']) ? self::proposalBox($layout) : null;
		if (!$box) {
			return 0;
		}
		[$x0, $y0, $w, $h] = $box;
		$n = 0;
		foreach (self::outlines($layout) as $panels) {
			foreach ($panels as $p) {
				foreach ($p as [$x, $y]) {
					if ($x < $x0 || $x > $x0 + $w || $y < $y0 || $y > $y0 + $h) {
						$n++;
						break;
					}
				}
			}
		}
		return $n;
	}

	/** Measurements shown on the proposal with an end outside the saved proposal view (0 with automatic framing). */
	public static function measuresOutsideView(array $layout): int
	{
		$box = !empty($layout['pview']) ? self::proposalBox($layout) : null;
		if (!$box) {
			return 0;
		}
		[$x0, $y0, $w, $h] = $box;
		$out = static fn (float $x, float $y): bool => $x < $x0 || $x > $x0 + $w || $y < $y0 || $y > $y0 + $h;
		return count(array_filter(self::shownMeasures($layout), static fn ($m) => $out($m[0], $m[1]) || $out($m[2], $m[3])));
	}

	/**
	 * The layout for the proposal: satellite tiles under the panels, framed by proposalBox(), as an SVG
	 * (images inline, so the PDF needs nothing else). $tile fetches one tile's bytes and type, or
	 * returns null (then the photo is left gray). Returns the SVG and the tile zoom it used.
	 *
	 * @param callable(int,int,int):?array{0:string,1:string} $tile  bytes, content type
	 * @return array{svg:string, zoom:int, box:array}|null  null when there are no panels; box is x, y, width, height in map meters
	 */
	public static function drawing(array $layout, float $aspect, callable $tile, int $pixelsAcross = 1400): ?array
	{
		$box = self::proposalBox($layout, $aspect);
		if (!$box) {
			return null;
		}
		[$x0, $y0, $w, $h] = $box;
		$outlines = self::outlines($layout);
		$sweeps = self::sweeps($layout);
		$lat = $layout['origin']['lat'];
		$z = (int) floor(log(self::EARTH * cos(deg2rad($lat)) / ($w / $pixelsAcross), 2));
		$z = max(15, min((int) ($layout['maxz'] ?? 21), 21, $z));
		$k = self::resolution($z, $lat);
		[$wx0, $wy0] = self::world($lat, $layout['origin']['lon'], $z);
		$tx1 = (int) floor(($wx0 + $x0 / $k) / 256);
		$tx2 = (int) floor(($wx0 + ($x0 + $w) / $k) / 256);
		$ty1 = (int) floor(($wy0 + $y0 / $k) / 256);
		$ty2 = (int) floor(($wy0 + ($y0 + $h) / $k) / 256);
		$f = static fn (float $v): string => rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" class="layout-drawing" viewBox="' . $f($x0) . ' ' . $f($y0) . ' ' . $f($w) . ' ' . $f($h) . '" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Panel layout on the satellite view">';
		$svg .= '<rect x="' . $f($x0) . '" y="' . $f($y0) . '" width="' . $f($w) . '" height="' . $f($h) . '" fill="#cfd6d2"/>';
		if (($tx2 - $tx1 + 1) * ($ty2 - $ty1 + 1) <= 48) {
			for ($tx = $tx1; $tx <= $tx2; $tx++) {
				for ($ty = $ty1; $ty <= $ty2; $ty++) {
					$img = $tile($z, $tx, $ty);
					if (!$img) {
						continue;
					}
					// A hair of overlap hides seams between tiles
					$svg .= '<image x="' . $f(($tx * 256 - $wx0) * $k) . '" y="' . $f(($ty * 256 - $wy0) * $k) . '" width="' . $f(256 * $k + 0.02) . '" height="' . $f(256 * $k + 0.02)
						. '" preserveAspectRatio="none" href="data:' . e($img[1]) . ';base64,' . base64_encode($img[0]) . '"/>';
				}
			}
		}
		$stroke = $f(max(0.03, $w / 900));
		foreach ($outlines as $panels) {
			foreach ($panels as $p) {
				$svg .= '<polygon points="' . implode(' ', array_map(static fn ($pt) => $f($pt[0]) . ',' . $f($pt[1]), $p)) . '" fill="#14243a" fill-opacity="0.92" stroke="#dfe7ee" stroke-width="' . $stroke . '"/>';
			}
		}
		foreach ($sweeps as [$cx, $cy, $r]) {
			$svg .= '<circle cx="' . $f($cx) . '" cy="' . $f($cy) . '" r="' . $f($r) . '" fill="none" stroke="#d62d20" stroke-width="' . $f(max(0.06, $w / 500)) . '" stroke-dasharray="' . $f(max(0.3, $w / 120)) . ' ' . $f(max(0.2, $w / 200)) . '"/>';
		}
		$u = $w / 100;
		// Measurements ticked "Show on proposal": a yellow line with a dark edge, end ticks, and the
		// length in a white box at the middle (rounded down to the inch, as on the layout page)
		$fs = 1.6 * $u;
		foreach (self::shownMeasures($layout) as [$x1, $y1, $x2, $y2, $label]) {
			$len = hypot($x2 - $x1, $y2 - $y1);
			if ($len < 0.01) {
				continue;
			}
			[$nx, $ny] = [-($y2 - $y1) / $len * 0.9 * $u, ($x2 - $x1) / $len * 0.9 * $u];   // tick half-length, across the line
			$path = 'M' . $f($x1) . ' ' . $f($y1) . ' L' . $f($x2) . ' ' . $f($y2)
				. ' M' . $f($x1 + $nx) . ' ' . $f($y1 + $ny) . ' L' . $f($x1 - $nx) . ' ' . $f($y1 - $ny)
				. ' M' . $f($x2 + $nx) . ' ' . $f($y2 + $ny) . ' L' . $f($x2 - $nx) . ' ' . $f($y2 - $ny);
			$svg .= '<g><path d="' . $path . '" fill="none" stroke="#1c2830" stroke-width="' . $f(0.5 * $u) . '" stroke-linecap="round"/>'
				. '<path d="' . $path . '" fill="none" stroke="#ffd400" stroke-width="' . $f(0.22 * $u) . '" stroke-linecap="round"/>';
			$mx = ($x1 + $x2) / 2;
			$my = ($y1 + $y2) / 2;
			$bw = mb_strlen($label) * 0.56 * $fs + 1.2 * $u;
			$svg .= '<rect x="' . $f($mx - $bw / 2) . '" y="' . $f($my - 1.4 * $u) . '" width="' . $f($bw) . '" height="' . $f(2.6 * $u) . '" rx="' . $f(0.5 * $u)
				. '" fill="#ffffff" fill-opacity="0.9" stroke="#1c2830" stroke-width="' . $f(0.08 * $u) . '"/>'
				. '<text x="' . $f($mx) . '" y="' . $f($my + 0.55 * $u) . '" font-size="' . $f($fs) . '" font-family="Liberation Sans, Arial, sans-serif" font-weight="bold" text-anchor="middle" fill="#1c2830">' . e($label) . '</text></g>';
		}
		// North arrow and a scale bar, sized to the frame
		$nx = $x0 + $w - 4 * $u;
		$ny = $y0 + 3 * $u;
		$svg .= '<g><circle cx="' . $f($nx) . '" cy="' . $f($ny + 2.2 * $u) . '" r="' . $f(2.6 * $u) . '" fill="#ffffff" fill-opacity="0.85"/>'
			. '<path d="M' . $f($nx) . ' ' . $f($ny) . ' L' . $f($nx + 1.2 * $u) . ' ' . $f($ny + 3.4 * $u) . ' L' . $f($nx) . ' ' . $f($ny + 2.6 * $u) . ' L' . $f($nx - 1.2 * $u) . ' ' . $f($ny + 3.4 * $u) . ' Z" fill="#025586"/>'
			. '<text x="' . $f($nx) . '" y="' . $f($ny + 4.6 * $u) . '" font-size="' . $f(1.7 * $u) . '" font-family="Liberation Sans, Arial, sans-serif" font-weight="bold" text-anchor="middle" fill="#025586">N</text></g>';
		$feet = [10, 20, 25, 50, 100, 200, 250, 500];
		$ft = $feet[0];
		foreach ($feet as $c) {
			if ($c * 0.3048 <= $w * 0.18) {
				$ft = $c;
			}
		}
		$len = $ft * 0.3048;
		$sx = $x0 + $w - 3 * $u - $len;   // scale bar bottom right; Google's logo bottom left
		$sy = $y0 + $h - 3 * $u;
		$svg .= '<g><rect x="' . $f($sx - $u) . '" y="' . $f($sy - 3.2 * $u) . '" width="' . $f($len + 2 * $u) . '" height="' . $f(4.4 * $u) . '" rx="' . $f(0.6 * $u) . '" fill="#ffffff" fill-opacity="0.85"/>'
			. '<rect x="' . $f($sx) . '" y="' . $f($sy) . '" width="' . $f($len) . '" height="' . $f(0.5 * $u) . '" fill="#1c2830"/>'
			. '<text x="' . $f($sx + $len / 2) . '" y="' . $f($sy - 0.9 * $u) . '" font-size="' . $f(1.6 * $u) . '" font-family="Liberation Sans, Arial, sans-serif" text-anchor="middle" fill="#1c2830">' . $ft . ' ft</text></g>';
		// Google Maps logo (required attribution): 18 px tall on the printed frame, 10 px clear of the edges
		$logo = APP_ROOT . '/public/assets/img/google-maps-logo-4x.png';
		if (is_file($logo)) {
			$px = $w / (7.3 * 96);
			$lh = 18 * $px;
			$svg .= '<image x="' . $f($x0 + 10 * $px) . '" y="' . $f($y0 + $h - 6 * $px - $lh) . '" width="' . $f($lh * 105 / 22) . '" height="' . $f($lh)
				. '" href="data:image/png;base64,' . base64_encode((string) file_get_contents($logo)) . '"><title>Google Maps</title></image>';
		}
		$svg .= '</svg>';
		return ['svg' => $svg, 'zoom' => $z, 'box' => [$x0, $y0, $w, $h]];
	}
}
