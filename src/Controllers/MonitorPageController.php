<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\MonitorCollector;
use App\View;

/**
 * The monitoring page (office display): fleet totals, a breakdown by manufacturer and the systems
 * that need attention. Reads only the database that monitor:poll fills; it never calls a vendor,
 * so it can refresh as often as it likes.
 */
final class MonitorPageController
{
	/** A site with no production on any of the last N complete days is flagged. */
	public const NO_PRODUCTION_DAYS = 3;

	/** Rolling window for the "Last 30 days" totals and the kWh per kW daily average. */
	public const ROLLING_DAYS = 30;

	public function show(): void
	{
		$today = date('Y-m-d');
		$week = date('Y-m-d', strtotime('-6 days'));
		$month = date('Y-m-01');
		$year = date('Y-01-01');
		$recent = date('Y-m-d', strtotime('-' . self::NO_PRODUCTION_DAYS . ' days'));
		$d30 = date('Y-m-d', strtotime('-' . (self::ROLLING_DAYS - 1) . ' days')); // rolling window, today included
		$d30c = date('Y-m-d', strtotime('-' . self::ROLLING_DAYS . ' days'));      // last 30 complete days

		$sums = [];
		foreach (Db::all(
			'SELECT site_id,
				SUM(CASE WHEN date >= ? THEN wh END) AS week,
				SUM(CASE WHEN date >= ? THEN wh END) AS month,
				SUM(CASE WHEN date >= ? THEN wh END) AS year,
				MAX(CASE WHEN date >= ? AND date < ? THEN wh END) AS recent_max,
				MAX(CASE WHEN wh > 0 THEN date END) AS last_production,
				MAX(CASE WHEN date = ? THEN wh END) AS yesterday,
				SUM(CASE WHEN date >= ? THEN wh END) AS d30,
				SUM(CASE WHEN date >= ? AND date < ? THEN wh END) AS d30c,
				MIN(date) AS first_date
			FROM monitor_daily GROUP BY site_id',
			[$week, $month, $year, $recent, $today, date('Y-m-d', strtotime('-1 day')), $d30, $d30c, $today]
		) as $r) {
			$sums[(int) $r['site_id']] = $r;
		}

		// Months filled by monitor:backfill count toward the year only where no daily values exist.
		$monthly = [];
		foreach (Db::all(
			"SELECT m.site_id, SUM(m.wh) AS wh, MIN(m.month) AS first_month FROM monitor_monthly m
			WHERE m.month >= ? AND m.month < ?
				AND NOT EXISTS (SELECT 1 FROM monitor_daily d WHERE d.site_id = m.site_id AND d.date LIKE m.month || '-%')
			GROUP BY m.site_id",
			[substr($year, 0, 7), substr($month, 0, 7)]
		) as $r) {
			$monthly[(int) $r['site_id']] = $r;
		}

		// Per brand: days with data among the last 30 complete days, and where its daily history starts.
		$days = [];
		foreach (Db::all(
			'SELECT s.vendor, COUNT(DISTINCT CASE WHEN d.date >= ? AND d.date < ? THEN d.date END) AS n, MIN(d.date) AS first
			FROM monitor_daily d JOIN monitor_sites s ON s.id = d.site_id WHERE s.ignored = 0 GROUP BY s.vendor',
			[$d30c, $today]
		) as $r) {
			$days[$r['vendor']] = $r;
		}

		$vendors = [];
		foreach (MonitorCollector::VENDORS as $key => $label) {
			$vendors[$key] = ['key' => $key, 'label' => $label, 'source' => MonitorCollector::SOURCES[$key] ?? ['', '', ''], 'systems' => 0, 'kw' => 0.0, 'kw_ac' => 0.0, 'yesterday' => null, 'week' => 0.0, 'd30' => 0.0, 'd30c' => 0.0,
				'month' => 0.0, 'year' => 0.0, 'attention' => 0, 'pending' => 0, 'first_date' => null, 'run' => null,
				'kw_active' => 0.0, 'kw_ac_active' => 0.0, 'active' => 0, 'active_with_yesterday' => 0];
		}
		$fleet = ['systems' => 0, 'kw' => 0.0, 'kw_ac' => 0.0, 'yesterday' => 0.0, 'yesterday_missing' => [], 'd30' => 0.0, 'd30_partial' => [], 'week' => 0.0, 'month' => 0.0, 'year' => 0.0];
		$attention = [];

		foreach (Db::all('SELECT * FROM monitor_sites WHERE ignored = 0 ORDER BY vendor, name') as $s) {
			if (!isset($vendors[$s['vendor']])) {
				continue;
			}
			$v = &$vendors[$s['vendor']];
			$d = $sums[(int) $s['id']] ?? [];
			$fresh = $s['today_date'] === $today;
			// Vendor-supplied totals when fresh (Enphase, APsystems); otherwise sum the stored days.
			$weekWh = $fresh && $s['week_wh'] !== null ? (float) $s['week_wh'] : (float) ($d['week'] ?? 0);
			$monthWh = $fresh && $s['month_wh'] !== null ? (float) $s['month_wh'] : (float) ($d['month'] ?? 0);
			$mo = $monthly[(int) $s['id']] ?? null;
			$yearWh = $fresh && $s['year_wh'] !== null ? (float) $s['year_wh'] : (float) ($d['year'] ?? 0) + (float) ($mo['wh'] ?? 0);
			if ($mo && ($d['first_date'] ?? null) !== null) {
				$d['first_date'] = min((string) $d['first_date'], $mo['first_month'] . '-01');
			} elseif ($mo) {
				$d['first_date'] = $mo['first_month'] . '-01';
			}

			$v['systems']++;
			$v['kw'] += (float) $s['capacity_kw'];
			// AC rating only where the vendor gives no DC size (Growatt), never added to DC.
			$ac = (float) $s['capacity_kw'] > 0 ? 0.0 : (float) ($s['capacity_ac_kw'] ?? 0);
			$v['kw_ac'] += $ac;
			$fleet['kw_ac'] += $ac;
			// Producing sites (not pending, not offline): the base for kWh per kW, and for judging whether
			// a brand's yesterday is in (most of them have a value), so a few dark sites do not flag it.
			if (!in_array($s['state'], ['pending', 'offline'], true)) {
				$v['active']++;
				$v['kw_active'] += (float) $s['capacity_kw'];
				$v['kw_ac_active'] += $ac;
				if (isset($d['yesterday']) && $d['yesterday'] !== null) {
					$v['active_with_yesterday']++;
				}
			}
			// Yesterday: a complete day for every brand (SolarEdge's weekend days arrive Monday morning).
			if (isset($d['yesterday']) && $d['yesterday'] !== null) {
				$v['yesterday'] = ($v['yesterday'] ?? 0) + (float) $d['yesterday'];
				$fleet['yesterday'] += (float) $d['yesterday'];
			}
			$v['week'] += $weekWh;
			$v['month'] += $monthWh;
			$v['d30'] += (float) ($d['d30'] ?? 0);
			$v['d30c'] += (float) ($d['d30c'] ?? 0);
			$v['year'] += $yearWh;
			if ($s['state'] === 'pending') {
				$v['pending']++;
			}
			if (!empty($d['first_date']) && ($v['first_date'] === null || $d['first_date'] < $v['first_date'])) {
				$v['first_date'] = $d['first_date'];
			}
			$fleet['systems']++;
			$fleet['kw'] += (float) $s['capacity_kw'];
			$fleet['week'] += $weekWh;
			$fleet['month'] += $monthWh;
			$fleet['year'] += $yearWh;

			// Needs attention: flagged by the manufacturer, or nothing produced on the last 3 complete days
			// (only once the tracker has watched the site that long, and not for sites still pending).
			$reasons = [];
			if (in_array($s['state'], ['alert', 'offline'], true)) {
				$reasons[] = (string) $s['status_text'];
			}
			$watched = min((string) ($d['first_date'] ?? $today), substr((string) $s['first_seen_at'], 0, 10)) <= $recent;
			if ($s['state'] !== 'pending' && $watched && (float) ($d['recent_max'] ?? 0) <= 0) {
				$reasons[] = 'No production for ' . self::NO_PRODUCTION_DAYS . '+ days';
			}
			if ($reasons) {
				$v['attention']++;
				$attention[] = [
					'vendor' => $v['label'],
					'name' => $s['name'] !== '' ? $s['name'] : $s['vendor_site_id'],
					'reason' => implode('; ', array_unique($reasons)),
					'offline' => $s['state'] === 'offline' || count($reasons) > 1,
					'impact' => (int) $s['alert_impact'],
					'last_production' => $d['last_production'] ?? null,
					'project_id' => $s['project_id'],
				];
			}
			unset($v);
		}
		usort($attention, static fn ($a, $b) => [$b['offline'], $b['impact'], $a['name']] <=> [$a['offline'], $a['impact'], $b['name']]);

		foreach ($vendors as $key => &$v) {
			// Scheduled pulls only: a one-time backfill's outcome is shown by the console, not here.
			$scheduled = "vendor = ? AND finished_at IS NOT NULL AND COALESCE(message, '') NOT LIKE 'Backfill%'";
			$run = Db::one("SELECT * FROM monitor_runs WHERE $scheduled ORDER BY id DESC LIMIT 1", [$key]);
			$ok = Db::value("SELECT MAX(finished_at) FROM monitor_runs WHERE $scheduled AND ok = 1", [$key]);
			$v['run'] = ['last_ok' => $ok ? (string) $ok : null, 'failed' => $run && !$run['ok'] && $run['finished_at'] ? (string) $run['message'] : null];
			// kWh per kW per day, averaged over the days with data among the last 30 complete days:
			// per kW DC where known, else per kW AC (labeled on the page).
			// Pending and offline sites are left out of the base: they are not producing.
			$v['yield_ac'] = $v['kw_active'] <= 0 && $v['kw_ac_active'] > 0;
			$base = $v['kw_active'] > 0 ? $v['kw_active'] : $v['kw_ac_active'];
			$n = (int) ($days[$key]['n'] ?? 0);
			$v['yield_days'] = $n;
			$v['yield_day'] = $base > 0 && $n > 0 ? $v['d30c'] / 1000 / $n / $base : null;

			// Rolling 30 days: a brand with under a week of daily history shows "-" and stays out of
			// the fleet total; one with more but not the full window counts, with a note.
			$first = $days[$key]['first'] ?? null;
			$v['d30_short'] = $v['systems'] > 0 && ($first === null || $first > date('Y-m-d', strtotime('-6 days')));
			if ($v['systems'] > 0 && ($first === null || $first > $d30)) {
				$fleet['d30_partial'][$v['label']] = ['from' => $first, 'left_out' => $v['d30_short']];
			}
			if (!$v['d30_short']) {
				$fleet['d30'] += $v['d30'];
			}

			if ($v['active'] > 0 && $v['active_with_yesterday'] * 2 < $v['active']) {
				$fleet['yesterday_missing'][$v['label']] = true;
			}
		}
		unset($v);
		$vendors = array_filter($vendors, static fn ($v) => $v['systems'] > 0 || $v['run']['last_ok'] || $v['run']['failed']);

		[$seLeft] = MonitorCollector::seBudget();
		View::render('monitor/index', [
			'title' => 'Monitoring',
			// Reload about 90 seconds after the next quarter hour, when monitor:poll (cron */15) has
			// stored its pull. Reloading never calls a vendor, so this only sets how fresh the screen is.
			'refresh' => 900 - (time() % 900) + 90,
			'fleet' => $fleet,
			'vendors' => $vendors,
			'attention' => $attention,
			'yearStart' => $year,
			'seCreditsLeft' => $seLeft,
			'noProductionDays' => self::NO_PRODUCTION_DAYS,
		]);
	}
}
