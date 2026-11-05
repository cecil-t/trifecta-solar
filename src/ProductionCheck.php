<?php
declare(strict_types=1);

namespace App;

/**
 * Monitored production against the project's estimate, month by month.
 *
 * Estimate: the project's estimated annual kWh, spread over the months by the fleet's own
 * monthly pattern (each month's share of a year, the median across monitored systems with a full
 * year of data), less module degradation (the Sales rates default) for each year since the system
 * started. Actual: the daily production the monitoring collector stored for every system linked to
 * the project. A month counts only when it is complete (the current month never does), after the
 * month the system started, and when monitoring reported on most days of it; months with gaps are
 * left out of both sides and counted, so an offline month doesn't read as underproduction.
 *
 * The headline is the last 12 counted months (or fewer, said so) as actual / estimate.
 * A production guarantee (a project_warranties line) is projected to its end at that pace.
 */
final class ProductionCheck
{
	/** A month counts when monitoring reported on at least this many of its days. */
	public const MIN_DAYS = 25;

	private static ?array $shape = null;

	/** Calendar month (1-12) => share of a year's production, from the fleet. Falls back to a flat 1/12. */
	public static function shape(): array
	{
		if (self::$shape !== null) {
			return self::$shape;
		}
		$byMonth = self::monthsFor(array_map('intval', array_column(Db::all('SELECT id FROM monitor_sites WHERE ignored = 0'), 'id')));
		$shares = [];
		foreach ($byMonth as $months) {
			// The latest full calendar year of months this system has: one of each month, all complete
			$have = [];
			foreach ($months as $ym => $m) {
				if ($m['days'] >= self::MIN_DAYS) {
					$have[(int) substr($ym, 5, 2)] = $m['kwh'];
				}
			}
			if (count($have) < 12 || array_sum($have) <= 0) {
				continue;
			}
			$total = array_sum($have);
			foreach ($have as $mo => $kwh) {
				$shares[$mo][] = $kwh / $total;
			}
		}
		$shape = [];
		for ($mo = 1; $mo <= 12; $mo++) {
			$s = $shares[$mo] ?? [];
			sort($s);
			$n = count($s);
			$shape[$mo] = $n ? ($n % 2 ? $s[intdiv($n, 2)] : ($s[$n / 2 - 1] + $s[$n / 2]) / 2) : 1 / 12;
		}
		$sum = array_sum($shape);
		self::$shape = array_map(static fn ($v) => $v / $sum, $shape);
		self::$shape['_systems'] = count($shares[1] ?? []);
		return self::$shape;
	}

