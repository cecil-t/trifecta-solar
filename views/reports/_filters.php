<?php
/**
 * Report header: title, filter bar (preset / From / To / Salesperson) and a
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
if (!empty($f['class'])) {
	$summary .= ' · ' . ($f['class'] === 'residential' ? 'Residential' : 'Non-Residential');
}
?>
<img src="<?= asset('assets/img/logo-horizontal.png') ?>" alt="Trifecta Solar" class="print-only report-logo" width="190">
<div class="page-head report-head">
	<div>
		<a href="/reports" class="back no-print">&larr; Reports</a>
		<h1><?= e(ReportController::REPORTS[$report][0]) ?><?= help_link('reports') ?></h1>
		<p class="report-summary"><?= e($summary) ?></p>
	</div>
	<button type="button" class="btn btn-secondary no-print" data-print>Print</button>
</div>

<form class="filters report-filters no-print" method="get" action="/reports/<?= $report ?>" id="report-filters">
	<?php if ($f['asof']): ?>
		<label>As of <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
	<?php else: ?>
		<label class="rf-wide">Date range
			<select name="preset" id="rf-preset">
				<?php foreach (ReportController::PRESETS as $k => $l): ?><option value="<?= $k ?>" <?= $f['preset'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
			</select>
		</label>
		<label>From <input type="date" name="from" value="<?= e($f['from'] === '2000-01-01' ? '' : $f['from']) ?>" id="rf-from"></label>
		<label>To <input type="date" name="to" value="<?= e($f['to']) ?>" id="rf-to"></label>
	<?php endif; ?>
	<?php if ($f['show_sales']): ?>
		<label class="rf-wide">Salesperson
			<select name="sales">
				<option value="">Everyone</option>
				<?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $f['sales'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
			</select>
		</label>
	<?php endif; ?>
	<?php if (array_key_exists('class', $f)): ?>
		<label class="rf-wide">Jobs
			<select name="class">
				<option value="">All jobs</option>
				<option value="residential" <?= $f['class'] === 'residential' ? 'selected' : '' ?>>Residential</option>
				<option value="commercial" <?= $f['class'] === 'commercial' ? 'selected' : '' ?>>Non-Residential</option>
			</select>
		</label>
	<?php endif; ?>
	<button class="btn btn-primary btn-small">Apply</button>
</form>
<script src="<?= asset('assets/js/reports.js') ?>" defer></script>
