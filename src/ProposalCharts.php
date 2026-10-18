<?php
declare(strict_types=1);

namespace App;

/**
 * SVG drawn on the server for the proposal (WeasyPrint runs no JavaScript): the monthly production
 * chart, the monthly bill chart, the cumulative cash flow, and each array's compass and pitch.
 * Colors: brand blue for use and bills without solar, a darker brand green (checked for
 * colorblind separation against the blue) for production and bills with solar. Every chart has a
 * table beside it on the same or the next page.
 */
final class ProposalCharts
{
	public const BLUE = '#025586';
	public const GREEN = '#4E9A2A';
	public const GRID = '#dfe5e8';
	public const INK = '#5d6a72';

	private static function money(float $v): string
	{
		$a = abs($v);
		$s = $a >= 1000000 ? number_format($a / 1000000, 1) . 'M' : ($a >= 10000 ? number_format($a / 1000) . 'k' : number_format($a));
		return ($v < 0 ? '-$' : '$') . $s;
	}

	/** A round step for an axis from 0 to max with about $n gridlines. */
	private static function step(float $max, int $n = 4): float
	{
		if ($max <= 0) {
			return 1.0;
		}
		$raw = $max / $n;
		$mag = pow(10, floor(log10($raw)));
		foreach ([1, 2, 2.5, 5, 10] as $m) {
			if ($m * $mag >= $raw) {
				return $m * $mag;
			}
		}
		return 10 * $mag;
	}

	private static function text(float $x, float $y, string $s, string $anchor = 'middle', string $extra = ''): string
	{
		return '<text x="' . round($x, 1) . '" y="' . round($y, 1) . '" text-anchor="' . $anchor . '" ' . $extra . '>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</text>';
	}

	/** A bar with a 2px rounded top (or bottom, below the baseline). */
	private static function bar(float $x, float $y0, float $w, float $h, string $fill): string
	{
		if (abs($h) < 0.5) {
			return '';
		}
		$r = min(2.0, abs($h) / 2, $w / 2);
		$top = min($y0, $y0 - $h);
		$hh = abs($h);
		// rounded on the end away from the baseline
		if ($h > 0) {
			$d = sprintf('M%.1f %.1fV%.1fQ%.1f %.1f %.1f %.1fH%.1fQ%.1f %.1f %.1f %.1fV%.1fZ', $x, $y0, $top + $r, $x, $top, $x + $r, $top, $x + $w - $r, $x + $w, $top, $x + $w, $top + $r, $y0);
		} else {
			$bot = $y0 + $hh;
			$d = sprintf('M%.1f %.1fV%.1fQ%.1f %.1f %.1f %.1fH%.1fQ%.1f %.1f %.1f %.1fV%.1fZ', $x, $y0, $bot - $r, $x, $bot, $x + $r, $bot, $x + $w - $r, $x + $w, $bot, $x + $w, $bot - $r, $y0);
		}
		return '<path d="' . $d . '" fill="' . $fill . '"/>';
	}