	/**
	 * Monthly kWh per monitoring site: the stored daily values when the month has enough days,
	 * else the vendor's own month total (monitor_monthly, which reaches further back than the
	 * daily history), which counts as a complete month.
	 * @return array<int, array<string, array{kwh:float, days:int}>> site id => 'YYYY-MM' => month
	 */
	public static function monthsFor(array $siteIds): array
	{
		if (!$siteIds) {
			return [];
		}
		$in = implode(',', array_map('intval', $siteIds));
		$out = [];
		foreach (Db::all("SELECT site_id, substr(date, 1, 7) AS ym, SUM(wh) / 1000.0 AS kwh, COUNT(*) AS days
			FROM monitor_daily WHERE site_id IN ($in) GROUP BY site_id, ym") as $r) {
			$out[(int) $r['site_id']][$r['ym']] = ['kwh' => (float) $r['kwh'], 'days' => (int) $r['days']];
		}
		$thisMonth = date('Y-m');
		foreach (Db::all("SELECT site_id, month AS ym, wh / 1000.0 AS kwh FROM monitor_monthly WHERE site_id IN ($in) AND wh > 0") as $r) {
			$have = $out[(int) $r['site_id']][$r['ym']] ?? null;
			if ($r['ym'] < $thisMonth && (!$have || $have['days'] < self::MIN_DAYS)) {
				$out[(int) $r['site_id']][$r['ym']] = ['kwh' => (float) $r['kwh'], 'days' => 31];
			}
		}
		return $out;
	}

	/**
	 * The comparison for one project, or null when it has no estimate or no linked monitoring.
	 * @param ?string $start when the system started producing (PTO or install), YYYY-MM-DD
	 */
	public static function forProject(array $p, ?string $start, array $guarantees = []): ?array
	{
		$annual = (float) ($p['est_annual_kwh'] ?? 0);
		$sites = Db::all('SELECT id, vendor, name FROM monitor_sites WHERE project_id = ? AND ignored = 0', [(int) $p['id']]);
		if ($annual <= 0 || !$sites || !$start) {
			return null;
		}
		$deg = Presales::num('degradation_default') / 100;
		$shape = self::shape();
		$byMonth = self::monthsFor(array_map('intval', array_column($sites, 'id')));
		$thisMonth = date('Y-m');
		$startMonth = substr($start, 0, 7);
		$months = [];
		foreach ($byMonth as $siteMonths) {
			foreach ($siteMonths as $ym => $m) {
				$months[$ym]['kwh'] = ($months[$ym]['kwh'] ?? 0) + $m['kwh'];
				// With several systems on one project, a month counts only if every one of them reported
				$months[$ym]['days'] = min($months[$ym]['days'] ?? PHP_INT_MAX, $m['days']);
				$months[$ym]['sites'] = ($months[$ym]['sites'] ?? 0) + 1;
			}
		}
		krsort($months);
		$rows = [];
		$gaps = 0;
		foreach ($months as $ym => $m) {
			if ($ym >= $thisMonth || $ym <= $startMonth) {
				continue;
			}
			$ok = $m['days'] >= self::MIN_DAYS && $m['sites'] === count($sites);
			$age = max(0, Warranties::monthsBetween($start, $ym . '-15')) / 12;
			$est = $annual * $shape[(int) substr($ym, 5, 2)] * (1 - $deg) ** floor($age);
			if (!$ok) {
				$gaps++;
			}
			$rows[] = ['month' => $ym, 'actual' => $m['kwh'], 'estimate' => $est, 'days' => $m['days'], 'counted' => $ok];
		}
		$counted = array_slice(array_values(array_filter($rows, static fn ($r) => $r['counted'])), 0, 12);
		$actual = array_sum(array_column($counted, 'actual'));
		$estimate = array_sum(array_column($counted, 'estimate'));
		$ratio = $estimate > 0 ? $actual / $estimate : null;

		$out = [
			'sites' => $sites, 'rows' => $rows, 'counted' => count($counted), 'gaps' => $gaps,
			'actual' => $actual, 'estimate' => $estimate, 'ratio' => $ratio, 'annual' => $annual,
			'shape_systems' => $shape['_systems'] ?? 0, 'degradation' => $deg, 'guarantees' => [],
		];
		foreach ($guarantees as $g) {
			$out['guarantees'][] = self::guarantee($g, $annual, $deg, $ratio);
		}
		return $out;
	}

	/** A production guarantee projected to its end at the pace seen so far. */
	private static function guarantee(array $g, float $annual, float $deg, ?float $ratio): array
	{
		$years = (float) $g['years'];
		$expected = 0.0;
		for ($y = 0; $y < (int) ceil($years); $y++) {
			$expected += $annual * (1 - $deg) ** $y * min(1, $years - $y);
		}
		$threshold = $g['guarantee_kwh'];
		$projected = $ratio !== null ? $expected * $ratio : null;
		$short = $projected !== null && $threshold !== null ? max(0, $threshold - $projected) : null;
		return [
			'line' => $g, 'expected' => $expected, 'threshold' => $threshold, 'projected' => $projected,
			'pct_of_threshold' => $projected !== null && $threshold ? $projected / $threshold : null,
			'short_kwh' => $short, 'payout' => $short !== null && $g['guarantee_rate'] !== null ? $short * $g['guarantee_rate'] : null,
		];
	}

	/** The production start for a project: PTO, else its neighbors (Warranties' chain). */
	public static function startFor(array $p, array $dates): ?string
	{
		return Warranties::start('pto', $dates, null, Warranties::installed($p, $dates))[0];
	}

	/** Production guarantee lines stored on a project, for forProject(). */
	public static function guaranteesFor(int $projectId): array
	{
		return array_map(static fn ($w) => ['years' => (float) $w['term_years'], 'provider' => $w['provider'],
			'guarantee_kwh' => $w['guarantee_kwh'] !== null ? (float) $w['guarantee_kwh'] : null,
			'guarantee_rate' => $w['guarantee_rate'] !== null ? (float) $w['guarantee_rate'] : null],
			Db::all("SELECT * FROM project_warranties WHERE project_id = ? AND kind = 'production_guarantee'", [$projectId]));
	}

	/**
	 * Every monitored project with an estimate, for the Production report, plus how many monitored
	 * projects have no estimate. Cancelled projects are left out.
	 */
	public static function fleet(): array
	{
		$projects = Db::all("SELECT p.* FROM projects p WHERE (p.hold_state IS NULL OR p.hold_state <> 'cancelled')
			AND EXISTS (SELECT 1 FROM monitor_sites s WHERE s.project_id = p.id AND s.ignored = 0)");
		$dates = Warranties::datesFor(array_column($projects, 'id'));
		$rows = [];
		$noEstimate = 0;
		foreach ($projects as $p) {
			if ((float) $p['est_annual_kwh'] <= 0) {
				$noEstimate++;
				continue;
			}
			$check = self::forProject($p, self::startFor($p, $dates[(int) $p['id']]), self::guaranteesFor((int) $p['id']));
			if ($check) {
				$rows[] = ['p' => $p, 'check' => $check];
			}
		}
		return ['rows' => $rows, 'no_estimate' => $noEstimate];
	}
}
