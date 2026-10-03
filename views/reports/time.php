<?php
use App\Tasks;

require __DIR__ . '/_helpers.php';
echo App\View::partial('reports/_filters', ['report' => $report, 'f' => $f, 'users' => $users]);
$days = array_map(static fn ($p) => $p['st']['days'], $completed);
sort($days);
$n = count($days);
$avg = $n ? round(array_sum($days) / $n) : '-';
$median = $n ? round($n % 2 ? $days[intdiv($n, 2)] : ($days[$n / 2 - 1] + $days[$n / 2]) / 2) : '-';
?>
<section class="card card-flush report-card-body">
	<div class="phase-title"><h2>Completed</h2><span class="muted small">Reached PTO in the date range</span></div>
	<div class="metrics report-metrics" id="time-stats">
		<div><span class="m-num" data-stat="avg"><?= $avg ?></span><span class="m-label">Avg days</span></div>
		<div><span class="m-num" data-stat="median"><?= $median ?></span><span class="m-label">Median days</span></div>
		<div><span class="m-num" data-stat="count"><?= $n ?></span><span class="m-label">Projects counted</span></div>
	</div>
	<p class="hint report-hint no-print">Uncheck a project to leave it out of the average (e.g. a customer delay). Nothing is saved; reloading resets it.<?= $completedNoPto ? ' ' . $completedNoPto . ' completed project' . ($completedNoPto === 1 ? ' has' : 's have') . ' no PTO date and ' . ($completedNoPto === 1 ? 'is' : 'are') . ' not listed.' : '' ?></p>
	<table class="table" id="time-table">
		<thead><tr><th class="check-col">Count</th><th>Project</th><th>Signed</th><th>PTO</th><th class="num">Days</th></tr></thead>
		<tbody>
		<?php if (!$completed): ?><tr><td colspan="5" class="empty">No projects reached PTO in this range.</td></tr><?php endif; ?>
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
</section>

<section class="card card-flush report-card-body mt print-break">
	<div class="phase-title"><h2>In progress</h2><span class="muted small"><?= count($inProgress) ?> project<?= count($inProgress) === 1 ? '' : 's' ?> without PTO yet, as of today (not limited by the date range)</span></div>
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

<script>
// Live average: unchecked projects drop out of the completed-time stats (and print struck through).
(function () {
	const table = document.getElementById('time-table');
	if (!table) return;
	const set = (k, v) => { const el = document.querySelector('#time-stats [data-stat="' + k + '"]'); if (el) el.textContent = v; };
	table.addEventListener('change', () => {
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
	});
})();
</script>
