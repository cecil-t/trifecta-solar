<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Projects;
use App\Service;
use App\Tasks;
use App\View;

/**
 * Reports, one page each so they print on their own. Sales are dated by contract signed
 * (the start-clock task); cancelled projects are left out everywhere. Project time runs
 * from contract signed to PTO.
 */
final class ReportController
{
	public const PRESETS = [
		'this_quarter' => 'This quarter', 'last_quarter' => 'Last quarter', 'ytd' => 'Year to date',
		'last_year' => 'Last year', '12m' => 'Rolling 12 months', '24m' => 'Rolling 24 months',
		'all' => 'All time', 'custom' => 'Custom dates',
	];
	public const REPORTS = [
		'sales' => ['Sales', 'Projects, contract value and kW by salesperson, quarter and month.'],
		'projection' => ['Projection', 'Next 12 months at the pace of the last quarter, this year, and rolling 12 and 24 months.'],
		'time' => ['Project time', 'Days from contract signed to PTO, with an in-progress list.'],
		'service' => ['Service', 'Tickets, trips, man-hours and amounts billed by coverage and month.'],
	];

	public function index(): void
	{
		View::render('reports/index', ['title' => 'Reports']);
	}

	// ------------------------------------------------------------------ sales

	public function sales(): void
	{
		$f = $this->filters('ytd');
		$signed = $this->signedProjects($f['sales']);
		$inPeriod = array_filter($signed, static fn ($p) => $p['signed'] >= $f['from'] && $p['signed'] <= $f['to']);

		$bySales = [];
		foreach ($inPeriod as $p) {
			$bySales[$p['sales_name'] ?? 'Unassigned'][] = $p;
		}
		$bySales = array_map(fn ($rows) => $this->sum($rows), $bySales);
		uasort($bySales, static fn ($a, $b) => $b['price'] <=> $a['price']);

		$byQuarter = [];
		foreach ($inPeriod as $p) {
			$byQuarter[substr($p['signed'], 0, 4) . ' Q' . (int) ceil((int) substr($p['signed'], 5, 2) / 3)][] = $p;
		}
		ksort($byQuarter);
		$byQuarter = array_map(fn ($rows) => $this->sum($rows), $byQuarter);

		$byMonth = [];
		foreach ($this->months($f['from'], $f['to'], $inPeriod ? min(array_column($inPeriod, 'signed')) : null) as $ym) {
			$byMonth[$ym] = $this->sum(array_filter($inPeriod, static fn ($p) => substr($p['signed'], 0, 7) === $ym));
		}

		$this->render('sales', $f, [
			'totals' => $this->sum($inPeriod), 'bySales' => $bySales, 'byQuarter' => $byQuarter,
			'byMonth' => array_reverse($byMonth, true), 'noDate' => $this->noDateCount($f['sales']),
		]);
	}

	// ------------------------------------------------------------------ projection

	public function projection(): void
	{
		$f = $this->filters('ytd', true);
		$signed = $this->signedProjects($f['sales']);
		$all = $this->signedProjects(0);
		$earliest = $all ? min(array_column($all, 'signed')) : null;
		$asOf = $f['to'];

		$rows = [];
		foreach ($this->windows($asOf) as $label => [$wFrom, $wTo]) {
			$in = array_filter($signed, static fn ($p) => $p['signed'] >= $wFrom && $p['signed'] <= $wTo);
			$s = $this->sum($in);
			$months = max((strtotime($wTo) - strtotime($wFrom)) / 86400 + 1, 1) / 30.4375;
			$k = 12 / $months;
			$rows[$label] = [
				'from' => $wFrom, 'to' => $wTo, 'actual' => $s,
				'next12' => ['count' => $s['count'] * $k, 'price' => $s['price'] * $k, 'kw' => $s['kw'] * $k],
				'partial' => $earliest === null || $earliest > $wFrom,
			];
		}
		$this->render('projection', $f, ['projections' => $rows, 'earliest' => $earliest]);
	}

	// ------------------------------------------------------------------ project time

