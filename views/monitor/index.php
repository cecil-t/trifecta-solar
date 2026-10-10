<?php
/**
 * @var array  $fleet       systems, kw, today, week, month, year (Wh)
 * @var array  $vendors     per manufacturer: label, source [label, kind, detail], systems, kw, today, week, month, year, attention, pending, first_date, run, yield_month
 * @var array  $attention   vendor, name, reason, offline, impact, last_production
 * @var string $yearStart
 * @var int    $seCreditsLeft
 * @var int    $noProductionDays
 */
$energy = static function (?float $wh): string {
	if ($wh === null) {
		return '-';
	}
	$kwh = $wh / 1000;
	if ($kwh >= 1000) {
		return number_format($kwh / 1000, $kwh >= 100000 ? 0 : 1) . ' MWh';
	}
	return number_format($kwh, $kwh >= 100 ? 0 : 1) . ' kWh';
};
$power = static fn (float $kw): string => $kw >= 1000 ? number_format($kw / 1000, 2) . ' MW' : number_format($kw, $kw >= 100 ? 0 : 1) . ' kW';
$date = static fn (?string $ymd): string => $ymd ? date('m/d/Y', strtotime($ymd)) : '';
$when = static fn (?string $utc): string => $utc ? fmt_dt($utc, fmt_dt($utc, 'Y-m-d') === date('Y-m-d') ? 'g:i A' : 'm/d g:i A') : 'never';
$bar = static function (float $pct, string $class): string {
	$w = max(0.0, min(100.0, $pct));
	return '<svg class="mon-bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">'
		. '<rect class="mon-bar-track" x="0" y="0" width="100" height="10" rx="2"/>'
		. '<rect class="' . e($class) . '" x="0" y="0" width="' . round($w, 2) . '" height="10" rx="2"/></svg>';
};
$fleetKw = max($fleet['kw'], 0.001);
$maxYield = max([0.001, ...array_values(array_map(static fn ($v) => (float) ($v['yield_month'] ?? 0), $vendors))]);
$attentionCount = count($attention);
$latestOk = null;
foreach ($vendors as $v) {
	if ($v['run']['last_ok'] && ($latestOk === null || $v['run']['last_ok'] > $latestOk)) {
		$latestOk = $v['run']['last_ok'];
	}
}
$hasSolarEdge = isset($vendors['solaredge']);
?>
<div class="page-head">
	<div>
		<h1>Monitoring</h1>
		<p class="muted">Every inverter brand in one place. Last data <?= e($when($latestOk)) ?>; this page reloads a minute or two after each quarter-hour pull.</p>
	</div>
</div>

<?php if (!$vendors): ?>
	<section class="card empty-state">
		<h2>No monitoring data yet</h2>
		<p class="muted">The collector (<code>monitor:poll</code>) fills this page once it runs on the server.</p>
	</section>
<?php else: ?>

<div class="stat-row mon-stats">
	<div class="stat"><span class="stat-num"><?= (int) $fleet['systems'] ?></span><span class="stat-label">Systems</span></div>
	<div class="stat"><span class="stat-num"><?= e($power($fleet['kw'])) ?></span><span class="stat-label">Installed (DC)</span></div>
	<div class="stat stat-orange"><span class="stat-num"><?= e($energy($fleet['today'])) ?></span><span class="stat-label">Today<?= $hasSolarEdge ? ' *' : '' ?></span></div>
	<div class="stat"><span class="stat-num"><?= e($energy($fleet['week'])) ?></span><span class="stat-label">Last 7 days</span></div>
	<div class="stat"><span class="stat-num"><?= e($energy($fleet['month'])) ?></span><span class="stat-label"><?= e(date('F')) ?></span></div>
	<div class="stat"><span class="stat-num"><?= e($energy($fleet['year'])) ?></span><span class="stat-label"><?= e(date('Y')) ?><?= $hasSolarEdge ? ' **' : '' ?></span></div>
	<a class="stat <?= $attentionCount ? 'mon-stat-alert' : 'mon-stat-ok' ?>" href="#attention"><span class="stat-num"><?= $attentionCount ?></span><span class="stat-label">Need attention</span></a>
</div>

