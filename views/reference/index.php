<?php
use App\Csrf;
use App\ProductionRefs;

/**
 * @var array $jobs         production_refs rows with arrays, proposal_monthly, last_result decoded, and compare (rows, totals)
 * @var bool  $installed    SAM is installed
 * @var bool  $weatherReady NSRDB key set
 */
$kwh = static fn ($v): string => $v === null ? '' : number_format((float) $v);
$pct = static fn (?float $v): string => $v === null ? '' : number_format($v) . '%';
$vendors = ['solaredge' => 'SolarEdge', 'enphase' => 'Enphase', 'apsystems' => 'APsystems', 'growatt' => 'Growatt', 'sma' => 'SMA'];
$utc = static fn (?string $t): string => $t ? date('m/d/Y g:i A', (int) strtotime($t)) : '';
$share = static function (array $t, int $i): ?float {
	return $t[3] > 0 && $t[$i] > 0 ? $t[3] / $t[$i] * 100 : null;
};
?>
<div class="page-head">
	<div>
		<h1>Reference jobs</h1>
		<p class="muted">Built, monitored systems with their signed proposal's estimate. Each run takes the site's NSRDB typical-year weather and runs SAM's detailed model and PVWatts v8 on the job's equipment and arrays with the losses the proposal states, then compares proposal, both models and monitored production month by month. Admins only. Jobs are added with <code>import:reference-jobs</code>.</p>
		<p class="small"><a href="/admin/proposal-apis">Back to Proposal APIs</a></p>
	</div>
</div>

<?php if (!$installed || !$weatherReady): ?>
	<div class="flash flash-error"><?= !$installed ? 'SAM is not installed on this server.' : 'NSRDB is not set up (NLR_API_KEY and NLR_EMAIL).' ?></div>
<?php endif; ?>

<?php if (!$jobs): ?>
	<section class="card"><p class="muted">No reference jobs yet. Add them with <code>bin/console import:reference-jobs &lt;file.json&gt; --dry-run</code>, then without <code>--dry-run</code>.</p></section>
