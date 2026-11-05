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
		'warranty' => ['Warranty', 'Systems under warranty, what expires when, warranty work and its estimated cost, and what needs review.'],
		'production' => ['Production', 'Monitored production against each project\'s estimate, how accurate the estimates are, and production guarantees.'],
		'opportunities' => ['Opportunities', 'Sales pipeline by salesperson: open, sold and lost quotes, win rate, pricing, overrides, and quotes going cold.'],
	];

	/** The reports this user may open (Opportunities is for salespeople and admins). */
	public static function visibleReports(): array
	{
		$out = self::REPORTS;
		if (!\App\Presales::canUse()) {
			unset($out['opportunities']);
		}
		return $out;
	}

	public function index(): void
	{
		View::render('reports/index', [
			'title' => 'Reports',
			'projects' => Projects::phaseCounts(),
			'service' => Service::counts(),
			'tasks' => \App\Todos::counts(),
			'recent' => \App\Activity::recentProjects(15),
			'lists' => [
				'customers' => (int) Db::value("SELECT COUNT(*) FROM organizations WHERE type = 'customer'"),
				'municipalities' => (int) Db::value('SELECT COUNT(*) FROM municipalities'),
				'directory' => array_map('intval', array_column(Db::all("SELECT type, COUNT(*) AS n FROM organizations WHERE type <> 'customer' GROUP BY type"), 'n', 'type')),
				'users' => (int) Db::value('SELECT COUNT(*) FROM users WHERE is_active = 1'),
			],
		]);
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
					(SELECT COALESCE(SUM(man_hours), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS man_hours,
					(SELECT COALESCE(SUM(parts_cents), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS parts_cents
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
			'tripRate' => \App\Presales::num('warranty_trip_rate'), 'hourRate' => \App\Presales::num('warranty_hour_rate'),
		]);
	}

	// ------------------------------------------------------------------ warranty

	public const WARRANTY_SHOW = [
		'active' => 'Under Trifecta warranty', 'expiring' => 'Trifecta warranty ends within 12 months', 'expired' => 'Trifecta warranty ended',
		'review' => 'Needs review', 'not_started' => 'Not installed yet', 'all' => 'All projects',
	];
	public const WARRANTY_PROGRAMS = [
		'' => 'Any program', 'trifecta' => 'Trifecta 10 year only', 'solarinsure' => 'SolarInsure', 'se_extended' => 'SolarEdge extended',
		'none' => 'None or unknown',
	];

	/** Warranty: coverage tiles, Trifecta coverage ending by year, flags, and the project list. */
	public function warranty(): void
	{
		$fleet = \App\Warranties::fleet();
		$today = date('Y-m-d');
		$yearAgo = date('Y-m-d', strtotime('-12 months'));
		$tripRate = \App\Presales::num('warranty_trip_rate');
		$hourRate = \App\Presales::num('warranty_hour_rate');

		// Warranty tickets in the last 12 months, and tickets that look miscoded
		$tickets = Db::all(
			"SELECT t.id, t.ticket_number, t.opened_on, t.coverage, t.project_id, t.description, c.name AS customer_name,
					(SELECT COALESCE(SUM(trips), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS trips,
					(SELECT COALESCE(SUM(man_hours), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS man_hours,
					(SELECT COALESCE(SUM(parts_cents), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS parts_cents
			 FROM service_tickets t LEFT JOIN organizations c ON c.id = t.customer_id
			 WHERE t.opened_on >= ? ORDER BY t.opened_on DESC",
			[$yearAgo]
		);
		$byId = array_column($fleet, null, 'id');
		$work = ['tickets' => 0, 'trips' => 0, 'hours' => 0.0, 'parts' => 0.0, 'cost' => 0.0, 'by_project' => []];
		$flags = [];
		foreach ($tickets as $t) {
			$p = $t['project_id'] ? ($byId[(int) $t['project_id']] ?? null) : null;
			if ($t['coverage'] === 'warranty') {
				$cost = (int) $t['trips'] * $tripRate + (float) $t['man_hours'] * $hourRate + (int) $t['parts_cents'] / 100;
				$work['tickets']++;
				$work['trips'] += (int) $t['trips'];
				$work['hours'] += (float) $t['man_hours'];
				$work['parts'] += (int) $t['parts_cents'] / 100;
				$work['cost'] += $cost;
				if ($p) {
					$work['by_project'][(int) $p['id']] = ($work['by_project'][(int) $p['id']] ?? 0) + $cost;
				}
				$tri = $p['trifecta'] ?? null;
				if (!$p) {
					$flags[] = ['ticket' => $t, 'project' => null, 'why' => 'Warranty ticket with no project, so its coverage can\'t be checked'];
				} elseif (!$tri) {
					$flags[] = ['ticket' => $t, 'project' => $p, 'why' => 'Warranty ticket, but the project has no Trifecta warranty (' . strtolower(\App\Warranties::PROGRAMS[$p['program']['code']]) . ')'];
				} elseif ($tri['end'] && $tri['end'] < $t['opened_on']) {
					$flags[] = ['ticket' => $t, 'project' => $p, 'why' => 'Warranty ticket opened after the Trifecta warranty ended (' . \App\Warranties::month($tri['end'], $tri['start_tag']) . '): billable?'];
				} elseif ($p['program']['code'] === 'solarinsure') {
					$flags[] = ['ticket' => $t, 'project' => $p, 'why' => 'SolarInsure job coded Trifecta warranty: confirm SolarInsure doesn\'t cover it'];
				}
			}
		}

		// Per-project review reasons
		$review = static function (array $p): array {
			$why = [];
			if ($p['program']['code'] === 'unknown') {
				$why[] = 'Program unknown';
			}
			if (!$p['installed']) {
				return $why;
			}
			if ($p['program']['assumed'] && !$p['is_legacy']) {
				$why[] = 'SolarInsure question not answered';
			}
			foreach ($p['lines'] as $l) {
				if ($l['flag']) {
					$why[] = $l['label'] . ': ' . strtolower($l['flag']);
				}
				if ($l['key'] === 'trifecta' && !$l['start']) {
					$why[] = 'No date to start the Trifecta warranty from';
				}
				if ($l['key'] === 'trifecta' && $l['start_tag'] === 'estimated') {
					$why[] = 'Trifecta start is estimated';
				}
			}
			return $why;
		};

		$tiles = ['trifecta' => [0, 0.0], 'solarinsure' => [0, 0.0], 'se_extended' => [0, 0.0], 'expiring' => [0, 0.0], 'expired' => [0, 0.0],
			'review' => 0, 'estimated' => 0];
		$runoff = [];
		$rows = [];
		$show = isset(self::WARRANTY_SHOW[$_GET['show'] ?? '']) ? $_GET['show'] : 'active';
		$prog = (string) ($_GET['program'] ?? '');
		$prog = isset(self::WARRANTY_PROGRAMS[$prog]) ? $prog : '';
		$legacy = in_array($_GET['legacy'] ?? '', ['legacy', 'tracker'], true) ? $_GET['legacy'] : '';
		$q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
		foreach ($fleet as $p) {
			$p['review'] = $review($p);
			$p['work_cost'] = $work['by_project'][(int) $p['id']] ?? 0;
			$tri = $p['trifecta'];
			$has = static fn (string $key) => (bool) array_filter($p['lines'], static fn ($l) => $l['key'] === $key && $l['status'] === 'active');
			if ($tri && $tri['status'] === 'active') {
				$tiles['trifecta'][0]++;
				$tiles['trifecta'][1] += $p['kw'];
				$year = (int) substr($tri['end'], 0, 4);
				$runoff[$year] = ($runoff[$year] ?? 0) + $p['kw'];
				if ($tri['months_left'] <= 12) {
					$tiles['expiring'][0]++;
					$tiles['expiring'][1] += $p['kw'];
				}
				if ($tri['start_tag'] === 'estimated') {
					$tiles['estimated']++;
				}
			}
			if ($tri && $tri['status'] === 'expired') {
				$tiles['expired'][0]++;
				$tiles['expired'][1] += $p['kw'];
			}
			foreach (['solarinsure', 'se_extended'] as $k) {
				if ($has($k)) {
					$tiles[$k][0]++;
					$tiles[$k][1] += $p['kw'];
				}
			}
			if ($p['review']) {
				$tiles['review']++;
			}

			// Filters for the list
			$keep = match ($show) {
				'active' => $tri && $tri['status'] === 'active',
				'expiring' => $tri && $tri['status'] === 'active' && $tri['months_left'] <= 12,
				'expired' => $tri && $tri['status'] === 'expired',
				'review' => (bool) $p['review'],
				'not_started' => !$p['installed'],
				default => true,
			};
			$keep = $keep && match ($prog) {
				'trifecta' => $p['program']['code'] === 'trifecta',
				'solarinsure' => $p['program']['code'] === 'solarinsure',
				'se_extended' => $has('se_extended'),
				'none' => in_array($p['program']['code'], ['none', 'unknown'], true),
				default => true,
			};
			$keep = $keep && ($legacy === '' || ($legacy === 'legacy') === (bool) $p['is_legacy']);
			if ($keep && $q !== '') {
				$hay = mb_strtolower($p['project_number'] . ' ' . $p['name'] . ' ' . $p['customer_name'] . ' ' . $p['site_city']);
				$keep = str_contains($hay, $q);
			}
			if ($keep) {
				$rows[] = $p;
			}
		}
		usort($rows, static fn ($a, $b) => [$a['trifecta']['end'] ?? '9999', $a['project_number']] <=> [$b['trifecta']['end'] ?? '9999', $b['project_number']]);
		if ($runoff) {
			// Every year from now to the last one, so gaps show as gaps
			for ($y = min((int) date('Y'), min(array_keys($runoff))); $y <= max(array_keys($runoff)); $y++) {
				$runoff[$y] ??= 0.0;
			}
			ksort($runoff);
		}

		View::render('reports/warranty', [
			'title' => 'Warranty report', 'report' => 'warranty',
			'tiles' => $tiles, 'runoff' => $runoff, 'rows' => $rows, 'flags' => $flags, 'work' => $work,
			'perSystemYear' => $tiles['trifecta'][0] ? $work['cost'] / max(1, $tiles['trifecta'][0]) : null,
			'tripRate' => $tripRate, 'hourRate' => $hourRate,
			'filter' => ['show' => $show, 'program' => $prog, 'legacy' => $legacy, 'q' => (string) ($_GET['q'] ?? '')],
			'total' => count($fleet), 'today' => $today,
		]);
	}

	// ------------------------------------------------------------------ production

	/** Production vs estimate across monitored projects. */
	public function production(): void
	{
		$min = in_array((int) ($_GET['months'] ?? 6), [1, 6, 12], true) ? (int) ($_GET['months'] ?? 6) : 6;
		$fleet = \App\ProductionCheck::fleet();
		$rows = array_values(array_filter($fleet['rows'], static fn ($r) => $r['check']['counted'] >= $min && $r['check']['ratio'] !== null));
		usort($rows, static fn ($a, $b) => $a['check']['ratio'] <=> $b['check']['ratio']);
		$ratios = array_map(static fn ($r) => $r['check']['ratio'], $rows);
		sort($ratios);
		$n = count($ratios);
		$actual = array_sum(array_map(static fn ($r) => $r['check']['actual'], $rows));
		$estimate = array_sum(array_map(static fn ($r) => $r['check']['estimate'], $rows));
		$guarantees = [];
		foreach ($fleet['rows'] as $r) {
			foreach ($r['check']['guarantees'] as $g) {
				$guarantees[] = ['p' => $r['p'], 'check' => $r['check'], 'g' => $g];
			}
		}
		View::render('reports/production', [
			'title' => 'Production report', 'report' => 'production', 'rows' => $rows, 'min' => $min,
			'summary' => [
				'count' => $n, 'median' => $n ? ($n % 2 ? $ratios[intdiv($n, 2)] : ($ratios[$n / 2 - 1] + $ratios[$n / 2]) / 2) : null,
				'weighted' => $estimate > 0 ? $actual / $estimate : null,
				'under' => count(array_filter($ratios, static fn ($r) => round($r * 100) < 85)),
				'over' => count(array_filter($ratios, static fn ($r) => round($r * 100) > 105)),
				'fewer' => count($fleet['rows']) - $n, 'no_estimate' => $fleet['no_estimate'],
			],
			'guarantees' => $guarantees, 'today' => date('Y-m-d'),
		]);
	}

	// ------------------------------------------------------------------ opportunities

	/** Sales pipeline. Admins see everyone (or one salesperson); a salesperson sees their own. */
	public function opportunities(): void
	{
		\App\Presales::requireUse();
		$admin = \App\Auth::isAdmin();
		$f = $this->filters('ytd', false, $admin);
		if (!$admin) {
			$f['sales'] = (int) \App\Auth::id();
			$f['sales_name'] = (string) (\App\Auth::user()['name'] ?? '');
		}
		$class = in_array($_GET['class'] ?? '', ['residential', 'commercial'], true) ? $_GET['class'] : '';
		$f['class'] = $class;
		$this->render('opportunities', $f, \App\OpportunityReport::build($f['from'], $f['to'], $f['sales'], $class) + ['admin' => $admin]);
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
			'w_parts' => array_sum(array_map('intval', array_column($w, 'parts_cents'))) / 100,
			'parts' => array_sum(array_map('intval', array_column($rows, 'parts_cents'))) / 100,
			'tickets' => count($rows),
			'trips' => (int) array_sum(array_column($rows, 'trips')),
			'hours' => (float) array_sum(array_column($rows, 'man_hours')),
			'amount' => array_sum(array_map(static fn ($t) => (int) $t['bill_amount_cents'], $billed)) / 100,
			'billed' => count($billed),
		];
	}
}
