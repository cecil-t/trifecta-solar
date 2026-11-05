<?php
use App\Controllers\ReportController;
use App\Warranties;

/**
 * @var array  $tiles          coverage counts: key => [systems, kW], plus review and estimated counts
 * @var array  $runoff         year => kW whose Trifecta warranty ends that year
 * @var array  $rows           projects in the list (Warranties::fleet() rows plus review, work_cost)
 * @var array  $flags          warranty tickets to check: ticket, project, why
 * @var array  $work           warranty tickets in the last 12 months: tickets, trips, hours, parts, cost
 * @var ?float $perSystemYear  estimated warranty cost per system under Trifecta warranty, last 12 months
 * @var float  $tripRate
 * @var float  $hourRate
 * @var array  $filter         show, program, legacy, q
 * @var int    $total          projects counted (not cancelled)
 */
require __DIR__ . '/_helpers.php';
$kw = static fn (float $n) => number_format($n, 0);
$tile = static fn (string $num, string $label, string $sub = '') => '<div><span class="m-num">' . $num . '</span><span class="m-label">' . e($label) . '</span>'
	. ($sub !== '' ? '<span class="muted small">' . e($sub) . '</span>' : '') . '</div>';
$years = static fn ($y) => rtrim(rtrim(number_format((float) $y, 1), '0'), '.');
$summary = ReportController::WARRANTY_SHOW[$filter['show']] . ($filter['program'] ? ' · ' . ReportController::WARRANTY_PROGRAMS[$filter['program']] : '')
	. ($filter['legacy'] === 'legacy' ? ' · Legacy records' : ($filter['legacy'] === 'tracker' ? ' · Tracker projects' : '')) . ' · as of ' . fmt_date($today);
?>
<img src="<?= asset('assets/img/logo-horizontal.png') ?>" alt="Trifecta Solar" class="print-only report-logo" width="190">
<div class="page-head report-head">
	<div>
		<a href="/reports" class="back no-print">&larr; Reports</a>
		<h1>Warranty<?= help_link('reports') ?></h1>
		<p class="report-summary"><?= e($summary) ?></p>
	</div>
	<button type="button" class="btn btn-secondary no-print" data-print>Print</button>
</div>

<section class="card card-flush report-card-body">
	<div class="metrics report-metrics">
		<?= $tile((string) $tiles['trifecta'][0], 'Under Trifecta warranty', $kw($tiles['trifecta'][1]) . ' kW') ?>
		<?= $tile((string) $tiles['solarinsure'][0], 'Under SolarInsure', $kw($tiles['solarinsure'][1]) . ' kW') ?>
		<?= $tile((string) $tiles['se_extended'][0], 'SolarEdge extended', $kw($tiles['se_extended'][1]) . ' kW') ?>
		<?= $tile((string) $tiles['expiring'][0], 'Trifecta ends in 12 months', $kw($tiles['expiring'][1]) . ' kW') ?>
		<?= $tile((string) $tiles['expired'][0], 'Trifecta warranty ended', $kw($tiles['expired'][1]) . ' kW') ?>
		<?= $tile((string) $tiles['review'], 'Need review', $tiles['estimated'] ? $tiles['estimated'] . ' with estimated starts' : '') ?>
	</div>
	<div class="metrics report-metrics">
		<?= $tile((string) $work['tickets'], 'Warranty tickets, last 12 months', $work['trips'] . ' trips, ' . App\Service::hours($work['hours']) . ' man-hours') ?>
		<?= $tile($money($work['cost']), 'Warranty cost (est.), last 12 months', $work['parts'] ? $money($work['parts']) . ' of it parts' : '') ?>
		<?= $tile($perSystemYear !== null ? $money($perSystemYear) : '-', 'Per system under warranty, a year') ?>
	</div>
	<p class="hint report-hint">Trifecta's 10 years start when final inspection passes; SolarInsure's 30 years start at installation. Warranty cost is an estimate: trips &times; $<?= e(rtrim(rtrim(number_format($tripRate, 2), '0'), '.')) ?> + man-hours &times; $<?= e(rtrim(rtrim(number_format($hourRate, 2), '0'), '.')) ?> + parts (rates from Sales rates). Cancelled projects are left out.</p>

	<?php if ($runoff): $max = max($runoff); $n = count($runoff); $w = 720; $h = 170; $top = 22; $bw = min(56, ($w - 20) / $n - 8); $step = ($w - 20) / $n; ?>
		<h2 class="sub report-sub">Trifecta coverage ending each year (kW DC)</h2>
		<div class="runoff-chart">
			<svg viewBox="0 0 <?= $w ?> <?= $h + $top + 30 ?>" role="img" aria-label="kW whose Trifecta warranty ends each year">
				<?php $i = 0; foreach ($runoff as $year => $v): $bh = $max > 0 ? round($v / $max * $h, 1) : 0; $x = round(10 + $i * $step + ($step - $bw) / 2, 1); $i++; ?>
					<rect class="runoff-bar" x="<?= $x ?>" y="<?= round($h + $top - $bh, 1) ?>" width="<?= round($bw, 1) ?>" height="<?= $bh ?>"><title><?= (int) $year ?>: <?= $kw($v) ?> kW</title></rect>
					<?php if ($v > 0): ?><text class="runoff-val" x="<?= round($x + $bw / 2, 1) ?>" y="<?= round($h + $top - 6 - $bh, 1) ?>" text-anchor="middle"><?= $kw($v) ?></text><?php endif; ?>
					<text class="runoff-year" x="<?= round($x + $bw / 2, 1) ?>" y="<?= $h + $top + 20 ?>" text-anchor="middle"><?= (int) $year ?></text>
				<?php endforeach; ?>
			</svg>
		</div>
	<?php endif; ?>
