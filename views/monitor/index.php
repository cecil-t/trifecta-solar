<?php
/**
 * @var array  $fleet       systems, kw, kw_ac, yesterday, yesterday_missing (vendor => true), week, d30, d30_partial (vendor => [from, left_out]), year (Wh)
 * @var array  $months      Wh by month (Y-m) for the last 13 months, this month last
 * @var array  $sePace      SolarEdge pacing from MonitorCollector::sePace
 * @var array  $monthsFrom  brand label => first month with data, for brands that start after the first month
 * @var array  $vendors     per manufacturer: label, source [label, kind, detail], systems, kw, kw_ac, yesterday, week, month, year, attention, pending, first_date, run, d30, yield_day, yield_days, yield_ac
 * @var bool   $kiosk       office-screen view opened with a display key: no links into the tracker
 * @var array  $attention   vendor, name, reason, offline, impact, last_production, project_id, site_id
 * @var array  $tickets     open service ticket per monitor site id: id, ticket_number
 * @var string $yearStart
 * @var int    $seCreditsLeft
 * @var int    $noProductionDays
 */
$energy = static fn (?float $wh): string => fmt_energy($wh);
$power = static fn (float $kw): string => $kw >= 1000 ? number_format($kw / 1000, 2) . ' MW' : number_format($kw, $kw >= 100 ? 0 : 1) . ' kW';
$date = static fn (?string $ymd): string => $ymd ? date('m/d/Y', strtotime($ymd)) : '';
$when = static fn (?string $utc): string => $utc ? fmt_dt($utc, fmt_dt($utc, 'Y-m-d') === date('Y-m-d') ? 'g:i A' : 'm/d g:i A') : 'never';
$bar = static function (float $pct, string $class): string {
	$w = max(0.0, min(100.0, $pct));
	return '<svg class="mon-bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">'
		. '<rect class="mon-bar-track" x="0" y="0" width="100" height="10" rx="2"/>'
		. '<rect class="' . e($class) . '" x="0" y="0" width="' . round($w, 2) . '" height="10" rx="2"/></svg>';
};
// Tile value: the number at full size, its unit one step smaller (escaped here; echo as is).
$tile = static fn (string $v): string => stat_value($v);
$fleetKw = max($fleet['kw'] + $fleet['kw_ac'], 0.001); // for shares only: DC, plus AC where DC is unknown
$maxYield = max([0.001, ...array_values(array_map(static fn ($v) => (float) ($v['yield_day'] ?? 0), $vendors))]);
$attentionCount = count($attention);
$latestOk = null;
foreach ($vendors as $v) {
	if ($v['run']['last_ok'] && ($latestOk === null || $v['run']['last_ok'] > $latestOk)) {
		$latestOk = $v['run']['last_ok'];
	}
}
$hasSolarEdge = isset($vendors['solaredge']);
$missingYesterday = array_keys($fleet['yesterday_missing']);
$partial30 = $fleet['d30_partial'];
// Brands whose year total is built from stored history (no year figure from the vendor) and whose
// history starts after January 1: their share of the year is partial until monitor:backfill runs.
$monthMax = max([1.0, ...array_values($months)]);
$thisMonth = date('Y-m');
$mwh = static fn (float $wh): string => number_format($wh / 1e6, $wh >= 1e8 ? 0 : 1);
$partialYear = [];
foreach (['solaredge', 'growatt'] as $k) {
	if (!empty($vendors[$k]['first_date']) && $vendors[$k]['first_date'] > $yearStart) {
		$partialYear[$vendors[$k]['label']] = $vendors[$k]['first_date'];
	}
}
?>
<h1 class="visually-hidden">Monitoring</h1>
<?= empty($kiosk) ? help_link('monitor', true) : '' ?>

<?php if (!$vendors): ?>
	<section class="card empty-state">
		<h2>No monitoring data yet</h2>
		<p class="muted">The collector (<code>monitor:poll</code>) fills this page once it runs on the server.</p>
	</section>
<?php else: ?>