	public function time(): void
	{
		$f = $this->filters('ytd');
		$signed = $this->signedProjects($f['sales']);
		$completed = array_values(array_filter($signed, static fn ($p) => $p['st']['pto_date'] && $p['st']['pto_date'] >= $f['from'] && $p['st']['pto_date'] <= $f['to']));
		usort($completed, static fn ($a, $b) => $b['st']['days'] <=> $a['st']['days']);
		$inProgress = array_values(array_filter($signed, static fn ($p) => !$p['st']['pto_date'] && $p['st']['phase'] !== 'complete'));
		usort($inProgress, static fn ($a, $b) => $b['st']['days'] <=> $a['st']['days']);
		$noPto = count(array_filter($signed, static fn ($p) => $p['st']['phase'] === 'complete' && !$p['st']['pto_date']));
		$this->render('time', $f, ['completed' => $completed, 'inProgress' => $inProgress, 'completedNoPto' => $noPto]);
	}

	// ------------------------------------------------------------------ service

	public function service(): void
	{
		$f = $this->filters('ytd', false, false);
		$tickets = Db::all(
			"SELECT t.*, (SELECT COALESCE(SUM(trips), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS trips,
					(SELECT COALESCE(SUM(man_hours), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS man_hours
			 FROM service_tickets t WHERE t.opened_on BETWEEN ? AND ?",
			[$f['from'], $f['to']]
		);
		$byCoverage = [];
		foreach (array_merge(array_keys(Service::COVERAGE), ['']) as $c) {
			$rows = array_filter($tickets, static fn ($t) => (string) $t['coverage'] === $c);
			if ($rows || $c !== '') {
				$byCoverage[$c] = $this->svcSum($rows);
			}
		}
		$byMonth = [];
		foreach ($this->months($f['from'], $f['to'], $tickets ? min(array_column($tickets, 'opened_on')) : null) as $ym) {
			$byMonth[$ym] = $this->svcSum(array_filter($tickets, static fn ($t) => substr($t['opened_on'], 0, 7) === $ym));
		}
		$this->render('service', $f, [
			'totals' => $this->svcSum($tickets), 'byCoverage' => $byCoverage, 'byMonth' => array_reverse($byMonth, true),
		]);
	}

	// ------------------------------------------------------------------ shared

	private function render(string $report, array $f, array $data): void
	{
		View::render('reports/' . $report, $data + [
			'title' => self::REPORTS[$report][0] . ' report', 'report' => $report, 'f' => $f,
			'users' => Projects::users(),
		]);
	}

	/**
	 * Date range from a preset (computed here) or custom From/To, plus the salesperson filter.
	 * @return array{preset:string,from:string,to:string,sales:int,sales_name:?string,asof:bool,show_sales:bool}
	 */
	private function filters(string $default, bool $asOf = false, bool $showSales = true): array
	{
		$preset = $_GET['preset'] ?? $default;
		if (!isset(self::PRESETS[$preset])) {
			$preset = $default;
		}
		$today = date('Y-m-d');
		if ($asOf) {
			$to = Projects::parseDate($_GET['to'] ?? '') ?? $today;
			$from = $to;
			$preset = 'custom';
		} else {
			[$from, $to] = $preset === 'custom'
				? [Projects::parseDate($_GET['from'] ?? '') ?? date('Y') . '-01-01', Projects::parseDate($_GET['to'] ?? '') ?? $today]
				: self::range($preset, $today);
			if ($from > $to) {
				[$from, $to] = [$to, $from];
			}
		}
		$sales = $showSales ? (int) ($_GET['sales'] ?? 0) : 0;
		return [
			'preset' => $preset, 'from' => $from, 'to' => $to, 'sales' => $sales,
			'sales_name' => $sales ? (string) Db::value('SELECT name FROM users WHERE id = ?', [$sales]) : null,
			'asof' => $asOf, 'show_sales' => $showSales,
		];
	}

	/** @return array{0:string,1:string} */
	public static function range(string $preset, string $today): array
	{
		$y = (int) substr($today, 0, 4);
		$q = (int) ceil((int) substr($today, 5, 2) / 3);
		$qStart = static fn (int $yy, int $qq) => sprintf('%d-%02d-01', $yy, ($qq - 1) * 3 + 1);
		$qEnd = static fn (int $yy, int $qq) => date('Y-m-t', strtotime(sprintf('%d-%02d-01', $yy, $qq * 3)));
		[$ly, $lq] = $q === 1 ? [$y - 1, 4] : [$y, $q - 1];
		return match ($preset) {
			'this_quarter' => [$qStart($y, $q), $today],
			'last_quarter' => [$qStart($ly, $lq), $qEnd($ly, $lq)],
			'last_year' => [($y - 1) . '-01-01', ($y - 1) . '-12-31'],
			'12m' => [date('Y-m-d', strtotime($today . ' -12 months +1 day')), $today],
			'24m' => [date('Y-m-d', strtotime($today . ' -24 months +1 day')), $today],
			'all' => ['2000-01-01', $today],
			default => [$y . '-01-01', $today],
		};
	}

	/** Projection windows, each ending on the as-of date except the last full quarter. */
	private function windows(string $asOf): array
	{
		$out = [];
		foreach (['last_quarter' => 'Last full quarter', 'ytd' => 'Year to date', '12m' => 'Rolling 12 months', '24m' => 'Rolling 24 months'] as $k => $label) {
			$out[$label] = self::range($k, $asOf);
		}
		return $out;
	}

	/** Non-cancelled projects with a contract signed date, with status, kW and price attached. */
	private function signedProjects(int $salesId): array
	{
		static $cache = [];
		if (!isset($cache['all'])) {
			$projects = Db::all(
				"SELECT p.*, u.name AS sales_name FROM projects p LEFT JOIN users u ON u.id = p.salesperson_id
				 WHERE COALESCE(p.hold_state, '') <> 'cancelled' ORDER BY p.project_number"
			);
			$tasks = Tasks::forProjects(array_column($projects, 'id'));
			$kw = Projects::dcKwMap();
			foreach ($projects as &$p) {
				$st = Tasks::status($p, $tasks[(int) $p['id']] ?? []);
				unset($st['tree']);
				$p['st'] = $st;
				$p['signed'] = $st['start_date'];
				$p['kw'] = $kw[(int) $p['id']] ?? 0.0;
				$p['price'] = $p['contract_price_cents'] !== null ? (int) $p['contract_price_cents'] / 100 : 0.0;
			}
			unset($p);
			$cache['all'] = $projects;
		}
		return array_values(array_filter($cache['all'], static fn ($p) => $p['signed'] !== null
			&& (!$salesId || (int) $p['salesperson_id'] === $salesId)));
	}

	private function noDateCount(int $salesId): int
	{
		return (int) Db::value(
			"SELECT COUNT(*) FROM projects p WHERE COALESCE(p.hold_state, '') <> 'cancelled'" . ($salesId ? ' AND p.salesperson_id = ?' : '') . "
			   AND NOT EXISTS (SELECT 1 FROM project_tasks t WHERE t.project_id = p.id AND t.gate = 'start_clock' AND t.done_date IS NOT NULL)",
			$salesId ? [$salesId] : []
		);
	}

	/** Months from the later of $from and the first data point, through $to. */
	private function months(string $from, string $to, ?string $firstData): array
	{
		$start = max($from, $firstData ?? $to);
		$out = [];
		for ($t = strtotime(substr($start, 0, 7) . '-01'); $t <= strtotime($to); $t = strtotime('+1 month', $t)) {
			$out[] = date('Y-m', $t);
		}
		return $out;
	}

	private function sum(array $rows): array
	{
		$both = array_filter($rows, static fn ($p) => $p['price'] > 0 && $p['kw'] > 0);
		$bk = array_sum(array_column($both, 'kw'));
		return [
			'count' => count($rows),
			'price' => array_sum(array_column($rows, 'price')),
			'kw' => array_sum(array_column($rows, 'kw')),
			'ppw' => $bk > 0 ? array_sum(array_column($both, 'price')) / ($bk * 1000) : null,
			'no_price' => count(array_filter($rows, static fn ($p) => $p['price'] <= 0)),
		];
	}

	private function svcSum(array $rows): array
	{
		$billed = array_filter($rows, static fn ($t) => $t['bill_amount_cents'] !== null);
		$w = array_filter($rows, static fn ($t) => $t['coverage'] === 'warranty');
		return [
			'w_trips' => (int) array_sum(array_column($w, 'trips')),
			'w_hours' => (float) array_sum(array_column($w, 'man_hours')),
			'tickets' => count($rows),
			'trips' => (int) array_sum(array_column($rows, 'trips')),
			'hours' => (float) array_sum(array_column($rows, 'man_hours')),
			'amount' => array_sum(array_map(static fn ($t) => (int) $t['bill_amount_cents'], $billed)) / 100,
			'billed' => count($billed),
		];
	}
}