</section>

<?php if ($flags): ?>
	<section class="card card-flush report-card-body mt">
		<div class="phase-title"><h2>Warranty tickets to check</h2><span class="muted small">Last 12 months</span></div>
		<table class="table table-warranty-flags">
			<thead><tr><th>Ticket</th><th>Opened</th><th>Project</th><th>Why</th></tr></thead>
			<tbody>
			<?php foreach ($flags as $f): ?>
				<tr>
					<td><a href="/service/<?= (int) $f['ticket']['id'] ?>"><strong><?= e($f['ticket']['ticket_number']) ?></strong></a> <span class="muted small"><?= e((string) $f['ticket']['customer_name']) ?></span></td>
					<td class="nowrap"><?= e(fmt_date($f['ticket']['opened_on'])) ?></td>
					<td><?= $f['project'] ? '<a href="/projects/' . (int) $f['project']['id'] . '">' . e($f['project']['project_number'] . ' ' . $f['project']['name']) . '</a>' : '<span class="muted">None linked</span>' ?></td>
					<td class="small"><?= e($f['why']) ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endif; ?>

<form class="filters report-filters no-print mt" method="get" action="/reports/warranty">
	<label class="rf-wide">Show
		<select name="show" data-autosubmit>
			<?php foreach (ReportController::WARRANTY_SHOW as $k => $l): ?><option value="<?= $k ?>" <?= $filter['show'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
		</select>
	</label>
	<label class="rf-wide">Program
		<select name="program" data-autosubmit>
			<?php foreach (ReportController::WARRANTY_PROGRAMS as $k => $l): ?><option value="<?= $k ?>" <?= $filter['program'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
		</select>
	</label>
	<label>Records
		<select name="legacy" data-autosubmit>
			<option value="">All</option>
			<option value="tracker" <?= $filter['legacy'] === 'tracker' ? 'selected' : '' ?>>Tracker projects</option>
			<option value="legacy" <?= $filter['legacy'] === 'legacy' ? 'selected' : '' ?>>Legacy records</option>
		</select>
	</label>
	<label class="rf-wide">Search <input type="search" name="q" value="<?= e($filter['q']) ?>" placeholder="Number, name, customer, city"></label>
	<button class="btn btn-primary btn-small">Apply</button>
</form>

<section class="card card-flush report-card-body">
	<div class="phase-title"><h2><?= count($rows) ?> project<?= count($rows) === 1 ? '' : 's' ?></h2><span class="muted small">of <?= $total ?>, soonest Trifecta end first. Click a row's arrow for every warranty.</span></div>
	<table class="table table-warranty-report">
		<thead><tr><th></th><th>Project</th><th class="num">kW DC</th><th>Program</th><th>Trifecta start</th><th>Trifecta ends</th><th>Left</th><th>Next to end</th></tr></thead>
		<tbody>
		<?php if (!$rows): ?><tr><td colspan="8" class="empty">No projects match.</td></tr><?php endif; ?>
		<?php foreach ($rows as $p): $t = $p['trifecta']; ?>
			<tr>
				<td class="nowrap"><button type="button" class="btn btn-ghost btn-small row-toggle" data-toggle-next-row aria-label="Show every warranty for <?= e($p['project_number']) ?>">&#9662;</button></td>
				<td><a href="/projects/<?= (int) $p['id'] ?>#warranties"><strong><?= e($p['project_number']) ?></strong> <?= e($p['name']) ?></a>
					<?php if ($p['is_legacy']): ?> <span class="chip">Legacy</span><?php endif; ?>
					<?php foreach ($p['review'] as $why): ?><div><span class="chip chip-orange"><?= e($why) ?></span></div><?php endforeach; ?></td>
				<td class="num"><?= $p['kw'] ? e(number_format($p['kw'], 1)) : '' ?></td>
				<td><?= e(Warranties::PROGRAMS[$p['program']['code']]) ?><?= $p['program']['assumed'] ? '<div class="muted small" title="' . e($p['program']['how']) . '">assumed</div>' : '' ?></td>
				<td class="nowrap"><?php if ($t && $t['start']): ?><?= e(Warranties::month($t['start'], $t['start_tag'])) ?><div class="muted small"><?= e(strtolower(Warranties::TAGS[$t['start_tag']])) ?></div><?php elseif ($t): ?><span class="muted small"><?= e($t['start_how']) ?></span><?php endif; ?></td>
				<td class="nowrap"><?= $t ? e(Warranties::month($t['end'], $t['start_tag'])) : '' ?></td>
				<td class="nowrap"><?php if ($t && $t['status'] === 'active'): ?><meter min="0" max="120" low="12" high="36" optimum="120" value="<?= (int) $t['months_left'] ?>" title="<?= (int) $t['months_left'] ?> months"></meter> <?= e(Warranties::left($t['months_left'])) ?><?php elseif ($t && $t['status'] === 'expired'): ?><span class="chip">Ended</span><?php endif; ?></td>
				<td class="small"><?= $p['next'] ? e($p['next']['label']) . '<div class="muted">' . e(Warranties::month($p['next']['end'], $p['next']['start_tag'])) . '</div>' : '' ?></td>
			</tr>
			<tr class="visit-edit-row" hidden>
				<td colspan="8">
					<table class="table table-compact table-warranties">
						<thead><tr><th>Warranty</th><th>Covers</th><th class="num">Years</th><th>Starts</th><th>Ends</th><th>Left</th><th>Reference</th></tr></thead>
						<tbody>
						<?php if (!$p['lines']): ?><tr><td colspan="7" class="empty">No warranties.</td></tr><?php endif; ?>
						<?php foreach ($p['lines'] as $l): ?>
							<tr class="w-<?= e($l['status']) ?>">
								<td><strong><?= e($l['label']) ?></strong><?= $l['provider'] && $l['provider'] !== $l['label'] ? ' <span class="muted">' . e($l['provider']) . '</span>' : '' ?></td>
								<td><?= e((string) $l['covers']) ?></td>
								<td class="num"><?= e($years($l['years'])) ?><?= $l['assumed'] ? '*' : '' ?></td>
								<td class="nowrap"><?= $l['start'] ? e(Warranties::month($l['start'], $l['start_tag'])) . ' <span class="muted">' . e(Warranties::lc($l['start_how'])) . '</span>' : '<span class="muted">' . e($l['start_how']) . '</span>' ?></td>
								<td class="nowrap"><?= e(Warranties::month($l['end'], $l['start_tag'])) ?></td>
								<td class="nowrap"><?= $l['status'] === 'expired' ? 'Ended' : e(Warranties::left($l['months_left'])) ?></td>
								<td><?= e((string) $l['reference']) ?><?= $l['flag'] ? ' <span class="chip chip-orange">' . e($l['flag']) . '</span>' : '' ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ($p['work_cost']): ?><p class="small muted">Warranty work in the last 12 months: <?= e($money($p['work_cost'])) ?> (est.)</p><?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>