<div class="stat-row mon-stats">
	<a class="stat <?= $attentionCount ? 'mon-stat-alert' : 'mon-stat-ok' ?>" href="#attention"><span class="stat-num mon-num-plain"><?= (int) $fleet['systems'] ?> <span class="stat-unit">systems</span></span><span class="stat-label <?= $attentionCount ? 'mon-issues' : 'mon-no-issues' ?>"><?= $attentionCount ?> <?= $attentionCount === 1 ? 'issue' : 'issues' ?></span></a>
	<div class="stat"><span class="stat-num"><?= $tile($power($fleet['kw'] + $fleet['kw_ac'])) ?></span><span class="stat-label">Installed<?= $fleet['kw_ac'] > 0 ? ' <span class="nowrap">(incl. ' . e($power($fleet['kw_ac'])) . ' AC)</span>' : ' (DC)' ?></span></div>
	<div class="stat stat-orange"><span class="stat-num"><?= $tile($energy($fleet['yesterday'])) ?></span><span class="stat-label">Yesterday<?= $missingYesterday ? ' <span class="muted small">(partial)</span>' : '' ?></span></div>
	<div class="stat"><span class="stat-num"><?= $tile($energy($fleet['week'])) ?></span><span class="stat-label">Last 7 days</span></div>
	<div class="stat"><span class="stat-num"><?= $tile($energy($fleet['d30'])) ?></span><span class="stat-label">Last 30 days<?= $partial30 ? ' &dagger;' : '' ?></span></div>
	<div class="stat"><span class="stat-num"><?= $tile($energy($fleet['year'])) ?></span><span class="stat-label"><?= e(date('Y')) ?><?= $partialYear ? ' **' : '' ?></span></div>
</div>

<section class="card mt">
	<h2>By manufacturer <span class="muted small">share of installed capacity</span></h2>
	<div class="mon-share" role="img" aria-label="Share of installed capacity by manufacturer">
		<svg class="mon-share-bar" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true">
			<?php $x = 0.0; foreach ($vendors as $v): $w = ($v['kw'] + $v['kw_ac']) / $fleetKw * 100; ?>
				<rect class="mon-fill-<?= e($v['key']) ?>" x="<?= round($x, 2) ?>" y="0" width="<?= round($w, 2) ?>" height="6"/>
			<?php $x += $w; endforeach; ?>
		</svg>
		<ul class="mon-legend">
			<?php foreach ($vendors as $v): ?>
				<li><span class="mon-dot mon-dot-<?= e($v['key']) ?>"></span><?= e($v['label']) ?> <strong><?= number_format(($v['kw'] + $v['kw_ac']) / $fleetKw * 100, 0) ?>%</strong> <span class="muted">(<?= (int) $v['systems'] ?> <?= $v['systems'] === 1 ? 'system' : 'systems' ?>)</span></li>
			<?php endforeach; ?>
		</ul>
	</div>

	<div class="mon-scroll">
		<table class="table mon-table">
			<thead>
				<tr>
					<th>Manufacturer</th>
					<th class="num">Systems</th>
					<th class="num">Installed</th>
					<th class="num">Yesterday</th>
					<th class="num">7 days</th>
					<th class="num">30 days</th>
					<th class="mon-th-plain" title="Average daily production per kW installed, over the last 30 days">kWh/kW/day</th>
					<th class="num"><?= e(date('Y')) ?></th>
					<th class="num">Issues</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($vendors as $v): ?>
				<tr>
					<td><span class="mon-dot mon-dot-<?= e($v['key']) ?>"></span><strong><?= e($v['label']) ?></strong> <span class="chip mon-src-<?= e($v['source'][1]) ?>" title="<?= e($v['source'][2]) ?>"><?= e($v['source'][0]) ?></span>
						<div class="muted small mon-upd">Updated <?= e($when($v['run']['last_ok'])) ?></div><?php if ($v['run']['failed']): ?><div class="mon-fail"><?= e($v['run']['failed']) ?></div><?php endif; ?></td>
					<td class="num"><?= (int) $v['systems'] ?><?php if ($v['pending']): ?><div class="muted small"><?= (int) $v['pending'] ?> pending</div><?php endif; ?></td>
					<td class="num"><?= $v['kw'] > 0 ? e($power($v['kw'])) : ($v['kw_ac'] > 0 ? e($power($v['kw_ac'])) . ' AC' : '<span class="muted">-</span>') ?></td>
					<td class="num"><?= $v['yesterday'] === null ? '<span class="muted">-</span>' : e($energy($v['yesterday'])) ?></td>
					<td class="num"><?= e($energy($v['week'])) ?></td>
					<td class="num"><?= $v['d30_short'] ? '<span class="muted">-</span>' : e($energy($v['d30'])) ?></td>
					<td class="mon-barcell"><?php if ($v['yield_day'] !== null): ?><?= $bar($v['yield_day'] / $maxYield * 100, 'mon-fill-' . $v['key']) ?><span title="<?= $v['yield_from_week'] ? 'From the brand&#39;s own last 7 days total' : 'Average of ' . (int) $v['yield_days'] . ' days' ?>"><?= number_format($v['yield_day'], 2) ?><?= $v['yield_ac'] ? ' <span class="muted small">AC</span>' : '' ?></span><?php else: ?><span class="muted">-</span><?php endif; ?></td>
					<td class="num"><?= e($energy($v['year'])) ?></td>
					<td class="num"><?= $v['attention'] ? '<a href="#attention" class="mon-attn">' . (int) $v['attention'] . '</a>' : '<span class="muted">0</span>' ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<p class="muted small mon-updated">Last data <?= e($when($latestOk)) ?>; this page auto-reloads 90 seconds after each quarter-hour pull.</p>

