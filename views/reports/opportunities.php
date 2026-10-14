<?php
/**
 * @var array $rows   per salesperson (OpportunityReport::finish)
 * @var array $total
 * @var array $cold   open opportunities with no change in 30+ days
 * @var array $lost   lost reasons grouped
 * @var array $overrides  every contingency and line price override, with its reason
 * @var bool  $admin
 */
use App\OpportunityReport;
use App\Presales;

require __DIR__ . '/_helpers.php';
echo App\View::partial('reports/_filters', ['report' => $report, 'f' => $f, 'users' => $users]);
$pct = static fn (?float $v) => $v === null ? '-' : Presales::pct($v);
$m = static fn (?float $v) => $v === null ? '-' : $money($v);
$row = static function (array $r, bool $foot = false) use ($pct, $m): string {
	$c = static fn ($v) => '<td class="num">' . $v . '</td>';
	return '<tr>' . ($foot ? '<td>Total</td>' : '<td>' . e($r['name']) . '</td>')
		. $c($r['count']) . $c($r['open']) . $c($r['sold']) . $c($r['lost']) . $c(e($pct($r['win_rate'])))
		. $c(e($m($r['open_value']))) . $c(e($m($r['sold_value']))) . $c(e($m($r['avg_price']))) . $c($r['avg_ppw'] === null ? '-' : e(Presales::perWatt($r['avg_ppw'])))
		. $c($r['avg_kw'] === null ? '-' : e(number_format($r['avg_kw'], 1))) . $c($r['avg_close'] === null ? '-' : e(number_format($r['avg_close'], 0)))
		. $c(e($pct($r['avg_margin']))) . $c(e($pct($r['avg_markup']))) . $c($r['overrides'] ?: '-') . '</tr>';
};
?>
<section class="card card-flush report-card-body">
	<div class="metrics report-metrics">
		<div><span class="m-num"><?= (int) $total['count'] ?></span><span class="m-label">Opportunities</span></div>
		<div><span class="m-num"><?= e($m($total['open_value'])) ?></span><span class="m-label">Open pipeline</span></div>
		<div><span class="m-num"><?= e($m($total['sold_value'])) ?></span><span class="m-label">Sold</span></div>
		<div><span class="m-num"><?= e($pct($total['win_rate'])) ?></span><span class="m-label">Win rate</span></div>
		<div><span class="m-num"><?= $total['avg_ppw'] === null ? '-' : e(Presales::perWatt($total['avg_ppw'])) ?></span><span class="m-label">Avg $/W</span></div>
	</div>
	<p class="hint report-hint">Dated by when the opportunity was created. Each opportunity counts once: its sold build sheet once sold, otherwise the average of its build sheets, since only one will close. Win rate is sold out of sold plus lost. Days to close runs from created to marked sold.<?= $admin ? '' : ' Your opportunities only.' ?></p>

	<h2 class="sub report-sub">By salesperson</h2>
	<div class="table-wrap">
		<table class="table table-opps">
			<thead><tr><th>Salesperson</th><th class="num">Opps</th><th class="num">Open</th><th class="num">Sold</th><th class="num">Lost</th><th class="num">Win rate</th><th class="num">Open $</th><th class="num">Sold $</th><th class="num">Avg price</th><th class="num">Avg $/W</th><th class="num">Avg kW</th><th class="num">Days to close</th><th class="num">Avg margin</th><th class="num">Avg markup</th><th class="num" title="Contingency and line price overrides on any build sheet">Overrides</th></tr></thead>
			<tbody>
			<?php if (!$rows): ?><tr><td colspan="15" class="empty">No opportunities in this range.</td></tr><?php endif; ?>
			<?php foreach ($rows as $r): ?><?= $row($r) ?><?php endforeach; ?>
			</tbody>
			<?php if (count($rows) > 1): ?><tfoot><?= $row($total, true) ?></tfoot><?php endif; ?>
		</table>
	</div>

	<h2 class="sub report-sub">Overrides <span class="muted small">(contingency and line prices, on any build sheet)</span></h2>
	<div class="table-wrap">
		<table class="table table-overrides">
			<thead><tr><th>Opportunity</th><th>Build sheet</th><th>Salesperson</th><th>Override</th><th class="num">Was</th><th class="num">Now</th><th>Reason</th></tr></thead>
			<tbody>
			<?php if (!$overrides): ?><tr><td colspan="7" class="empty">No overrides in this range.</td></tr><?php endif; ?>
			<?php foreach ($overrides as $ov): ?>
				<tr data-href="/presales/<?= (int) $ov['o']['id'] ?>/designs/<?= (int) $ov['design_id'] ?>">
					<td><a href="/presales/<?= (int) $ov['o']['id'] ?>"><?= e($ov['o']['name']) ?></a></td>
					<td><a href="/presales/<?= (int) $ov['o']['id'] ?>/designs/<?= (int) $ov['design_id'] ?>"><?= e($ov['design']) ?></a></td>
					<td><?= e($ov['o']['salesperson_name'] ?? '') ?></td>
					<td class="cell-desc"><?= e($ov['what']) ?></td>
					<td class="num"><?= e($ov['was']) ?></td>
					<td class="num"><?= e($ov['now']) ?></td>
					<td class="cell-desc"><?= e($ov['reason']) ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<h2 class="sub report-sub">Going cold <span class="muted small">(open, no edits, build sheet saves or log notes in <?= OpportunityReport::COLD_DAYS ?>+ days; any date)</span></h2>
	<div class="table-wrap">
		<table class="table">
			<thead><tr><th>Opportunity</th><th>Salesperson</th><th class="num">Value</th><th>Last activity</th><th class="num">Days</th></tr></thead>
			<tbody>
			<?php if (!$cold): ?><tr><td colspan="5" class="empty">Nothing going cold.</td></tr><?php endif; ?>
			<?php foreach ($cold as $c): ?>
				<tr data-href="/presales/<?= (int) $c['o']['id'] ?>">
					<td><a href="/presales/<?= (int) $c['o']['id'] ?>"><?= e($c['o']['name']) ?></a></td>
					<td><?= e($c['o']['salesperson_name'] ?? '') ?></td>
					<td class="num"><?= $c['v']['priced'] ? e($money($c['v']['price'])) : '-' ?></td>
					<td><?= e(fmt_dt($c['last'], 'm/d/Y')) ?></td>
					<td class="num <?= $c['days'] >= 60 ? 'text-red' : '' ?>"><?= (int) $c['days'] ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<h2 class="sub report-sub">Lost reasons</h2>
	<div class="table-wrap">
		<table class="table">
			<thead><tr><th>Reason</th><th class="num">Lost</th><th>Salesperson</th></tr></thead>
			<tbody>
			<?php if (!$lost): ?><tr><td colspan="3" class="empty">None lost in this range.</td></tr><?php endif; ?>
			<?php foreach ($lost as $l): ?>
				<tr><td class="cell-desc"><?= e($l['reason']) ?></td><td class="num"><?= (int) $l['count'] ?></td><td><?= e(implode(', ', array_keys($l['who']))) ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
