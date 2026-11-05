<?php

/**
 * Production vs estimate card on the project page.
 * @var array $check  ProductionCheck::forProject()
 */
$kwh = static fn (float $n) => number_format($n, 0);
$pct = static fn (?float $r) => $r === null ? '-' : number_format($r * 100, 0) . '%';
$tone = static fn (?float $r) => $r === null ? '' : (round($r * 100) < 85 ? 'chip-orange' : (round($r * 100) > 105 ? 'chip-green' : 'chip-blue'));
$shown = array_slice($check['rows'], 0, 14);
?>
<section class="card card-flush mt" id="production">
	<div class="phase-title">
		<h2>Production vs estimate</h2>
		<span class="muted small"><?= count($check['sites']) === 1 ? e($check['sites'][0]['name']) . ' (' . e($check['sites'][0]['vendor']) . ')' : count($check['sites']) . ' monitored systems' ?></span>
	</div>
	<?php if (!$check['counted']): ?>
		<p class="hint report-hint">No complete month of production since the system started yet.</p>
	<?php else: ?>
		<div class="metrics report-metrics">
			<div><span class="m-num"><span class="chip <?= $tone($check['ratio']) ?> prod-ratio"><?= e($pct($check['ratio'])) ?></span></span><span class="m-label">Of estimate, last <?= $check['counted'] ?> month<?= $check['counted'] === 1 ? '' : 's' ?></span></div>
			<div><span class="m-num"><?= e($kwh($check['actual'])) ?></span><span class="m-label">kWh produced</span></div>
			<div><span class="m-num"><?= e($kwh($check['estimate'])) ?></span><span class="m-label">kWh estimated</span></div>
			<?php if ($check['gaps']): ?><div><span class="m-num"><?= (int) $check['gaps'] ?></span><span class="m-label">Months left out (gaps)</span></div><?php endif; ?>
		</div>
	<?php endif; ?>
	<?php foreach ($check['guarantees'] as $g): ?>
		<div class="prod-guarantee">
			<strong>Production guarantee</strong>, <?= e(rtrim(rtrim(number_format((float) $g['line']['years'], 1), '0'), '.')) ?> years:
			<?php if ($g['threshold'] === null): ?>
				<span class="muted">no guaranteed kWh entered on the warranty line.</span>
			<?php elseif ($g['projected'] === null): ?>
				<?= e($kwh($g['threshold'])) ?> kWh guaranteed; no production pace yet.
			<?php else: ?>
				<?= e($kwh($g['threshold'])) ?> kWh guaranteed. At this pace the system makes about <?= e($kwh($g['projected'])) ?> kWh (<?= e($pct($g['pct_of_threshold'])) ?> of the guarantee)<?= $g['short_kwh'] > 0 ? ', ' . e($kwh($g['short_kwh'])) . ' kWh short' . ($g['payout'] !== null ? ': a payout of about <strong>$' . e(number_format($g['payout'], 0)) . '</strong>' : '') : ': no payout' ?>.
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
	<?php if ($shown): ?>
		<details class="prod-months">
			<summary class="btn btn-ghost btn-small">Month by month</summary>
			<table class="table table-compact">
				<thead><tr><th>Month</th><th class="num">Produced kWh</th><th class="num">Estimated kWh</th><th class="num">Of estimate</th><th></th></tr></thead>
				<tbody>
				<?php foreach ($shown as $r): ?>
					<tr class="<?= $r['counted'] ? '' : 'muted' ?>">
						<td><?= e(date('M Y', strtotime($r['month'] . '-01'))) ?></td>
						<td class="num"><?= e($kwh($r['actual'])) ?></td>
						<td class="num"><?= e($kwh($r['estimate'])) ?></td>
						<td class="num"><?= e($pct($r['estimate'] > 0 ? $r['actual'] / $r['estimate'] : null)) ?></td>
						<td class="small"><?= $r['counted'] ? '' : 'Left out: monitoring reported ' . (int) $r['days'] . ' days' ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
	<?php endif; ?>
	<p class="hint report-hint">Estimate: <?= e($kwh($check['annual'])) ?> kWh a year (the project's estimate), spread over the months by the fleet's own pattern (<?= (int) $check['shape_systems'] ?> monitored systems with a full year), less <?= e(rtrim(rtrim(number_format($check['degradation'] * 100, 2), '0'), '.')) ?>% a year for module aging. Single months swing with the weather; the 12-month figure is the one to judge by.</p>
</section>