<section class="card">
	<h2>Last 13 months <span class="muted small">fleet production in MWh; <?= e(date('M')) ?> so far<?= $monthsFrom ? ' &Dagger;' : '' ?></span></h2>
	<ol class="mon-months">
		<?php foreach ($months as $ym => $wh): $pct = $wh > 0 ? max(1, (int) round($wh / $monthMax * 100)) : 0; $inside = $pct >= 16; $now = $ym === $thisMonth; $val = $wh > 0 ? $mwh($wh) : '-'; ?>
			<li class="mon-month<?= $now ? ' mon-month-now' : '' ?>" title="<?= e(date('F Y', strtotime($ym . '-01')) . ': ' . ($wh > 0 ? $val . ' MWh' : 'no data') . ($now ? ' so far' : '')) ?>">
				<div class="mon-month-track">
					<?php if (!$inside): ?><span class="mon-month-val"><?= e($val) ?></span><?php endif; ?>
					<?php if ($pct > 0): ?><div class="mon-month-bar mon-h-<?= $pct ?>"><?php if ($inside): ?><span class="mon-month-val"><?= e($val) ?></span><?php endif; ?></div><?php endif; ?>
				</div>
				<span class="mon-month-name"><?= e(date('M', strtotime($ym . '-01'))) ?><?php if (substr($ym, 5) === '01' || $ym === array_key_first($months)): ?> <span class="mon-month-year"><?= e(substr($ym, 0, 4)) ?></span><?php endif; ?></span>
			</li>
		<?php endforeach; ?>
	</ol>
</section>

<details class="card mt mon-attention" id="attention"<?= $attentionCount && $attentionCount <= 8 ? ' open' : '' ?>>
	<summary><h2>Issues <span class="mon-count<?= $attentionCount ? ' mon-count-alert' : '' ?>"><?= $attentionCount ?></span></h2></summary>
	<?php if (!$attention): ?>
		<p class="muted">Nothing flagged: no manufacturer alerts and every system produced in the last <?= (int) $noProductionDays ?> days.</p>
	<?php else: ?>
		<div class="mon-scroll">
			<table class="table mon-table">
				<thead><tr><th>Manufacturer</th><th>System</th><th>Why</th><th>Last production</th><?php if (!$kiosk): ?><th>Service</th><?php endif; ?></tr></thead>
				<tbody>
				<?php foreach ($attention as $a): ?>
					<tr class="<?= $a['offline'] ? 'mon-row-offline' : '' ?>">
						<td><?= e($a['vendor']) ?></td>
						<td><?php if ($a['project_id'] && !$kiosk): ?><a href="/projects/<?= (int) $a['project_id'] ?>"><?= e($a['name']) ?></a><?php else: ?><?= e($a['name']) ?><?php endif; ?></td>
						<td class="mon-why"><?= e($a['reason']) ?><?= $a['impact'] && $a['vendor'] === 'SolarEdge' ? ' <span class="chip">impact ' . (int) $a['impact'] . '</span>' : '' ?></td>
						<td><?= $a['last_production'] ? e($date($a['last_production'])) : '<span class="muted">none on record</span>' ?></td>
						<?php if (!$kiosk): $tk = $tickets[$a['site_id']] ?? null; ?>
							<td class="mon-ticket"><?php if ($tk): ?><a href="/service/<?= (int) $tk['id'] ?>" class="chip" title="Open service ticket for this system"><?= e($tk['ticket_number']) ?></a><?php else: ?><a href="/service/new?monitor=<?= (int) $a['site_id'] ?>" class="btn btn-small btn-ghost">Open ticket</a><?php endif; ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</details>

