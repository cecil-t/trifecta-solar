<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Projects;
use App\Service;
use App\Tasks;
use App\View;

/**
 * Reports. Sales are dated by contract signed (the start-clock task); cancelled projects
 * are left out everywhere. Project time runs from contract signed to PTO.
 */
final class ReportController
{
    public const PERIODS = [
        'ytd' => 'This year', 'last_year' => 'Last year', '12m' => 'Last 12 months', 'all' => 'All time', 'custom' => 'Custom',
    ];

    public function index(): void
    {
        [$period, $from, $to] = $this->period();
        $today = date('Y-m-d');

        // ---- every non-cancelled project with its status
        $projects = Db::all(
            "SELECT p.*, u.name AS sales_name, u.initials AS sales_initials
             FROM projects p LEFT JOIN users u ON u.id = p.salesperson_id
             WHERE COALESCE(p.hold_state, '') <> 'cancelled'
             ORDER BY p.project_number"
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
        $signed = array_filter($projects, static fn ($p) => $p['signed'] !== null);
        $noDate = count($projects) - count($signed);
        $earliest = $signed ? min(array_column($signed, 'signed')) : null;

        // ---- sales in the period
        $inPeriod = array_filter($signed, static fn ($p) => $p['signed'] >= $from && $p['signed'] <= $to);
        $totals = $this->sum($inPeriod);
        $bySales = [];
        foreach ($inPeriod as $p) {
            $key = $p['sales_name'] ?? 'Unassigned';
            $bySales[$key][] = $p;
        }
        $bySales = array_map(fn ($rows) => $this->sum($rows), $bySales);
        uasort($bySales, static fn ($a, $b) => $b['price'] <=> $a['price']);

        $byMonth = [];
        foreach ($this->months($from, $to) as $ym) {
            $byMonth[$ym] = $this->sum(array_filter($inPeriod, static fn ($p) => substr($p['signed'], 0, 7) === $ym));
        }
        $byQuarter = [];
        foreach ($inPeriod as $p) {
            $q = substr($p['signed'], 0, 4) . ' Q' . (int) ceil((int) substr($p['signed'], 5, 2) / 3);
            $byQuarter[$q][] = $p;
        }
        ksort($byQuarter);
        $byQuarter = array_map(fn ($rows) => $this->sum($rows), $byQuarter);

        // ---- projections: run rate in each window, scaled to the next 12 months
        $projections = [];
        foreach ($this->windows($today) as $label => [$wFrom, $wTo]) {
            $rows = array_filter($signed, static fn ($p) => $p['signed'] >= $wFrom && $p['signed'] <= $wTo);
            $s = $this->sum($rows);
            $months = max((strtotime($wTo) - strtotime($wFrom)) / 86400 + 1, 1) / 30.4375;
            $f = 12 / $months;
            $projections[$label] = [
                'from' => $wFrom, 'to' => $wTo, 'actual' => $s, 'months' => $months,
                'next12' => ['count' => $s['count'] * $f, 'price' => $s['price'] * $f, 'kw' => $s['kw'] * $f],
                'partial' => $earliest === null || $earliest > $wFrom,
            ];
        }

        // ---- project time
        $completed = array_values(array_filter($signed, static fn ($p) => $p['st']['pto_date'] && $p['st']['pto_date'] >= $from && $p['st']['pto_date'] <= $to));
        usort($completed, static fn ($a, $b) => $b['st']['days'] <=> $a['st']['days']);
        $inProgress = array_values(array_filter($signed, static fn ($p) => !$p['st']['pto_date'] && $p['st']['phase'] !== 'complete'));
        usort($inProgress, static fn ($a, $b) => $b['st']['days'] <=> $a['st']['days']);
        $completedNoPto = count(array_filter($projects, static fn ($p) => $p['st']['phase'] === 'complete' && !$p['st']['pto_date']));

        // ---- service, by date opened
        $tickets = Db::all(
            "SELECT t.*, (SELECT COALESCE(SUM(trips), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS trips,
                    (SELECT COALESCE(SUM(man_hours), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS man_hours
             FROM service_tickets t WHERE t.opened_on BETWEEN ? AND ?",
            [$from, $to]
        );
        $svcByCoverage = [];
        foreach (array_merge(array_keys(Service::COVERAGE), ['']) as $c) {
            $rows = array_filter($tickets, static fn ($t) => (string) $t['coverage'] === $c);
            if ($rows || $c !== '') {
                $svcByCoverage[$c] = $this->svcSum($rows);
            }
        }
        $svcByMonth = [];
        foreach ($this->months($from, $to) as $ym) {
            $svcByMonth[$ym] = $this->svcSum(array_filter($tickets, static fn ($t) => substr($t['opened_on'], 0, 7) === $ym));
        }

        View::render('reports/index', [
            'title' => 'Reports', 'period' => $period, 'from' => $from, 'to' => $to,
            'totals' => $totals, 'bySales' => $bySales, 'byMonth' => $byMonth, 'byQuarter' => $byQuarter,
            'projections' => $projections, 'earliest' => $earliest, 'noDate' => $noDate,
            'completed' => $completed, 'inProgress' => $inProgress, 'completedNoPto' => $completedNoPto,
            'svcTotals' => $this->svcSum($tickets), 'svcByCoverage' => $svcByCoverage, 'svcByMonth' => $svcByMonth,
        ]);
    }

    /** @return array{0:string,1:string,2:string} */
    private function period(): array
    {
        $period = $_GET['period'] ?? 'ytd';
        $y = (int) date('Y');
        $range = match ($period) {
            'last_year' => [($y - 1) . '-01-01', ($y - 1) . '-12-31'],
            '12m' => [date('Y-m-d', strtotime('-12 months +1 day')), date('Y-m-d')],
            'all' => ['2000-01-01', date('Y-m-d')],
            'custom' => [Projects::parseDate($_GET['from'] ?? '') ?? $y . '-01-01', Projects::parseDate($_GET['to'] ?? '') ?? date('Y-m-d')],
            default => [$y . '-01-01', date('Y-m-d')],
        };
        if (!isset(self::PERIODS[$period])) {
            $period = 'ytd';
        }
        if ($range[0] > $range[1]) {
            $range = [$range[1], $range[0]];
        }
        return [$period, $range[0], $range[1]];
    }

    /** Projection windows, each ending today except the last full quarter. */
    private function windows(string $today): array
    {
        $y = (int) substr($today, 0, 4);
        $q = (int) ceil((int) substr($today, 5, 2) / 3);
        [$qy, $qq] = $q === 1 ? [$y - 1, 4] : [$y, $q - 1];
        $qStart = sprintf('%d-%02d-01', $qy, ($qq - 1) * 3 + 1);
        $qEnd = date('Y-m-t', strtotime(sprintf('%d-%02d-01', $qy, $qq * 3)));
        return [
            'Last full quarter (' . $qy . ' Q' . $qq . ')' => [$qStart, $qEnd],
            'This year to date' => [$y . '-01-01', $today],
            'Rolling 12 months' => [date('Y-m-d', strtotime($today . ' -12 months +1 day')), $today],
            'Rolling 24 months' => [date('Y-m-d', strtotime($today . ' -24 months +1 day')), $today],
        ];
    }

    private function months(string $from, string $to): array
    {
        $out = [];
        $from = max($from, '2015-01-01');
        for ($t = strtotime(substr($from, 0, 7) . '-01'); $t <= strtotime($to); $t = strtotime('+1 month', $t)) {
            $out[] = date('Y-m', $t);
        }
        return $out;
    }

    private function sum(array $rows): array
    {
        $count = count($rows);
        $price = array_sum(array_column($rows, 'price'));
        $kw = array_sum(array_column($rows, 'kw'));
        // $/W only over projects that have both a price and equipment
        $both = array_filter($rows, static fn ($p) => $p['price'] > 0 && $p['kw'] > 0);
        $bp = array_sum(array_column($both, 'price'));
        $bk = array_sum(array_column($both, 'kw'));
        return ['count' => $count, 'price' => $price, 'kw' => $kw, 'ppw' => $bk > 0 ? $bp / ($bk * 1000) : null,
            'no_price' => count(array_filter($rows, static fn ($p) => $p['price'] <= 0))];
    }

    private function svcSum(array $rows): array
    {
        return [
            'tickets' => count($rows),
            'trips' => (int) array_sum(array_column($rows, 'trips')),
            'hours' => (float) array_sum(array_column($rows, 'man_hours')),
        ];
    }
}
