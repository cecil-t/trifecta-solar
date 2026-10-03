<?php
use App\Controllers\ReportController;
use App\Service;
use App\Tasks;

$money = static fn (float $n) => '$' . number_format($n, 0);
$kw = static fn (float $n) => number_format($n, 1);
$ppw = static fn (?float $n) => $n === null ? '' : '$' . number_format($n, 2);
$monthLabel = static fn (string $ym) => date('M Y', strtotime($ym . '-01'));
$avg = static function (array $rows): ?float {
    return $rows ? array_sum(array_map(static fn ($p) => $p['st']['days'], $rows)) / count($rows) : null;
};
$median = static function (array $rows): ?float {
    $d = array_map(static fn ($p) => $p['st']['days'], $rows);
    sort($d);
    $n = count($d);
    return $n ? ($n % 2 ? $d[intdiv($n, 2)] : ($d[$n / 2 - 1] + $d[$n / 2]) / 2) : null;
};
$periodLabel = fmt_date($from) . ' to ' . fmt_date($to);
$salesRow = static function (string $label, array $s) use ($money, $kw, $ppw): string {
    return '<tr><td>' . $label . '</td><td class="num">' . $s['count'] . '</td><td class="num">' . $money($s['price'])
        . ($s['no_price'] ? ' <span class="muted small" title="Projects with no contract price">(' . $s['no_price'] . ' no price)</span>' : '')
        . '</td><td class="num">' . $kw($s['kw']) . '</td><td class="num">' . $ppw($s['ppw']) . '</td></tr>';
};
?>
<div class="page-head">
    <div>
        <h1>Reports</h1>
        <p class="muted small">Sales are dated by contract signed. Cancelled projects are left out.</p>
    </div>
</div>

<form class="filters report-period" method="get" action="/reports">
    <select name="period" onchange="document.getElementById('custom-range').hidden = this.value !== 'custom'; if (this.value !== 'custom') this.form.submit();">
        <?php foreach (ReportController::PERIODS as $k => $l): ?><option value="<?= $k ?>" <?= $period === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <span id="custom-range" class="custom-range" <?= $period === 'custom' ? '' : 'hidden' ?>>
        <input type="date" name="from" value="<?= e($from) ?>" aria-label="From"> to <input type="date" name="to" value="<?= e($to) ?>" aria-label="To">
        <button class="btn btn-secondary btn-small">Apply</button>
    </span>
    <span class="muted small"><?= e($periodLabel) ?></span>
</form>

<nav class="report-nav">
    <a href="#sales">Sales</a><a href="#projection">Projection</a><a href="#time">Project time</a><a href="#service">Service</a>
</nav>

<section class="card card-flush" id="sales">
    <div class="phase-title"><h2>Sales</h2><span class="muted small"><?= e($periodLabel) ?></span></div>
    <div class="metrics report-metrics">
        <div><span class="m-num"><?= $totals['count'] ?></span><span class="m-label">Projects</span></div>
        <div><span class="m-num"><?= $money($totals['price']) ?></span><span class="m-label">Contract value</span></div>
        <div><span class="m-num"><?= $kw($totals['kw']) ?></span><span class="m-label">kW DC</span></div>
        <div><span class="m-num"><?= $ppw($totals['ppw']) ?: '-' ?></span><span class="m-label">Avg $/W</span></div>
    </div>
    <?php if ($noDate): ?><p class="hint report-hint"><?= $noDate ?> project<?= $noDate === 1 ? ' has' : 's have' ?> no contract signed date and <?= $noDate === 1 ? 'is' : 'are' ?> not counted.</p><?php endif; ?>

    <h3 class="sub report-sub">By salesperson</h3>
    <table class="table">
        <thead><tr><th>Salesperson</th><th class="num">Projects</th><th class="num">Contract value</th><th class="num">kW DC</th><th class="num">$/W</th></tr></thead>
        <tbody>
        <?php if (!$bySales): ?><tr><td colspan="5" class="empty">No sales in this period.</td></tr><?php endif; ?>
        <?php foreach ($bySales as $name => $s): ?><?= $salesRow(e($name), $s) ?><?php endforeach; ?>
        </tbody>
        <?php if (count($bySales) > 1): ?><tfoot><?= $salesRow('<strong>Total</strong>', $totals) ?></tfoot><?php endif; ?>
    </table>

    <h3 class="sub report-sub">By quarter</h3>
    <table class="table">
        <thead><tr><th>Quarter</th><th class="num">Projects</th><th class="num">Contract value</th><th class="num">kW DC</th><th class="num">$/W</th></tr></thead>
        <tbody>
        <?php if (!$byQuarter): ?><tr><td colspan="5" class="empty">No sales in this period.</td></tr><?php endif; ?>
        <?php foreach ($byQuarter as $q => $s): ?><?= $salesRow(e($q), $s) ?><?php endforeach; ?>
        </tbody>
    </table>

    <details class="report-more">
        <summary>By month</summary>
        <table class="table">
            <thead><tr><th>Month</th><th class="num">Projects</th><th class="num">Contract value</th><th class="num">kW DC</th><th class="num">$/W</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($byMonth, true) as $ym => $s): if ($period === 'all' && !$s['count']) { continue; } ?><?= $salesRow(e($monthLabel($ym)), $s) ?><?php endforeach; ?>
            </tbody>
        </table>
    </details>