<div class="muted small mt mon-notes">
	<?php if ($missingYesterday): ?><p>Yesterday is partial: it does not include <?= e(count($missingYesterday) > 1 ? implode(', ', array_slice($missingYesterday, 0, -1)) . ' or ' . end($missingYesterday) : $missingYesterday[0]) ?> yet<?= in_array('SolarEdge', $missingYesterday, true) ? ' (SolarEdge days arrive with its next pull)' : '' ?>.</p><?php endif; ?>
	<?php if ($partial30): ?><p>&dagger; The 30-day figures <?php $parts = []; foreach ($partial30 as $name => $p) { $parts[] = $p['left_out'] ? 'leave out ' . e($name) . ' (under a week of daily history so far)' : 'count ' . e($name) . ' from ' . e($date($p['from'])); } echo implode('; ', $parts); ?>. They fill out as the days pass or a backfill runs.</p><?php endif; ?>
	<?php if ($monthsFrom): ?><p>&Dagger; The 13-month chart counts <?php $parts = []; foreach ($monthsFrom as $name => $from) { $parts[] = e($name) . ' from ' . e(date('M Y', strtotime($from . '-01'))); } echo implode(' and ', $parts); ?>; monitor:backfill --months fills in the earlier months.</p><?php endif; ?>
	<?php if ($partialYear): ?><p>** The year total counts <?php $parts = []; foreach ($partialYear as $name => $from) { $parts[] = e($name) . ' from ' . e($date($from)); } echo implode(' and ', $parts); ?>, when the tracker started storing them; the one-time backfill fills in the earlier months.</p><?php endif; ?>
	<?php if ($hasSolarEdge): ?>
		<p>Enphase, Growatt and SMA are read every 30 minutes and APsystems four times each weekday; SolarEdge is pulled on weekdays from 8 AM as often as this cycle's credits allow<?= $sePace['gap'] > 1 && is_finite($sePace['gap']) ? ' (now about every ' . (int) ceil($sePace['gap']) . ' weekdays)' : ($sePace['pulls'] < 1 ? ' (none left this cycle)' : ' (every weekday)') ?>; each pull fills in every day since the last one, so its weekend days arrive Monday morning.</p>
		<p>SolarEdge data from the <a href="https://www.solaredge.com/" rel="noopener" target="_blank">SolarEdge</a> monitoring platform; <?= number_format($seCreditsLeft) ?> API credits left this cycle.</p>
	<?php endif; ?>
	<p>Data sources: <?php $parts = []; foreach ($vendors as $v) { $parts[] = e($v['label']) . ', ' . e($v['source'][2]); } echo implode('; ', $parts); ?>.</p>
	<?php if ($fleet['kw_ac'] > 0): ?><p>Sizes are DC array sizes as each manufacturer reports them, except where marked AC: Growatt reports only its inverters' AC rating, so its size and kWh/kW/day are per kW AC, and the Installed total counts that AC rating in place of its DC size.</p><?php endif; ?>
	<p>Flagged: anything the manufacturer reports as an alert or offline, and any system with no production on the last <?= (int) $noProductionDays ?> complete days.</p>
</div>
<?php endif; ?>
