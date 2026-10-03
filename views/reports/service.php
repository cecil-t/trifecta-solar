<?php
use App\Service;

require __DIR__ . '/_helpers.php';
echo App\View::partial('reports/_filters', ['report' => $report, 'f' => $f, 'users' => $users]);
$row = static function (string $label, array $s) use ($money2): string {
    return '<tr><td>' . $label . '</td><td class="num">' . $s['tickets'] . '</td><td class="num">' . $s['trips'] . '</td><td class="num">'
        . e(Service::hours($s['hours'])) . '</td><td class="num">' . ($s['billed'] ? $money2($s['amount']) : '') . '</td></tr>';
};
$head = '<thead><tr><th>%s</th><th class="num">Tickets</th><th class="num">Trips</th><th class="num">Man-hours</th><th class="num">Amount billed</th></tr></thead>';
?>
<section class="card card-flush report-card-body">
    <div class="metrics report-metrics">
        <div><span class="m-num"><?= $totals['tickets'] ?></span><span class="m-label">Tickets</span></div>
        <div><span class="m-num"><?= $totals['trips'] ?></span><span class="m-label">Trips</span></div>
        <div><span class="m-num"><?= e(Service::hours($totals['hours'])) ?: '0' ?></span><span class="m-label">Man-hours</span></div>
        <div><span class="m-num"><?= $money($totals['amount']) ?></span><span class="m-label">Amount billed</span></div>
    </div>
    <p class="hint report-hint">Tickets opened in the date range. Amount billed is what was entered on each ticket (customer or SolarInsure).</p>

    <h3 class="sub report-sub">By coverage</h3>
    <table class="table">
        <?= sprintf($head, 'Coverage') ?>
        <tbody>
        <?php foreach ($byCoverage as $c => $s): ?><?= $row($c === '' ? '<span class="muted">Not set</span>' : e(Service::COVERAGE[$c]), $s) ?><?php endforeach; ?>
        </tbody>
        <tfoot><?= $row('Total', $totals) ?></tfoot>
    </table>

    <h3 class="sub report-sub">By month</h3>
    <table class="table">
        <?= sprintf($head, 'Month') ?>
        <tbody>
        <?php if (!$byMonth): ?><tr><td colspan="5" class="empty">No service tickets in this range.</td></tr><?php endif; ?>
        <?php foreach ($byMonth as $ym => $s): if ($f['preset'] === 'all' && !$s['tickets']) { continue; } ?><?= $row(e($monthLabel($ym)), $s) ?><?php endforeach; ?>
        </tbody>
    </table>
</section>