<section class="card mt">
	<h2>By manufacturer</h2>
	<div class="mon-share" role="img" aria-label="Share of installed capacity by manufacturer">
		<svg class="mon-share-bar" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true">
			<?php $x = 0.0; foreach ($vendors as $v): $w = $v['kw'] / $fleetKw * 100; ?>
				<rect class="mon-fill-<?= e($v['key']) ?>" x="<?= round($x, 2) ?>" y="0" width="<?= round($w, 2) ?>" height="6"/>
			<?php $x += $w; endforeach; ?>
		</svg>
		<ul class="mon-legend">
			<?php foreach ($vendors as $v): ?>
				<li><span class="mon-dot mon-dot-<?= e($v['key']) ?>"></span><?= e($v['label']) ?> <strong><?= number_format($v['kw'] / $fleetKw * 100, 0) ?>%</strong> <span class="muted">of capacity, <?= (int) $v['systems'] ?> <?= $v['systems'] === 1 ? 'system' : 'systems' ?></span></li>
			<?php endforeach; ?>
		</ul>
	</div>

	<div class="mon-scroll">
		<table class="table mon-table">
			<thead>
				<tr>
					<th>Manufacturer</th>
					<th class="num">Systems</th>
					<th>Installed</th>
					<th class="num">Today</th>
					<th class="num">7 days</th>
					<th class="num"><?= e(date('F')) ?></th>
					<th>kWh per kW, <?= e(date('M')) ?></th>
					<th class="num"><?= e(date('Y')) ?></th>
					<th class="num">Attention</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($vendors as $v): ?>
				<tr>
					<td><span class="mon-dot mon-dot-<?= e($v['key']) ?>"></span><strong><?= e($v['label']) ?></strong> <span class="chip mon-src-<?= e($v['source'][1]) ?>" title="<?= e($v['source'][2]) ?>"><?= e($v['source'][0]) ?></span>
						<div class="muted small mon-upd">Updated <?= e($when($v['run']['last_ok'])) ?></div><?php if ($v['run']['failed']): ?><div class="mon-fail"><?= e($v['run']['failed']) ?></div><?php endif; ?></td>
					<td class="num"><?= (int) $v['systems'] ?><?= $v['pending'] ? ' <span class="muted small">(' . (int) $v['pending'] . ' pending)</span>' : '' ?></td>
					<td class="mon-barcell"><?= $bar($v['kw'] / $fleetKw * 100, 'mon-fill-' . $v['key']) ?><span><?= e($power($v['kw'])) ?></span></td>
					<td class="num"><?= $v['today'] === null ? '<span class="muted">-</span>' : e($energy($v['today'])) ?></td>
					<td class="num"><?= e($energy($v['week'])) ?></td>
					<td class="num"><?= e($energy($v['month'])) ?></td>
					<td class="mon-barcell"><?php if ($v['yield_month'] !== null): ?><?= $bar($v['yield_month'] / $maxYield * 100, 'mon-fill-' . $v['key']) ?><span><?= number_format($v['yield_month'], 1) ?></span><?php else: ?><span class="muted">-</span><?php endif; ?></td>
					<td class="num"><?= e($energy($v['year'])) ?></td>
					<td class="num"><?= $v['attention'] ? '<a href="#attention" class="mon-attn">' . (int) $v['attention'] . '</a>' : '<span class="muted">0</span>' ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<details class="card mt mon-attention" id="attention"<?= $attentionCount && $attentionCount <= 8 ? ' open' : '' ?>>
	<summary><h2>Need attention <span class="mon-count<?= $attentionCount ? ' mon-count-alert' : '' ?>"><?= $attentionCount ?></span></h2></summary>
	<?php if (!$attention): ?>
		<p class="muted">Nothing flagged: no manufacturer alerts and every system produced in the last <?= (int) $noProductionDays ?> days.</p>
	<?php else: ?>
		<div class="mon-scroll">
			<table class="table mon-table">
				<thead><tr><th>Manufacturer</th><th>System</th><th>Why</th><th>Last production</th></tr></thead>
				<tbody>
				<?php foreach ($attention as $a): ?>
					<tr class="<?= $a['offline'] ? 'mon-row-offline' : '' ?>">
						<td><?= e($a['vendor']) ?></td>
						<td><?php if ($a['project_id']): ?><a href="/projects/<?= (int) $a['project_id'] ?>"><?= e($a['name']) ?></a><?php else: ?><?= e($a['name']) ?><?php endif; ?></td>
						<td class="mon-why"><?= e($a['reason']) ?><?= $a['impact'] && $a['vendor'] === 'SolarEdge' ? ' <span class="chip">impact ' . (int) $a['impact'] . '</span>' : '' ?></td>
						<td><?= $a['last_production'] ? e($date($a['last_production'])) : '<span class="muted">none on record</span>' ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</details>

<div class="muted small mt mon-notes">
	<?php if ($hasSolarEdge): ?>
		<p>* Today counts the systems reported live (Enphase and APsystems). SolarEdge production is pulled once each weekday morning; weekend days appear Monday.</p>
		<?php if (!empty($vendors['solaredge']['first_date']) && $vendors['solaredge']['first_date'] > $yearStart): ?>
			<p>** The SolarEdge share of the year total counts from <?= e($date($vendors['solaredge']['first_date'])) ?>, when the tracker started storing it.</p>
		<?php endif; ?>
		<p>SolarEdge data from the <a href="https://www.solaredge.com/" rel="noopener" target="_blank">SolarEdge</a> monitoring platform; <?= number_format($seCreditsLeft) ?> API credits left this cycle.</p>
	<?php endif; ?>
	<p>Data sources: <?php $parts = []; foreach ($vendors as $v) { $parts[] = e($v['label']) . ', ' . e($v['source'][2]); } echo implode('; ', $parts); ?>.</p>
	<p>Flagged: anything the manufacturer reports as an alert or offline, and any system with no production on the last <?= (int) $noProductionDays ?> complete days.</p>
</div>
<?php endif; ?>
