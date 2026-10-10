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

	public function show(): void
	{
		$today = date('Y-m-d');
		$week = date('Y-m-d', strtotime('-6 days'));
		$month = date('Y-m-01');
		$year = date('Y-01-01');
		$recent = date('Y-m-d', strtotime('-' . self::NO_PRODUCTION_DAYS . ' days'));

		$sums = [];
		foreach (Db::all(
			'SELECT site_id,
				SUM(CASE WHEN date >= ? THEN wh END) AS week,
				SUM(CASE WHEN date >= ? THEN wh END) AS month,
				SUM(CASE WHEN date >= ? THEN wh END) AS year,
				MAX(CASE WHEN date >= ? AND date < ? THEN wh END) AS recent_max,
				MAX(CASE WHEN wh > 0 THEN date END) AS last_production,
				MIN(date) AS first_date
			FROM monitor_daily GROUP BY site_id',
			[$week, $month, $year, $recent, $today]
		) as $r) {
			$sums[(int) $r['site_id']] = $r;
		}

		$vendors = [];
		foreach (MonitorCollector::VENDORS as $key => $label) {
			$vendors[$key] = ['key' => $key, 'label' => $label, 'systems' => 0, 'kw' => 0.0, 'today' => null, 'week' => 0.0,
				'month' => 0.0, 'year' => 0.0, 'attention' => 0, 'pending' => 0, 'first_date' => null, 'run' => null];
		}
		$fleet = ['systems' => 0, 'kw' => 0.0, 'today' => 0.0, 'week' => 0.0, 'month' => 0.0, 'year' => 0.0];
		$attention = [];

		foreach (Db::all('SELECT * FROM monitor_sites WHERE ignored = 0 ORDER BY vendor, name') as $s) {
			if (!isset($vendors[$s['vendor']])) {
				continue;
			}
			$v = &$vendors[$s['vendor']];
			$d = $sums[(int) $s['id']] ?? [];
			$fresh = $s['today_date'] === $today;
			$todayWh = $fresh ? $s['today_wh'] : null;
			// Vendor-supplied totals when fresh (Enphase, APsystems); otherwise sum the stored days.
			$weekWh = $fresh && $s['week_wh'] !== null ? (float) $s['week_wh'] : (float) ($d['week'] ?? 0);
			$monthWh = $fresh && $s['month_wh'] !== null ? (float) $s['month_wh'] : (float) ($d['month'] ?? 0);
			$yearWh = $fresh && $s['year_wh'] !== null ? (float) $s['year_wh'] : (float) ($d['year'] ?? 0);

			$v['systems']++;
			$v['kw'] += (float) $s['capacity_kw'];
			if ($todayWh !== null) {
				$v['today'] = ($v['today'] ?? 0) + (float) $todayWh;
				$fleet['today'] += (float) $todayWh;
			}
			$v['week'] += $weekWh;
			$v['month'] += $monthWh;
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
			$run = Db::one('SELECT * FROM monitor_runs WHERE vendor = ? ORDER BY id DESC LIMIT 1', [$key]);
			$ok = Db::value('SELECT MAX(finished_at) FROM monitor_runs WHERE vendor = ? AND ok = 1', [$key]);
			$v['run'] = ['last_ok' => $ok ? (string) $ok : null, 'failed' => $run && !$run['ok'] && $run['finished_at'] ? (string) $run['message'] : null];
			$v['yield_month'] = $v['kw'] > 0 ? $v['month'] / 1000 / $v['kw'] : null; // kWh per kW this month
		}
		unset($v);
		$vendors = array_filter($vendors, static fn ($v) => $v['systems'] > 0 || $v['run']['last_ok'] || $v['run']['failed']);

		[$seLeft] = MonitorCollector::seBudget();
		View::render('monitor/index', [
			'title' => 'Monitoring',
			'refresh' => 900,
			'fleet' => $fleet,
			'vendors' => $vendors,
			'attention' => $attention,
			'yearStart' => $year,
			'seCreditsLeft' => $seLeft,
			'noProductionDays' => self::NO_PRODUCTION_DAYS,
		]);
	}
}
