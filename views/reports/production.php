<?php
/**
 * @var array  $rows        projects compared: p (project row), check (ProductionCheck::forProject())
 * @var int    $min         fewest counted months a project needs to be listed
 * @var array  $summary     count, median, weighted, under, over, fewer, no_estimate
 * @var array  $guarantees  p, check, g (projected guarantee)
 * @var string $today
 */
require __DIR__ . '/_helpers.php';
$kwh = static fn (float $n) => number_format($n, 0);
$pct = static fn (?float $r) => $r === null ? '-' : number_format($r * 100, 0) . '%';
$tone = static fn (?float $r) => $r === null ? '' : (round($r * 100) < 85 ? 'chip-orange' : (round($r * 100) > 105 ? 'chip-green' : 'chip-blue'));
?>
<img src="<?= asset('assets/img/logo-horizontal.png') ?>" alt="Trifecta Solar" class="print-only report-logo" width="190">
<div class="page-head report-head">
	<div>
		<a href="/reports" class="back no-print">&larr; Reports</a>
		<h1>Production<?= help_link('reports') ?></h1>
		<p class="report-summary">Projects with at least <?= $min ?> complete month<?= $min === 1 ? '' : 's' ?> of production &middot; as of <?= e(fmt_date($today)) ?></p>
	</div>
	<button type="button" class="btn btn-secondary no-print" data-print>Print</button>
</div>

<form class="filters report-filters no-print" method="get" action="/reports/production">
	<label class="rf-wide">Months of production
		<select name="months" data-autosubmit>
			<?php foreach ([1 => 'At least 1 month', 6 => 'At least 6 months', 12 => 'A full 12 months'] as $k => $l): ?><option value="<?= $k ?>" <?= $min === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
		</select>
	</label>
</form>

<section class="card card-flush report-card-body">
	<div class="metrics report-metrics">
		<div><span class="m-num"><?= $summary['count'] ?></span><span class="m-label">Projects compared</span></div>
		<div><span class="m-num"><?= e($pct($summary['median'])) ?></span><span class="m-label">Median, of estimate</span></div>
		<div><span class="m-num"><?= e($pct($summary['weighted'])) ?></span><span class="m-label">All kWh together</span></div>
		<div><span class="m-num"><?= $summary['under'] ?></span><span class="m-label">Under 85%</span></div>
		<div><span class="m-num"><?= $summary['over'] ?></span><span class="m-label">Over 105%</span></div>
	</div>
	<p class="hint report-hint">Each project: its last 12 complete months of monitored production (fewer when it is newer) against its estimated annual kWh, spread over the months by the fleet's own monthly pattern and less module aging. Months with monitoring gaps are left out of both sides.<?= $summary['fewer'] ? ' ' . $summary['fewer'] . ' more monitored project' . ($summary['fewer'] === 1 ? ' has' : 's have') . ' fewer months.' : '' ?><?= $summary['no_estimate'] ? ' ' . $summary['no_estimate'] . ' monitored project' . ($summary['no_estimate'] === 1 ? ' has' : 's have') . ' no estimated annual kWh and ' . ($summary['no_estimate'] === 1 ? 'is' : 'are') . ' not compared.' : '' ?> Under 85% usually means a problem (a string down, shading, monitoring reading part of the system) or an estimate that was too high.</p>
</section>

<?php if ($guarantees): ?>
	<section class="card card-flush report-card-body mt">
		<div class="phase-title"><h2>Production guarantees</h2><span class="muted small">Projected to the end of each guarantee at the pace so far</span></div>
		<table class="table">
			<thead><tr><th>Project</th><th class="num">Years</th><th class="num">Guaranteed kWh</th><th class="num">Projected kWh</th><th class="num">Of guarantee</th><th class="num">Projected payout</th></tr></thead>
			<tbody>
			<?php foreach ($guarantees as $x): $g = $x['g']; ?>
				<tr>
					<td><a href="/projects/<?= (int) $x['p']['id'] ?>#production"><strong><?= e($x['p']['project_number']) ?></strong> <?= e($x['p']['name']) ?></a></td>
					<td class="num"><?= e(rtrim(rtrim(number_format((float) $g['line']['years'], 1), '0'), '.')) ?></td>
					<td class="num"><?= $g['threshold'] !== null ? e($kwh($g['threshold'])) : '<span class="muted">Not entered</span>' ?></td>
					<td class="num"><?= $g['projected'] !== null ? e($kwh($g['projected'])) : '-' ?></td>
					<td class="num"><?= e($pct($g['pct_of_threshold'])) ?></td>
					<td class="num"><?= $g['payout'] !== null ? ($g['payout'] > 0 ? '<strong>$' . e(number_format($g['payout'], 0)) . '</strong>' : '$0') : '-' ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endif; ?>

<section class="card card-flush report-card-body mt">
	<div class="phase-title"><h2>By project</h2><span class="muted small">Lowest first</span></div>
	<table class="table">
		<thead><tr><th>Project</th><th class="num">Months</th><th class="num">Est. kWh / yr</th><th class="num">Produced kWh</th><th class="num">Estimated kWh</th><th class="num">Of estimate</th></tr></thead>
		<tbody>
		<?php if (!$rows): ?><tr><td colspan="6" class="empty">No monitored projects with an estimate and enough months yet.</td></tr><?php endif; ?>
		<?php foreach ($rows as $r): $c = $r['check']; ?>
			<tr>
				<td><a href="/projects/<?= (int) $r['p']['id'] ?>#production"><strong><?= e($r['p']['project_number']) ?></strong> <?= e($r['p']['name']) ?></a><?= $c['gaps'] ? ' <span class="muted small">(' . (int) $c['gaps'] . ' month' . ($c['gaps'] === 1 ? '' : 's') . ' with gaps left out)</span>' : '' ?></td>
				<td class="num"><?= (int) $c['counted'] ?></td>
				<td class="num"><?= e($kwh($c['annual'])) ?></td>
				<td class="num"><?= e($kwh($c['actual'])) ?></td>
				<td class="num"><?= e($kwh($c['estimate'])) ?></td>
				<td class="num"><span class="chip <?= $tone($c['ratio']) ?>"><?= e($pct($c['ratio'])) ?></span></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>
