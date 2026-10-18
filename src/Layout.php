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

	/**
	 * The module the layout draws: the build sheet's first module line, with its size in meters from
	 * the line, its spec values, the catalog item or SAM's library. Size is null when none of them has it.
	 *
	 * @return array{name:string, watts:float, length:?float, width:?float, lines:int}|null
	 */
	public static function module(array $lines): ?array
	{
		$mods = array_values(array_filter($lines, static fn ($l) => ($l['category'] ?? '') === 'modules'));
		if (!$mods) {
			return null;
		}
		$m = $mods[0];
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
		return ['name' => (string) $m['name'], 'watts' => (float) ($m['watts'] ?? 0),
			'length' => $ok ? max($len, $wid) / 1000 : null, 'width' => $ok ? min($len, $wid) / 1000 : null, 'lines' => count($mods)];
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
		];
		if ($out['module']['width'] > $out['module']['length']) {
			[$out['module']['length'], $out['module']['width']] = [$out['module']['width'], $out['module']['length']];
		}
		$keys = [];
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
			$out['arrays'][] = [
				'key' => $key,
				'name' => mb_substr($name !== '' ? $name : 'Array ' . (count($out['arrays']) + 1), 0, 60),
				'mount' => in_array($a['mount'] ?? '', ['ground', 'tracker'], true) ? $a['mount'] : 'roof',
				'pitch' => ($a['mount'] ?? '') === 'tracker' ? 0.0 : round($num($a['pitch'] ?? 0, 0, 75, 'pitch'), 2),
				'az' => round(fmod(fmod($num($a['az'] ?? 180, -720, 720, 'azimuth'), 360) + 360, 360), 2),
				'x' => round($num($a['x'] ?? 0, -5000, 5000, 'position'), 4),
				'y' => round($num($a['y'] ?? 0, -5000, 5000, 'position'), 4),
				'orient' => ($a['orient'] ?? '') === 'landscape' ? 'landscape' : 'portrait',
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
				'src' => preg_match('/^(flat|arr:[a-z0-9]{1,16}|face:\d{1,3})$/', (string) ($m['src'] ?? '')) ? (string) $m['src'] : 'flat',
			];
		}
		$roof = $in['roof'] ?? null;
		if (is_array($roof) && is_array($roof['segments'] ?? null)) {
			$segs = [];
			foreach (array_slice($roof['segments'], 0, 80) as $s) {
				if (!is_array($s) || !is_array($s['box'] ?? null) || count($s['box']) !== 4) {
					continue;
				}
				$segs[] = ['pitch' => round($num($s['pitch'] ?? 0, 0, 90, 'roof pitch'), 2), 'azimuth' => round($num($s['azimuth'] ?? 0, 0, 360, 'roof azimuth'), 2),
					'area_m2' => round($num($s['area_m2'] ?? 0, 0, 1e6, 'roof area'), 1),
					'box' => array_map(static fn ($v) => round($num($v, -180, 180, 'roof box'), 7), array_values($s['box'])),
					'hull' => is_array($s['hull'] ?? null) ? array_map(static fn ($pt) => !is_array($pt) ? throw new \InvalidArgumentException('The layout has a bad roof outline.') : [round($num($pt[0] ?? null, -85, 85, 'roof outline'), 7),
						round($num($pt[1] ?? null, -180, 180, 'roof outline'), 7)], array_slice(array_values($s['hull']), 0, 80)) : null];
			}
			$out['roof'] = ['quality' => mb_substr((string) ($roof['quality'] ?? ''), 0, 20), 'date' => mb_substr((string) ($roof['date'] ?? ''), 0, 10),
				'lat' => $num($roof['lat'] ?? 0, -85, 85, 'roof point'), 'lon' => $num($roof['lon'] ?? 0, -180, 180, 'roof point'), 'segments' => $segs];
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
		$m = $layout['module'];
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
	 * Bring the build sheet's drawn arrays in line with the layout: update, add and remove rows that
	 * carry a layout key (typed rows stay). Material and shading on existing rows are kept. When the
	 * build sheet has one module line with a typed quantity, it becomes the panel count. Returns what
	 * changed, for the log.
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
				'tilt' => $fixed ? round((float) $a['pitch'], 1) : null, 'panels' => count($a['cells'])];
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
			if ($diff) {
				Db::update('presales_design_arrays', (int) $old['id'], $row);
				$changes[] = $a['name'] . ' ' . implode(', ', $diff);
			}
		}
		foreach ($have as $key => $old) {
			if (!isset($seen[$key])) {
				Db::run('DELETE FROM presales_design_arrays WHERE id = ?', [(int) $old['id']]);
				$changes[] = 'removed ' . $old['name'];
			}
		}
		// The module quantity follows the drawing when there is one module line with a typed quantity
		$mods = Db::all("SELECT id, qty, qty_rule FROM presales_design_lines WHERE design_id = ? AND category = 'modules'", [$id]);
		$total = self::panels($layout);
		if (count($mods) === 1 && $mods[0]['qty_rule'] === 'manual' && $total > 0 && (int) round((float) $mods[0]['qty']) !== $total) {
			Db::update('presales_design_lines', (int) $mods[0]['id'], ['qty' => $total]);
			$changes[] = 'modules ' . (int) round((float) $mods[0]['qty']) . ' to ' . $total;
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
	 * The layout for the proposal: satellite tiles under the panels, framed on the arrays, as an SVG
	 * (images inline, so the PDF needs nothing else). $tile fetches one tile's bytes and type, or
	 * returns null (then the photo is left gray). Returns the SVG and the tile zoom it used.
	 *
	 * @param callable(int,int,int):?array{0:string,1:string} $tile  bytes, content type
	 * @return array{svg:string, zoom:int, box:array}|null  null when there are no panels; box is x, y, width, height in map meters
	 */
	public static function drawing(array $layout, float $aspect, callable $tile, int $pixelsAcross = 1400): ?array
	{
		$outlines = self::outlines($layout);
		if (!$outlines) {
			return null;
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
		$sweeps = self::sweeps($layout);
		foreach ($sweeps as [$cx, $cy, $r]) {
			array_push($xs, $cx - $r, $cx + $r);
			array_push($ys, $cy - $r, $cy + $r);
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
		// North arrow and a scale bar, sized to the frame
		$u = $w / 100;
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
		$sx = $x0 + 3 * $u;
		$sy = $y0 + $h - 3 * $u;
		$svg .= '<g><rect x="' . $f($sx - $u) . '" y="' . $f($sy - 3.2 * $u) . '" width="' . $f($len + 2 * $u) . '" height="' . $f(4.4 * $u) . '" rx="' . $f(0.6 * $u) . '" fill="#ffffff" fill-opacity="0.85"/>'
			. '<rect x="' . $f($sx) . '" y="' . $f($sy) . '" width="' . $f($len) . '" height="' . $f(0.5 * $u) . '" fill="#1c2830"/>'
			. '<text x="' . $f($sx + $len / 2) . '" y="' . $f($sy - 0.9 * $u) . '" font-size="' . $f(1.6 * $u) . '" font-family="Liberation Sans, Arial, sans-serif" text-anchor="middle" fill="#1c2830">' . $ft . ' ft</text></g>';
		$svg .= '</svg>';
		return ['svg' => $svg, 'zoom' => $z, 'box' => [$x0, $y0, $w, $h]];
	}
}