</section>

<section class="card card-flush mt" id="projection">
    <div class="phase-title"><h2>Projection, next 12 months</h2><span class="muted small">Each window's pace, scaled to 12 months</span></div>
    <table class="table">
        <thead><tr><th>Based on</th><th class="num">Actual</th><th class="num">Projects / yr</th><th class="num">Contract value / yr</th><th class="num">kW DC / yr</th></tr></thead>
        <tbody>
        <?php foreach ($projections as $label => $pr): ?>
            <tr>
                <td><?= e($label) ?><div class="muted small"><?= e(fmt_date($pr['from'])) ?> to <?= e(fmt_date($pr['to'])) ?></div>
                    <?php if ($pr['partial']): ?><span class="chip chip-orange" title="The tracker has no signed projects before <?= e(fmt_date($earliest)) ?>, so this window is missing earlier sales and will read low">Incomplete history</span><?php endif; ?>
                </td>
                <td class="num small"><?= $pr['actual']['count'] ?> / <?= $money($pr['actual']['price']) ?> / <?= $kw($pr['actual']['kw']) ?> kW</td>
                <td class="num"><?= number_format($pr['next12']['count'], 1) ?></td>
                <td class="num"><?= $money($pr['next12']['price']) ?></td>
                <td class="num"><?= $kw($pr['next12']['kw']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="hint report-hint">The tracker holds the 2026 tab plus a few older completed jobs, so windows reaching back into 2025 are missing sales that finished before 2026.</p>
</section>

<section class="card card-flush mt" id="time">
    <div class="phase-title"><h2>Project time</h2><span class="muted small">Contract signed to PTO</span></div>

    <h3 class="sub report-sub">Completed (PTO <?= e($periodLabel) ?>)</h3>
    <div class="metrics report-metrics" id="time-stats">
        <div><span class="m-num" data-stat="avg"><?= ($a = $avg($completed)) !== null ? round($a) : '-' ?></span><span class="m-label">Avg days</span></div>
        <div><span class="m-num" data-stat="median"><?= ($m = $median($completed)) !== null ? round($m) : '-' ?></span><span class="m-label">Median days</span></div>
        <div><span class="m-num" data-stat="count"><?= count($completed) ?></span><span class="m-label">Projects counted</span></div>
    </div>
    <p class="hint report-hint">Uncheck a project to leave it out of the average (e.g. a customer delay). Nothing is saved; reloading resets it.<?= $completedNoPto ? ' ' . $completedNoPto . ' completed project' . ($completedNoPto === 1 ? ' has' : 's have') . ' no PTO date and are not listed.' : '' ?></p>
    <table class="table" id="time-table">
        <thead><tr><th class="check-col">Count</th><th>Project</th><th>Signed</th><th>PTO</th><th class="num">Days</th></tr></thead>
        <tbody>
        <?php if (!$completed): ?><tr><td colspan="5" class="empty">No projects reached PTO in this period.</td></tr><?php endif; ?>
        <?php foreach ($completed as $p): ?>
            <tr data-days="<?= (int) $p['st']['days'] ?>">
                <td class="check-col"><input type="checkbox" checked aria-label="Include <?= e($p['project_number']) ?> in the average"></td>
                <td><a href="/projects/<?= (int) $p['id'] ?>"><strong><?= e($p['project_number']) ?></strong> <?= e($p['name']) ?></a></td>
                <td><?= e(fmt_date($p['signed'])) ?></td>
                <td><?= e(fmt_date($p['st']['pto_date'])) ?></td>
                <td class="num"><?= (int) $p['st']['days'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3 class="sub report-sub">In progress (<?= count($inProgress) ?>)</h3>
    <table class="table">
        <thead><tr><th>Project</th><th>Phase</th><th>Signed</th><th class="num">Days so far</th></tr></thead>
        <tbody>
        <?php if (!$inProgress): ?><tr><td colspan="4" class="empty">Nothing in progress.</td></tr><?php endif; ?>
        <?php foreach ($inProgress as $p): ?>
            <tr>
                <td><a href="/projects/<?= (int) $p['id'] ?>"><strong><?= e($p['project_number']) ?></strong> <?= e($p['name']) ?></a></td>
                <td><span class="phase phase-<?= e($p['st']['phase']) ?>"><?= e(Tasks::PHASE_LABELS[$p['st']['phase']]) ?></span><?php if ($p['hold_state'] === 'on_hold'): ?> <span class="chip chip-orange">On hold</span><?php endif; ?></td>
                <td><?= e(fmt_date($p['signed'])) ?></td>
                <td class="num"><?= (int) $p['st']['days'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card card-flush mt" id="service">
    <div class="phase-title"><h2>Service</h2><span class="muted small">Tickets opened <?= e($periodLabel) ?></span></div>
    <div class="metrics report-metrics">
        <div><span class="m-num"><?= $svcTotals['tickets'] ?></span><span class="m-label">Tickets</span></div>
        <div><span class="m-num"><?= $svcTotals['trips'] ?></span><span class="m-label">Trips</span></div>
        <div><span class="m-num"><?= e(Service::hours($svcTotals['hours'])) ?: '0' ?></span><span class="m-label">Man-hours</span></div>
    </div>
    <h3 class="sub report-sub">By coverage</h3>
    <table class="table">
        <thead><tr><th>Coverage</th><th class="num">Tickets</th><th class="num">Trips</th><th class="num">Man-hours</th></tr></thead>
        <tbody>
        <?php foreach ($svcByCoverage as $c => $s): ?>
            <tr><td><?= $c === '' ? '<span class="muted">Not set</span>' : e(Service::COVERAGE[$c]) ?></td><td class="num"><?= $s['tickets'] ?></td><td class="num"><?= $s['trips'] ?></td><td class="num"><?= e(Service::hours($s['hours'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <details class="report-more">
        <summary>By month</summary>
        <table class="table">
            <thead><tr><th>Month</th><th class="num">Tickets</th><th class="num">Trips</th><th class="num">Man-hours</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($svcByMonth, true) as $ym => $s): if ($period === 'all' && !$s['tickets']) { continue; } ?>
                <tr><td><?= e($monthLabel($ym)) ?></td><td class="num"><?= $s['tickets'] ?></td><td class="num"><?= $s['trips'] ?></td><td class="num"><?= e(Service::hours($s['hours'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</section>

<script>
// Live average: unchecked projects drop out of the completed-time stats.
(function () {
    const table = document.getElementById('time-table');
    if (!table) return;
    const set = (k, v) => { const el = document.querySelector('#time-stats [data-stat="' + k + '"]'); if (el) el.textContent = v; };
    function recalc() {
        const days = [];
        table.querySelectorAll('tbody tr[data-days]').forEach((tr) => {
            const on = tr.querySelector('input').checked;
            tr.classList.toggle('is-excluded', !on);
            if (on) days.push(+tr.dataset.days);
        });
        days.sort((a, b) => a - b);
        const n = days.length;
        set('count', n);
        set('avg', n ? Math.round(days.reduce((a, b) => a + b, 0) / n) : '-');
        set('median', n ? Math.round(n % 2 ? days[(n - 1) / 2] : (days[n / 2 - 1] + days[n / 2]) / 2) : '-');
    }
    table.addEventListener('change', recalc);
})();
</script>