<?php else: ?>
	<section class="card">
		<h2>Summary</h2>
		<p class="muted small">Monitored production as a percent of each estimate, over the last 12 complete months the system ran. The second, gray figure leaves out December to February, where snow on the panels is in none of the estimates.</p>
		<div class="table-wrap"><table class="table table-refs">
			<thead>
			<tr><th>Job</th><th class="num">Months</th><th class="num">Proposal</th><th class="num">SAM</th><th class="num">PVWatts</th></tr>
			</thead>
			<tbody>
			<?php foreach ($jobs as $j): ?>
				<?php $a = $j['compare']['totals']['all']; $n = $j['compare']['totals']['nowinter']; ?>
				<tr>
					<td><a href="#ref-<?= (int) $j['id'] ?>"><?= e($j['name']) ?></a></td>
					<td class="num"><?= (int) $a[4] ?></td>
					<?php for ($i = 0; $i < 3; $i++): ?>
						<td class="num"><?= e($pct($share($a, $i))) ?><br><span class="muted small"><?= e($pct($share($n, $i))) ?></span></td>
					<?php endfor; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<form method="post" action="/admin/reference-jobs/run" class="mt-sm">
			<?= Csrf::field() ?>
			<button type="submit" class="btn btn-primary"<?= $installed ? '' : ' disabled' ?>>Run every job</button>
			<span class="muted small">A few seconds per job, plus about 8 seconds for any site whose weather is not downloaded yet.</span>
		</form>
	</section>

	<?php foreach ($jobs as $j): ?>
		<?php $res = $j['last_result']; $ok = $res && !empty($res['ok']); $c = $j['compare']; ?>
		<section class="card mt" id="ref-<?= (int) $j['id'] ?>">
			<h2><?= e($j['name']) ?></h2>
			<dl class="kv">
				<?php if ($j['project_id']): ?><dt>Project</dt><dd><a href="/projects/<?= (int) $j['project_id'] ?>"><?= e(trim($j['project_number'] . ' ' . $j['project_name'])) ?></a></dd><?php endif; ?>
				<dt>Monitoring</dt><dd><?= $j['monitor_site_id'] ? e(($vendors[$j['vendor']] ?? ucfirst((string) $j['vendor'])) . ': ' . $j['site_name']) : 'Not linked' ?><?= $j['in_service'] ? ', producing since ' . e(fmt_date($j['in_service'])) : '' ?></dd>
				<dt>Equipment</dt><dd><?= e((string) $j['equipment']) ?></dd>
				<dt>SAM library</dt><dd class="small"><?= e($j['module']) ?><br><?= (int) $j['inverter_count'] ?> x <?= e($j['inverter']) ?><?= $j['optimizers'] ? '<br>Module optimizers' : '' ?></dd>
				<dt>Arrays</dt><dd><?= e(implode(', ', array_map(static fn ($a) => $a['modules'] . ' at ' . (float) $a['azimuth'] . '° / ' . (float) $a['tilt'] . '°' . ($a['mount'] === 'ground' ? ' ground' : ''), $j['arrays']))) ?> <span class="muted small">(modules at azimuth / tilt)</span></dd>
				<dt>Proposal</dt><dd><?= e((string) $j['proposal_tool']) ?><?= $j['proposal_annual'] ? ', ' . e($kwh($j['proposal_annual'])) . ' kWh a year' : '' ?></dd>
				<dt>Losses</dt><dd><?= $j['loss_total'] !== null ? 'Total ' . e((string) (float) $j['loss_total']) . '%: inverter ' . e((string) (float) $j['loss_inverter']) . '%, optimizer ' . e((string) (float) $j['loss_optimizer']) . '%, shading ' . e((string) (float) $j['loss_shading']) . '%' : 'Not stated' ?><?= $ok ? ', other ' . e((string) $res['other_loss_percent']) . '%' : '' ?></dd>
				<?php if ($j['notes']): ?><dt>Notes</dt><dd><?= nl2br(e($j['notes'])) ?></dd><?php endif; ?>
			</dl>

			<form method="post" action="/admin/reference-jobs/<?= (int) $j['id'] ?>/run" class="mt-sm">
				<?= Csrf::field() ?>
				<button type="submit" class="btn btn-secondary btn-small"<?= $installed ? '' : ' disabled' ?>><?= $res ? 'Run again' : 'Run' ?></button>
				<?php if ($res): ?><span class="muted small">Last run <?= e($utc($j['last_run_at'])) ?></span><?php endif; ?>
			</form>

			<?php if ($res && !$ok): ?>
				<div class="flash flash-error mt-sm"><?= e((string) $res['error']) ?></div>
			<?php elseif ($ok): ?>
				<dl class="kv mt-sm">
					<dt>System</dt><dd><?= e(number_format((float) $res['kw_dc'], 2)) ?> kW DC, <?= e(number_format((float) $res['kw_ac'], 2)) ?> kW AC, DC/AC <?= e(number_format((float) $res['dc_ac'], 2)) ?></dd>
					<dt>Year one</dt><dd>SAM detailed <?= e($kwh($res['detailed']['annual_kwh'])) ?> kWh (clipping <?= e((string) $res['detailed']['clip_loss_percent']) ?>%), PVWatts v8 <?= e($kwh($res['pvwatts']['annual_kwh'])) ?> kWh<?= $j['proposal_annual'] ? ', proposal ' . e($kwh($j['proposal_annual'])) . ' kWh' : '' ?></dd>
					<dt>Weather</dt><dd><?= e((string) $res['weather']['dataset']) ?>, NSRDB cell <?= e((string) $res['weather']['location_id']) ?><?= $res['snow_modeled'] ? ', snow modeled' : ', no snow data (snow not modeled)' ?></dd>
					<?php if (!empty($res['merged'])): ?><dt>Grouped</dt><dd class="small"><?= e(implode('; ', $res['merged'])) ?> (SAM takes at most 4 orientations)</dd><?php endif; ?>
					<dt>Versions</dt><dd class="small">PySAM <?= e((string) $res['pysam']) ?>, SAM library <?= e((string) $res['library_release']) ?></dd>
				</dl>
			<?php endif; ?>

			<?php if ($c['rows']): ?>
				<div class="table-wrap mt-sm"><table class="table table-tight table-refs">
					<thead>
					<tr><th>Month</th><th class="num">Proposal</th><th class="num">SAM</th><th class="num">PVWatts</th><th class="num">Actual</th><th class="num">% of prop.</th><th class="num">% of SAM</th></tr>
					</thead>
					<tbody>
					<?php foreach ($c['rows'] as $row): ?>
						<tr>
							<td class="nowrap"><?= e($row['label']) ?></td>
							<td class="num"><?= e($kwh($row['proposal'])) ?></td>
							<td class="num"><?= e($kwh($row['detailed'])) ?></td>
							<td class="num"><?= e($kwh($row['pvwatts'])) ?></td>
							<td class="num"><strong><?= e($kwh($row['actual'])) ?></strong></td>
							<td class="num"><?= e($pct(ProductionRefs::pct($row['actual'], $row['proposal'] !== null ? (float) $row['proposal'] : null))) ?></td>
							<td class="num"><?= e($pct(ProductionRefs::pct($row['actual'], $row['detailed']))) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot>
					<?php foreach (['all' => 'Total', 'nowinter' => 'Without Dec to Feb'] as $k => $label): ?>
						<?php $t = $c['totals'][$k]; ?>
						<tr>
							<td><?= e($label) ?></td>
							<td class="num"><?= e($kwh($t[0])) ?></td>
							<td class="num"><?= $ok ? e($kwh($t[1])) : '' ?></td>
							<td class="num"><?= $ok ? e($kwh($t[2])) : '' ?></td>
							<td class="num"><strong><?= e($kwh($t[3])) ?></strong></td>
							<td class="num"><?= e($pct($share($t, 0))) ?></td>
							<td class="num"><?= $ok ? e($pct($share($t, 1))) : '' ?></td>
						</tr>
					<?php endforeach; ?>
					</tfoot>
				</table></div>
			<?php else: ?>
				<p class="muted small mt-sm">No whole months of monitored production to compare yet.</p>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
<?php endif; ?>
