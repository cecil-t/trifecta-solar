<?php
/**
 * Report header: title, filter bar (preset / From / To / Salesperson), report tabs, and a
 * one-line summary that is all that prints of the filters.
 * @var string $report
 * @var array  $f
 * @var array  $users
 */
use App\Controllers\ReportController;

$summary = $f['asof'] ? 'As of ' . fmt_date($f['to']) : ($f['preset'] === 'all' ? 'All time through ' . fmt_date($f['to']) : fmt_date($f['from']) . ' to ' . fmt_date($f['to']));
if (!in_array($f['preset'], ['custom', 'all'], true) && !$f['asof']) {
    $summary = ReportController::PRESETS[$f['preset']] . ': ' . $summary;
}
if ($f['sales_name']) {
    $summary .= ' · ' . $f['sales_name'];
}
?>
<div class="page-head report-head">
    <div>
        <a href="/reports" class="back no-print">&larr; Reports</a>
        <h1><?= e(ReportController::REPORTS[$report][0]) ?></h1>
        <p class="report-summary"><?= e($summary) ?></p>
    </div>
    <button type="button" class="btn btn-secondary no-print" onclick="window.print()">Print</button>
</div>

<nav class="tabs no-print">
    <?php foreach (ReportController::REPORTS as $key => [$label]): ?>
        <a href="/reports/<?= $key ?>?<?= e(http_build_query(array_filter(['preset' => $f['asof'] ? null : $f['preset'], 'from' => $f['preset'] === 'custom' ? $f['from'] : null, 'to' => $f['preset'] === 'custom' ? $f['to'] : null, 'sales' => $f['sales'] ?: null]))) ?>" class="tab <?= $key === $report ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<form class="filters report-filters no-print" method="get" action="/reports/<?= $report ?>" id="report-filters">
    <?php if ($f['asof']): ?>
        <label>As of <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
    <?php else: ?>
        <label>Date range
            <select name="preset" id="rf-preset">
                <?php foreach (ReportController::PRESETS as $k => $l): ?><option value="<?= $k ?>" <?= $f['preset'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>From <input type="date" name="from" value="<?= e($f['from'] === '2000-01-01' ? '' : $f['from']) ?>" id="rf-from"></label>
        <label>To <input type="date" name="to" value="<?= e($f['to']) ?>" id="rf-to"></label>
    <?php endif; ?>
    <?php if ($f['show_sales']): ?>
        <label>Salesperson
            <select name="sales">
                <option value="">Everyone</option>
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $f['sales'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <button class="btn btn-primary btn-small">Apply</button>
</form>
<script>
// Picking a preset applies it right away; typing a date switches the preset to Custom.
(function () {
    const form = document.getElementById('report-filters');
    const preset = document.getElementById('rf-preset');
    if (!form) return;
    form.querySelectorAll('select').forEach((s) => s.addEventListener('change', () => form.submit()));
    ['rf-from', 'rf-to'].forEach((id) => {
        const el = document.getElementById(id);
        if (el && preset) el.addEventListener('change', () => { preset.value = 'custom'; });
    });
})();
</script>