	/**
	 * Monthly production (bars) with monthly use (line), January to December, in kWh.
	 *
	 * @param list<float>      $prod
	 * @param list<float>|null $use
	 */
	public static function production(array $prod, ?array $use, bool $useEstimated): string
	{
		$w = 700;
		$h = 250;
		$l = 56;
		$r = 10;
		$t = 14;
		$b = 30;
		$pw = $w - $l - $r;
		$ph = $h - $t - $b;
		$max = max(1.0, max($prod), $use ? max($use) : 0.0);
		$step = self::step($max * 1.05);
		$top = ceil($max * 1.05 / $step) * $step;
		$y = static fn (float $v): float => $t + $ph - $v / $top * $ph;
		$svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" font-family="Liberation Sans, Arial, sans-serif" font-size="11" fill="' . self::INK . '">';
		for ($v = 0.0; $v <= $top + 0.001; $v += $step) {
			$svg .= '<line x1="' . $l . '" x2="' . ($w - $r) . '" y1="' . round($y($v), 1) . '" y2="' . round($y($v), 1) . '" stroke="' . ($v == 0 ? '#b9c3c9' : self::GRID) . '" stroke-width="1"/>';
			$svg .= self::text($l - 6, $y($v) + 4, number_format($v), 'end');
		}
		$slot = $pw / 12;
		$bw = $slot * 0.56;
		foreach ($prod as $i => $v) {
			$x = $l + $i * $slot + ($slot - $bw) / 2;
			$svg .= self::bar($x, $y(0), $bw, $y(0) - $y((float) $v), self::GREEN);
			$svg .= self::text($l + $i * $slot + $slot / 2, $h - $b + 16, Savings::MONTHS[$i]);
		}
		if ($use) {
			$pts = [];
			foreach ($use as $i => $v) {
				$pts[] = round($l + $i * $slot + $slot / 2, 1) . ',' . round($y((float) $v), 1);
			}
			$svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . self::BLUE . '" stroke-width="2"' . ($useEstimated ? ' stroke-dasharray="6 4"' : '') . '/>';
			foreach ($pts as $p) {
				[$px, $py] = explode(',', $p);
				$svg .= '<circle cx="' . $px . '" cy="' . $py . '" r="4" fill="' . self::BLUE . '" stroke="#fff" stroke-width="2"/>';
			}
		}
		$svg .= self::text(12, $t + $ph / 2, 'kWh per month', 'middle', 'transform="rotate(-90 12 ' . round($t + $ph / 2, 1) . ')"');
		return $svg . '</svg>';
	}

	/** Monthly bill without and with solar, in the order given (the net metering year). */
	public static function bills(array $months): string
	{
		$w = 700;
		$h = 150;
		$l = 56;
		$r = 10;
		$t = 12;
		$b = 30;
		$pw = $w - $l - $r;
		$ph = $h - $t - $b;
		$max = max(1.0, ...array_map(static fn ($m) => max($m['bill_without'], $m['bill_with']), $months));
		$step = self::step($max * 1.05);
		$top = ceil($max * 1.05 / $step) * $step;
		$y = static fn (float $v): float => $t + $ph - $v / $top * $ph;
		$svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" font-family="Liberation Sans, Arial, sans-serif" font-size="11" fill="' . self::INK . '">';
		for ($v = 0.0; $v <= $top + 0.001; $v += $step) {
			$svg .= '<line x1="' . $l . '" x2="' . ($w - $r) . '" y1="' . round($y($v), 1) . '" y2="' . round($y($v), 1) . '" stroke="' . ($v == 0 ? '#b9c3c9' : self::GRID) . '" stroke-width="1"/>';
			$svg .= self::text($l - 6, $y($v) + 4, '$' . number_format($v), 'end');
		}
		$slot = $pw / count($months);
		$bw = $slot * 0.3;
		foreach ($months as $i => $m) {
			$x0 = $l + $i * $slot + $slot / 2;
			$svg .= self::bar($x0 - $bw - 1, $y(0), $bw, $y(0) - $y((float) $m['bill_without']), self::BLUE);
			$svg .= self::bar($x0 + 1, $y(0), $bw, $y(0) - $y((float) $m['bill_with']), self::GREEN);
			$svg .= self::text($x0, $h - $b + 16, $m['label']);
		}
		return $svg . '</svg>';
	}

