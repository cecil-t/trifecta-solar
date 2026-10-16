<?php
use App\Csrf;

/**
 * @var bool    $installed     the venv's Python exists
 * @var string  $python        path to the venv's Python
 * @var string  $libFolder     folder with SAM's CEC libraries
 * @var ?array  $result        reply from bin/sam.py (check), plus ms and at
 * @var bool    $nlrConfigured NLR_API_KEY and NLR_EMAIL are set
 * @var string  $dataset       NSRDB dataset name
 * @var array   $files         weather files downloaded so far
 * @var array   $point         lat and lon for the weather form
 * @var ?array  $weather       file (SolarResource::forPoint), sam (reply from the weather action), at
 * @var ?string $weatherError
 */
$test = $result['test_run'] ?? null;
$wx = $weather['sam']['weather'] ?? null;
$utc = static fn (?string $t): string => $t ? date('m/d/Y g:i A', (int) strtotime($t . ' UTC')) : '';
$deg = static fn ($v): string => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
?>
<div class="page-head">
	<div>
		<h1>SAM</h1>
		<p class="muted">SAM (System Advisor Model, National Laboratory of the Rockies) on this server, for production estimates. Admins only; nothing here is shown to anyone else.</p>
	</div>
</div>

<?php if ($result && !$result['ok']): ?>
	<div class="flash flash-error"><?= e($result['error']) ?></div>
	<?php if (!empty($result['trace'])): ?>
		<section class="card">
			<h2>Details</h2>
			<pre class="small"><?= e((string) $result['trace']) ?></pre>
		</section>
	<?php endif; ?>
<?php endif; ?>

<?php if ($result && $result['ok']): ?>
	<section class="card">
		<h2>Check passed</h2>
		<p class="muted small"><?= e($result['at']) ?></p>
		<dl class="kv">
			<dt>PySAM</dt><dd><?= e((string) $result['pysam']) ?> on Python <?= e((string) $result['python']) ?></dd>
			<dt>SAM libraries</dt><dd><?= e((string) ($result['library_release'] ?? 'Release unknown (no VERSION file)')) ?></dd>
			<?php foreach ($result['libraries'] as $lib): ?>
				<dt><code><?= e((string) $lib['file']) ?></code></dt>
				<dd><?= $lib['found'] ? e(number_format((int) $lib['rows'])) . ' entries' : 'Missing' ?></dd>
			<?php endforeach; ?>
			<?php if ($test): ?>
				<dt>Test run</dt><dd><?= e((string) $test['model']) ?></dd>
				<dt>Result</dt><dd><?= e(number_format((float) $test['system_kw_dc'], 1)) ?> kW DC, <?= e(number_format((int) $test['annual_kwh'])) ?> kWh a year (made-up weather, so the number means nothing)</dd>
				<dt>SAM run time</dt><dd><?= e(number_format((float) $test['seconds'], 2)) ?> s</dd>
			<?php endif; ?>
			<dt>Whole call</dt><dd><?= e(number_format((int) $result['ms'] / 1000, 2)) ?> s, peak memory <?= (int) $result['peak_memory_mb'] ?> MB</dd>
		</dl>
	</section>
<?php endif; ?>

