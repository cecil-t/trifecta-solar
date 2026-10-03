<?php
require __DIR__ . '/_helpers.php';
echo App\View::partial('reports/_filters', ['report' => $report, 'f' => $f, 'users' => $users]);
$head = '<thead><tr><th>%s</th><th class="num">Projects</th><th class="num">Contract value</th><th class="num">kW DC</th><th class="num">$/W</th></tr></thead>';
?>
<section class="card card-flush report-card-body">
	<div class="metrics report-metrics">
		<div><span class="m-num"><?= $totals['count'] ?></span><span class="m-label">Projects</span></div>
		<div><span class="m-num"><?= $money($totals['price']) ?></span><span class="m-label">Contract value</span></div>
		<div><span class="m-num"><?= $kwf($totals['kw']) ?></span><span class="m-label">kW DC</span></div>
		<div><span class="m-num"><?= $ppw($totals['ppw']) ?: '-' ?></span><span class="m-label">Avg $/W</span></div>
	</div>
	<p class="hint report-hint">Dated by contract signed; cancelled projects left out.<?= $noDate ? ' ' . $noDate . ' project' . ($noDate === 1 ? ' has' : 's have') . ' no contract signed date and ' . ($noDate === 1 ? 'is' : 'are') . ' not counted.' : '' ?> $/W only counts projects with both a price and equipment.</p>

	<?php if (!$f['sales']): ?>
		<h2 class="sub report-sub">By salesperson</h2>
		<table class="table">
			<?= sprintf($head, 'Salesperson') ?>
			<tbody>
			<?php if (!$bySales): ?><tr><td colspan="5" class="empty">No sales in this range.</td></tr><?php endif; ?>
			<?php foreach ($bySales as $name => $s): ?><?= $salesRow(e($name), $s) ?><?php endforeach; ?>
			</tbody>
			<?php if (count($bySales) > 1): ?><tfoot><?= $salesRow('Total', $totals) ?></tfoot><?php endif; ?>
		</table>
	<?php endif; ?>

	<h2 class="sub report-sub">By quarter</h2>
	<table class="table">
		<?= sprintf($head, 'Quarter') ?>
		<tbody>
		<?php if (!$byQuarter): ?><tr><td colspan="5" class="empty">No sales in this range.</td></tr><?php endif; ?>
		<?php foreach ($byQuarter as $q => $s): ?><?= $salesRow(e($q), $s) ?><?php endforeach; ?>
		</tbody>
	</table>

	<h2 class="sub report-sub">By month</h2>
	<table class="table">
		<?= sprintf($head, 'Month') ?>
		<tbody>
		<?php if (!$byMonth): ?><tr><td colspan="5" class="empty">No sales in this range.</td></tr><?php endif; ?>
		<?php foreach ($byMonth as $ym => $s): if ($f['preset'] === 'all' && !$s['count']) { continue; } ?><?= $salesRow(e($monthLabel($ym)), $s) ?><?php endforeach; ?>
		</tbody>
	</table>
</section>