	/** Cumulative cash flow by year: below zero until the system has paid for itself. */
	public static function cashflow(array $years, float $outlay, ?float $payback): string
	{
		$w = 700;
		$h = 250;
		$l = 64;
		$r = 10;
		$t = 14;
		$b = 30;
		$pw = $w - $l - $r;
		$ph = $h - $t - $b;
		$vals = array_merge([-$outlay], array_map(static fn ($y) => (float) $y['cumulative'], $years));
		$max = max(0.0, ...$vals);
		$min = min(0.0, ...$vals);
		$step = self::step(max($max - $min, 1.0), 5);
		$top = ceil($max / $step) * $step;
		$bot = floor($min / $step) * $step;
		$span = max($top - $bot, 1.0);
		$y = static fn (float $v): float => $t + ($top - $v) / $span * $ph;
		$svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" font-family="Liberation Sans, Arial, sans-serif" font-size="11" fill="' . self::INK . '">';
		for ($v = $bot; $v <= $top + 0.001; $v += $step) {
			$svg .= '<line x1="' . $l . '" x2="' . ($w - $r) . '" y1="' . round($y($v), 1) . '" y2="' . round($y($v), 1) . '" stroke="' . (abs($v) < 0.001 ? '#8f9aa1' : self::GRID) . '" stroke-width="1"/>';
			$svg .= self::text($l - 6, $y($v) + 4, self::money($v), 'end');
		}
		$n = count($vals);
		$slot = $pw / $n;
		$bw = max(2.0, $slot * 0.62);
		foreach ($vals as $i => $v) {
			$x = $l + $i * $slot + ($slot - $bw) / 2;
			$svg .= self::bar($x, $y(0), $bw, $y(0) - $y($v), $v < 0 ? self::BLUE : self::GREEN);
			if ($i === 0 || $i % 5 === 0 || $i === $n - 1) {
				$svg .= self::text($l + $i * $slot + $slot / 2, $h - $b + 16, $i === 0 ? 'Start' : 'Year ' . $i);
			}
		}
		if ($payback !== null) {
			$px = $l + ($payback + 0.5) * $slot;
			$svg .= '<line x1="' . round($px, 1) . '" x2="' . round($px, 1) . '" y1="' . $t . '" y2="' . ($t + $ph) . '" stroke="#1c2830" stroke-width="1" stroke-dasharray="4 3"/>';
			$svg .= self::text($px + 5, $t + 12, 'Paid for itself: ' . Savings::yearsText($payback), 'start', 'fill="#1c2830" font-weight="bold"');
		}
		return $svg . '</svg>';
	}

	/** An array's direction (north up) as a compass with a needle; trackers get a circle (they follow the sun). */
	public static function compass(?int $az, bool $tracker, int $size = 54): string
	{
		$c = $size / 2;
		$rad = $c - 3;
		$svg = '<svg class="orient" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" xmlns="http://www.w3.org/2000/svg">'
			. '<circle cx="' . $c . '" cy="' . $c . '" r="' . $rad . '" fill="#fff" stroke="#9aa5ad" stroke-width="1.5"/>'
			. '<text x="' . $c . '" y="' . round($c - $rad + 11, 1) . '" text-anchor="middle" font-family="Liberation Sans, Arial, sans-serif" font-size="9" font-weight="bold" fill="#5d6a72">N</text>';
		if ($tracker) {
			$svg .= '<circle cx="' . $c . '" cy="' . $c . '" r="' . round($rad * 0.5, 1) . '" fill="none" stroke="' . self::GREEN . '" stroke-width="3"/>';
		} elseif ($az !== null) {
			$x2 = $c + ($rad - 5) * sin(deg2rad($az));
			$y2 = $c - ($rad - 5) * cos(deg2rad($az));
			$svg .= '<line x1="' . $c . '" y1="' . $c . '" x2="' . round($x2, 1) . '" y2="' . round($y2, 1) . '" stroke="' . self::GREEN . '" stroke-width="3" stroke-linecap="round"/>'
				. '<circle cx="' . $c . '" cy="' . $c . '" r="2.5" fill="' . self::GREEN . '"/>';
		}
		return $svg . '</svg>';
	}

	/** The tilt drawn as a line on a level base. */
	public static function pitch(?float $tilt, int $w = 70, int $h = 46): string
	{
		$t = $tilt === null ? 0.0 : max(0.0, min(90.0, $tilt));
		$len = $w - 12;
		$x2 = 6 + $len * cos(deg2rad($t));
		$y2 = max(3.0, $h - 6 - $len * sin(deg2rad($t)));
		return '<svg class="orient" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg">'
			. '<line x1="3" y1="' . ($h - 6) . '" x2="' . ($w - 3) . '" y2="' . ($h - 6) . '" stroke="#9aa5ad" stroke-width="1.5"/>'
			. '<line x1="6" y1="' . ($h - 6) . '" x2="' . round($x2, 1) . '" y2="' . round($y2, 1) . '" stroke="' . self::BLUE . '" stroke-width="4" stroke-linecap="round"/></svg>';
	}
}
