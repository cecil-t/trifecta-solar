<?php
use App\Production;

/**
 * Build sheet: the production estimate (App\Production). Inside the build sheet form; "Save and
 * run" submits the form to the production action, which saves first.
 *
 * @var array      $o       opportunity
 * @var array      $d       design row
 * @var array      $prodIn  Production::inputs() for the build sheet as shown
 * @var array|null $prod    the saved estimate
 * @var string     $base    the build sheet's address
 * @var callable   $foldBtn
 */
$missing = $prodIn['missing'];
$stale = $prod && $prodIn['key'] !== $d['production_key'];
$kwh = static fn ($v): string => number_format((float) $v);
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$chosen = $prod ? App\Savings::model($d) : null;
$annual = $prod ? (float) $prod[$chosen]['annual_kwh'] : null;
$usage = (int) ($o['annual_usage_kwh'] ?? 0);
?>
<section class="card band" id="sec-production">
	<div class="card-head card-head-plain band-head">
		<h2><?= $foldBtn('sec-production', 'Production estimate', false) ?>Production estimate</h2>
		<span class="small"><?= $prod ? e($kwh($annual)) . ' kWh a year' . ($usage ? ', ' . e(number_format($annual / $usage * 100)) . '% of use' : '') : 'Not run yet' ?></span>
	</div>

	<div id="prod-ready" <?= $missing ? '' : 'hidden' ?>>
		<p class="small"><strong>Before it can run, the build sheet needs:</strong></p>
		<ul class="prod-missing" id="prod-missing">
			<?php foreach ($missing as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
		</ul>
	</div>
	<div class="prod-run">
		<button type="submit" formaction="<?= e($base) ?>/production" class="btn btn-primary" id="prod-run" <?= $missing ? 'disabled' : '' ?>><?= $prod ? 'Save and run again' : 'Save and run production' ?></button>
		<span class="muted small">Saves the build sheet, then runs SAM's detailed model and PVWatts v8 on its module, inverters and arrays at the site's map point (about 10 seconds; a site's first run also downloads its weather).</span>
	</div>
	<p class="prod-running small" id="prod-running" role="status" hidden>Saving and running SAM and PVWatts (usually about 10 seconds).</p>
	<div class="flash flash-warn mt-sm" id="prod-stale" <?= $stale ? '' : 'hidden' ?>>The build sheet has changed since this estimate ran. Run it again before it goes on a proposal.</div>

	<?php if ($prod): ?>
		<?php
		$det = $prod['detailed'];
		$pvw = $prod['pvwatts'];
		$max = max(1, max(array_merge($det['monthly_kwh'], $pvw['monthly_kwh'])));
		$set = $prod['settings'] ?? [];
		?>
		<div class="prod-tiles mt-sm">
			<?php foreach (['detailed' => $det, 'pvwatts' => $pvw] as $k => $r): ?>
				<div class="prod-tile<?= $k === $chosen ? ' is-chosen' : '' ?>">
					<span class="m-label"><?= e(Production::MODELS[$k]) ?><?= $k === $chosen ? ' &middot; on the proposal' : '' ?></span>
					<span class="m-num"><?= e($kwh($r['annual_kwh'])) ?> <small>kWh a year</small></span>
					<span class="muted small"><?= e(number_format((float) $r['annual_kwh'] / max(0.001, (float) $prod['kw_dc']))) ?> kWh per kW DC<?= $usage ? ' &middot; ' . e(number_format((float) $r['annual_kwh'] / $usage * 100)) . '% of the ' . e(number_format($usage)) . ' kWh use' : '' ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<svg class="prod-chart mt-sm" viewBox="0 0 600 170" role="img" aria-label="Production by month, SAM detailed model and PVWatts">
			<?php foreach ($months as $i => $mo): $x = 14 + $i * 48; $h1 = round((float) $det['monthly_kwh'][$i] / $max * 130, 1); $h2 = round((float) $pvw['monthly_kwh'][$i] / $max * 130, 1); ?>
				<rect class="bar-det" x="<?= $x ?>" y="<?= 140 - $h1 ?>" width="18" height="<?= $h1 ?>"><title><?= e($mo . ': SAM detailed ' . $kwh($det['monthly_kwh'][$i]) . ' kWh') ?></title></rect>
				<rect class="bar-pvw" x="<?= $x + 20 ?>" y="<?= 140 - $h2 ?>" width="18" height="<?= $h2 ?>"><title><?= e($mo . ': PVWatts ' . $kwh($pvw['monthly_kwh'][$i]) . ' kWh') ?></title></rect>
				<text x="<?= $x + 19 ?>" y="158" class="bar-label"><?= e($mo) ?></text>
			<?php endforeach; ?>
			<line x1="8" y1="140.5" x2="592" y2="140.5" class="bar-axis"></line>
		</svg>
		<p class="prod-legend small"><span class="key key-det"></span> SAM detailed <span class="key key-pvw"></span> PVWatts v8</p>

		<div class="table-wrap"><table class="table table-tight prod-table">
			<thead><tr><th>kWh</th><?php foreach ($months as $mo): ?><th class="num"><?= e($mo) ?></th><?php endforeach; ?><th class="num">Year</th></tr></thead>
			<tbody>
				<tr><td>SAM detailed</td><?php foreach ($det['monthly_kwh'] as $v): ?><td class="num"><?= e($kwh($v)) ?></td><?php endforeach; ?><td class="num"><strong><?= e($kwh($det['annual_kwh'])) ?></strong></td></tr>
				<tr><td>PVWatts v8</td><?php foreach ($pvw['monthly_kwh'] as $v): ?><td class="num"><?= e($kwh($v)) ?></td><?php endforeach; ?><td class="num"><strong><?= e($kwh($pvw['annual_kwh'])) ?></strong></td></tr>
			</tbody>
		</table></div>

		<dl class="kv mt-sm">
			<dt>Modeled</dt><dd><?= e(number_format((float) $prod['kw_dc'], 2)) ?> kW DC, <?= e(number_format((float) $prod['kw_ac'], 2)) ?> kW AC, DC/AC <?= e(number_format((float) $prod['dc_ac'], 2)) ?>, <?= (int) $prod['modules'] ?> modules<?= ($prod['mlpe'] ?? '') === 'optimizers' ? ' with optimizers' : (($prod['mlpe'] ?? '') === 'micro' ? ' on microinverters' : '') ?>; clipping <?= e((string) $det['clip_loss_percent']) ?>%</dd>
			<dt>Orientations</dt><dd><?= e(implode('; ', array_map(static fn ($g) => $g['modules'] . ' at ' . ($g['mount'] === 'tracker' ? 'single-axis tracker' : round((float) $g['azimuth']) . '° / ' . round((float) $g['tilt'], 1) . '°' . ($g['mount'] !== 'roof' ? ' ' . $g['mount'] : '')) . ((float) $g['shading'] > 0 ? ', ' . $g['shading'] . '% shade' : ''), $prod['groups']))) ?></dd>
			<?php if (!empty($prod['merged']) || !empty($prod['notes'])): ?><dt>Notes</dt><dd class="small"><?= e(implode(' ', array_merge($prod['notes'] ?? [], array_map(static fn ($m) => $m . ' (SAM takes up to 4 orientations).', $prod['merged'] ?? [])))) ?></dd><?php endif; ?>
			<dt>Assumptions</dt><dd class="small">Other losses <?= e((string) ($prod['other_loss_percent'] ?? '')) ?>%<?= ($prod['mlpe'] ?? '') === 'optimizers' ? ', optimizers ' . e((string) ($set['optimizer_loss'] ?? '')) . '%' : '' ?>, inverters from their CEC data<?= (float) ($prod['sam_adjust_percent'] ?? 0) > 0 ? ', extra ' . e((string) $prod['sam_adjust_percent']) . '% on the detailed model' : '' ?>. <?= $prod['snow_modeled'] ? 'Snow modeled.' : 'Snow not modeled (the typical-year weather has no snow data).' ?> Year one, before degradation.</dd>
			<dt>Ran</dt><dd class="small"><?= e(date('m/d/Y g:i A', (int) strtotime((string) $d['production_at']))) ?> &middot; NSRDB <?= e((string) ($prod['weather']['dataset'] ?? '')) ?> cell <?= e((string) ($prod['weather']['location_id'] ?? '')) ?> &middot; PySAM <?= e((string) $prod['pysam']) ?>, SAM library <?= e((string) $prod['library_release']) ?></dd>
		</dl>
	<?php endif; ?>
</section>