<section class="card<?= $result ? ' mt' : '' ?>" id="weather">
	<h2>Weather</h2>
	<p class="muted small">NSRDB typical-year weather (<?= e($dataset) ?>, hourly) for a point. Downloaded once per NSRDB grid cell (about 2 km) and kept on the server, so nearby jobs share a file. Then SAM's example system runs on it three ways as a sanity check; those numbers are not an estimate for any real system.</p>
	<?php if (!$nlrConfigured): ?>
		<p class="muted">Not set up. Add <code>NLR_API_KEY</code> and <code>NLR_EMAIL</code> to the server .env file (free key from developer.nlr.gov).</p>
	<?php endif; ?>

	<?php if ($weatherError): ?>
		<div class="flash flash-error"><?= e($weatherError) ?></div>
	<?php endif; ?>

	<form method="post" action="/admin/sam/weather" class="sam-point">
		<?= Csrf::field() ?>
		<label>Latitude <input type="text" name="lat" value="<?= e($point['lat']) ?>" inputmode="decimal" placeholder="40.1600" required></label>
		<label>Longitude <input type="text" name="lon" value="<?= e($point['lon']) ?>" inputmode="decimal" placeholder="-76.3900" required></label>
		<button type="submit" class="btn btn-primary">Get weather and run</button>
	</form>

	<?php if ($weather): ?>
		<?php $f = $weather['file']; $row = $f['row']; $sam = $weather['sam']; ?>
		<h3 class="mt">Result</h3>
		<p class="muted small"><?= e($weather['at']) ?></p>
		<dl class="kv">
			<dt>Weather file</dt><dd><?= $f['cached'] ? 'Already on the server' : 'Downloaded now' ?> (<?= e(number_format((int) $f['ms'] / 1000, 1)) ?> s, <?= e(number_format((int) $row['bytes'] / 1024)) ?> KB)</dd>
			<dt>NSRDB cell</dt><dd><?= e((string) $row['location_id']) ?> at <?= e($deg($row['lat'])) ?>, <?= e($deg($row['lon'])) ?>, elevation <?= e(number_format((float) $row['elevation'])) ?> m</dd>
			<?php if ($wx): ?>
				<dt>Hours</dt><dd><?= e(number_format((int) $wx['hours'])) ?></dd>
				<dt>Sun per year</dt><dd>GHI <?= e(number_format((int) $wx['ghi_kwh_m2'])) ?>, DNI <?= e(number_format((int) $wx['dni_kwh_m2'])) ?>, DHI <?= e(number_format((int) $wx['dhi_kwh_m2'])) ?> kWh/m&sup2;</dd>
				<dt>Temperature</dt><dd>Average <?= e((string) $wx['temp_avg_c']) ?> &deg;C, range <?= e((string) $wx['temp_min_c']) ?> to <?= e((string) $wx['temp_max_c']) ?> &deg;C</dd>
				<dt>Columns</dt><dd class="small"><?= e(implode(', ', array_slice($wx['columns'], 5))) ?></dd>
			<?php endif; ?>
		</dl>
		<?php if (!$sam['ok']): ?>
			<div class="flash flash-error mt"><?= e($sam['error']) ?></div>
			<?php if (!empty($sam['trace'])): ?><pre class="small"><?= e((string) $sam['trace']) ?></pre><?php endif; ?>
		<?php else: ?>
			<div class="table-wrap mt"><table class="table">
				<thead><tr><th>SAM example system</th><th class="num">kWh per kW DC</th><th class="num">Clipping</th><th class="num">Run</th></tr></thead>
				<tbody>
				<?php foreach ($sam['runs'] as $run): ?>
					<tr>
						<td><?= e((string) $run['case']) ?></td>
						<td class="num"><?= e(number_format((int) $run['kwh_per_kw'])) ?></td>
						<td class="num"><?= e(number_format((float) $run['clip_loss_percent'], 1)) ?>%</td>
						<td class="num"><?= e(number_format((float) $run['seconds'], 2)) ?> s</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<p class="muted small"><?= e((string) $sam['system']) ?>. Whole call <?= e(number_format((int) $sam['ms'] / 1000, 2)) ?> s, peak memory <?= (int) $sam['peak_memory_mb'] ?> MB.</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ($files): ?>
		<h3 class="mt">Weather files on the server</h3>
		<div class="table-wrap"><table class="table">
			<thead><tr><th>NSRDB cell</th><th>Point</th><th class="num">Size</th><th>Downloaded</th></tr></thead>
			<tbody>
			<?php foreach ($files as $file): ?>
				<tr>
					<td><?= e((string) $file['location_id']) ?> <span class="muted small"><?= e((string) $file['dataset']) ?></span></td>
					<td><?= e($deg($file['lat'])) ?>, <?= e($deg($file['lon'])) ?></td>
					<td class="num"><?= e(number_format((int) $file['bytes'] / 1024)) ?> KB</td>
					<td><?= e($utc((string) $file['fetched_at'])) ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
	<?php endif; ?>
</section>

<section class="card mt">
	<h2>Setup</h2>
	<dl class="kv">
		<dt>Installed</dt><dd><?= $installed ? 'Yes' : 'No: rebuild the Docker image' ?></dd>
		<dt>Python</dt><dd><code><?= e($python) ?></code></dd>
		<dt>Libraries</dt><dd><code><?= e($libFolder) ?></code></dd>
		<dt>NSRDB key</dt><dd><?= $nlrConfigured ? 'Set' : 'Not set' ?></dd>
	</dl>
	<form method="post" action="/admin/sam" class="mt">
		<?= Csrf::field() ?>
		<button type="submit" class="btn btn-primary">Run check</button>
		<span class="muted small">Starts SAM, reads the library files and runs the detailed PV model once (about a second).</span>
	</form>
</section>
