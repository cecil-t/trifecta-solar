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
 * @var bool    $openeiConfigured OPENEI_API_KEY or NLR_API_KEY is set
 * @var array   $sectors       URDB sectors
 * @var ?array  $rateForm      lat, lon, sector, kwh for the rates form
 * @var ?array  $rates         list (UtilityRates::summary rows), ms, at
 * @var ?string $ratesError
 * @var ?array  $bill          summary and sam (reply from the bill action), or error
 * @var bool    $pdfInstalled  WeasyPrint's Python exists
 * @var ?string $pdfError
 * @var bool    $googleConfigured GOOGLE_MAPS_API_KEY is set
 * @var string  $address       the address typed in the Google form
 * @var ?array  $google        geo (GoogleMaps::geocode), building (GoogleMaps::buildingInsights), at
 * @var ?string $googleError
 */
$test = $result['test_run'] ?? null;
$rf = $rateForm ?? ['lat' => $point['lat'], 'lon' => $point['lon'], 'sector' => 'Residential', 'kwh' => '1000'];
$wx = $weather['sam']['weather'] ?? null;
$utc = static fn (?string $t): string => $t ? date('m/d/Y g:i A', (int) strtotime($t . ' UTC')) : '';
$deg = static fn ($v): string => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
?>
<div class="page-head">
	<div>
		<h1>Proposal APIs</h1>
		<p class="muted">The services the proposal system will use, each with a test: Google (address, roof and aerial), NSRDB weather, OpenEI utility rates, SAM (System Advisor Model) for production and bills, and PDF output. Admins only; nothing here is shown to anyone else.</p>
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

<section class="card<?= $result ? ' mt' : '' ?>" id="google">
	<h2>Google</h2>
	<p class="muted small">Address to point with Google Geocoding, then the Solar API's roof model for the building there, and an aerial image. The point found fills the Weather and Utility rates forms below. Nothing is saved.</p>
	<?php if (!$googleConfigured): ?>
		<p class="muted">Not set up. Add <code>GOOGLE_MAPS_API_KEY</code> to the server .env file (Google Cloud project with Geocoding, Solar, Maps Static and Map Tiles enabled).</p>
	<?php endif; ?>

	<?php if ($googleError): ?>
		<div class="flash flash-error"><?= e($googleError) ?></div>
	<?php endif; ?>

	<form method="post" action="/admin/proposal-apis/google#google" class="sam-point">
		<?= Csrf::field() ?>
		<label class="sam-address">Address <input type="text" name="address" value="<?= e($address) ?>" placeholder="123 Main St, Springfield, PA 17000" maxlength="300" required></label>
		<button type="submit" class="btn btn-primary">Look up</button>
	</form>

	<?php if ($google): ?>
		<?php $g = $google['geo']; $bi = $google['building']; ?>
		<h3 class="mt">Result</h3>
		<p class="muted small"><?= e($google['at']) ?></p>
		<dl class="kv">
			<dt>Address</dt><dd><?= e($g['address']) ?><?= $g['partial'] ? ' <span class="muted small">(partial match)</span>' : '' ?></dd>
			<dt>Point</dt><dd><?= e($deg($g['lat'])) ?>, <?= e($deg($g['lon'])) ?> <span class="muted small">(<?= e(strtolower(str_replace('_', ' ', $g['precision']))) ?>, <?= e(number_format($g['ms'] / 1000, 1)) ?> s)</span></dd>
			<dt>Parts</dt><dd><?= e(implode(' | ', array_filter([$g['parts']['street'], $g['parts']['city'], $g['parts']['township'] !== $g['parts']['city'] ? $g['parts']['township'] : '', $g['parts']['county'], $g['parts']['state'], $g['parts']['zip']]))) ?></dd>
		</dl>
		<img class="sam-aerial mt" src="/admin/proposal-apis/aerial?lat=<?= e(rawurlencode($deg($g['lat']))) ?>&amp;lon=<?= e(rawurlencode($deg($g['lon']))) ?>&amp;zoom=20" alt="Aerial image at the point" width="640" height="400">

		<h3 class="mt">Solar API roof model</h3>
		<?php if (!$bi['ok']): ?>
			<div class="flash flash-error"><?= e($bi['error']) ?></div>
		<?php else: ?>
			<?php $b = $bi['building']; ?>
			<dl class="kv">
				<dt>Imagery</dt><dd><?= e(ucfirst(strtolower($b['quality']))) ?> quality, taken <?= e($b['imagery_date']) ?></dd>
				<dt>Building</dt><dd>Centre <?= e($deg($b['center_lat'])) ?>, <?= e($deg($b['center_lon'])) ?><?= $b['roof_m2'] !== null ? ', roof ' . e(number_format($b['roof_m2'] * 10.7639)) . ' sq ft' : '' ?></dd>
				<?php if ($b['max_panels'] !== null): ?><dt>Google's fit</dt><dd>Up to <?= (int) $b['max_panels'] ?> panels of <?= e(number_format((float) $b['panel_watts'])) ?> W<?= $b['max_sun_hours'] !== null ? ', best spot ' . e(number_format($b['max_sun_hours'])) . ' sun hours a year' : '' ?></dd><?php endif; ?>
			</dl>
			<?php if ($b['segments']): ?>
				<div class="table-wrap mt"><table class="table">
					<thead><tr><th>Roof face</th><th class="num">Pitch</th><th class="num">Azimuth</th><th class="num">Area</th><th class="num">Sun hours</th></tr></thead>
					<tbody>
					<?php foreach ($b['segments'] as $i => $s): ?>
						<tr>
							<td><?= $i + 1 ?></td>
							<td class="num"><?= e(number_format($s['pitch'], 1)) ?>&deg; <span class="muted small">(<?= e(number_format($s['pitch_12'], 1)) ?>/12)</span></td>
							<td class="num"><?= e(number_format($s['azimuth'])) ?>&deg;</td>
							<td class="num"><?= e(number_format($s['area_m2'] * 10.7639)) ?> sq ft</td>
							<td class="num"><?= $s['sun_median'] !== null ? e(number_format($s['sun_median'])) : '' ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
				<p class="muted small">Largest faces first. Sun hours are the median for the face, a year of full-sun-equivalent hours in Google's model.</p>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
</section>

<section class="card mt" id="weather">
	<h2>Weather</h2>
	<p class="muted small">NSRDB typical-year weather (<?= e($dataset) ?>, hourly) for a point. Downloaded once per NSRDB grid cell (about 2 km) and kept on the server, so nearby jobs share a file. Then SAM's example system runs on it three ways as a sanity check; those numbers are not an estimate for any real system.</p>
	<?php if (!$nlrConfigured): ?>
		<p class="muted">Not set up. Add <code>NLR_API_KEY</code> and <code>NLR_EMAIL</code> to the server .env file (free key from developer.nlr.gov).</p>
	<?php endif; ?>

	<?php if ($weatherError): ?>
		<div class="flash flash-error"><?= e($weatherError) ?></div>
	<?php endif; ?>

	<form method="post" action="/admin/proposal-apis/weather" class="sam-point">
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
				<thead><tr><th>SAM example system</th><th class="num">Detailed, kWh per kW DC</th><th class="num">PVWatts, kWh per kW DC</th><th class="num">PVWatts vs detailed</th><th class="num">Clipping</th><th class="num">Run</th></tr></thead>
				<tbody>
				<?php foreach ($sam['runs'] as $run): $diff = (float) ($run['pvwatts_vs_detailed_percent'] ?? 0); ?>
					<tr>
						<td><?= e((string) $run['case']) ?></td>
						<td class="num"><?= e(number_format((int) $run['kwh_per_kw'])) ?></td>
						<td class="num"><?= isset($run['pvwatts_kwh_per_kw']) ? e(number_format((int) $run['pvwatts_kwh_per_kw'])) : '' ?></td>
						<td class="num"><?= isset($run['pvwatts_kwh_per_kw']) ? e(($diff > 0 ? '+' : '') . number_format($diff, 1)) . '%' : '' ?></td>
						<td class="num"><?= e(number_format((float) $run['clip_loss_percent'], 1)) ?>%</td>
						<td class="num"><?= e(number_format((float) $run['seconds'] + (float) ($run['pvwatts_seconds'] ?? 0), 2)) ?> s</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<p class="muted small"><?= e((string) $sam['system']) ?>. PVWatts v8 is set up to match it: same size, DC/AC ratio, tilt, azimuth and row spacing, the example's losses combined into <?= e(number_format((float) ($sam['pvwatts_losses'] ?? 0), 1)) ?>%, its inverter's efficiency and a bifacial premium module. Whole call <?= e(number_format((int) $sam['ms'] / 1000, 2)) ?> s, peak memory <?= (int) $sam['peak_memory_mb'] ?> MB.</p>
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

<section class="card mt" id="rates">
	<h2>Utility rates</h2>
	<p class="muted small">Rates in effect at a point from OpenEI's Utility Rate Database (approved rates only). Bill runs one rate through SAM's bill calculator for the same use every month with no solar, as a check that SAM reads the rate right. Nothing is saved.</p>
	<?php if (!$openeiConfigured): ?>
		<p class="muted">Not set up. Add <code>NLR_API_KEY</code> (the same free developer key as NSRDB) or <code>OPENEI_API_KEY</code> to the server .env file.</p>
	<?php endif; ?>

	<?php if ($ratesError): ?>
		<div class="flash flash-error"><?= e($ratesError) ?></div>
	<?php endif; ?>

	<form method="post" action="/admin/proposal-apis/rates#rates" class="sam-point">
		<?= Csrf::field() ?>
		<label>Latitude <input type="text" name="lat" value="<?= e($rf['lat']) ?>" inputmode="decimal" placeholder="40.1600" required></label>
		<label>Longitude <input type="text" name="lon" value="<?= e($rf['lon']) ?>" inputmode="decimal" placeholder="-76.3900" required></label>
		<label>Sector
			<select name="sector">
				<?php foreach ($sectors as $s): ?><option value="<?= e($s) ?>"<?= $rf['sector'] === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
			</select>
		</label>
		<label>kWh a month <input type="text" name="kwh" value="<?= e($rf['kwh']) ?>" inputmode="decimal"></label>
		<button type="submit" class="btn btn-primary">Find rates</button>
	</form>

	<?php if ($bill): ?>
		<?php if (isset($bill['error'])): ?>
			<div class="flash flash-error mt"><?= e($bill['error']) ?></div>
		<?php else: ?>
			<?php $bs = $bill['summary']; $b = $bill['sam']; ?>
			<h3 class="mt">Bill: <?= e($bs['utility']) ?>, <?= e($bs['name']) ?></h3>
			<?php if (!$b['ok']): ?>
				<div class="flash flash-error"><?= e($b['error']) ?></div>
				<?php if (!empty($b['trace'])): ?><pre class="small"><?= e((string) $b['trace']) ?></pre><?php endif; ?>
			<?php else: ?>
				<dl class="kv">
					<dt>Use</dt><dd><?= e(number_format((float) $b['monthly_kwh'])) ?> kWh a month, no solar</dd>
					<dt>Year one bill</dt><dd>$<?= e(number_format((float) $b['annual_bill'], 2)) ?> (energy $<?= e(number_format((float) $b['energy_charges'], 2)) ?>, fixed $<?= e(number_format((float) $b['fixed_charges'], 2)) ?>)</dd>
					<dt>Months</dt><dd class="small"><?= e(implode(', ', array_map(static fn ($v) => '$' . number_format((float) $v, 2), $b['monthly_bills']))) ?></dd>
					<dt>As SAM reads it</dt><dd><?= (int) $b['sam_rate']['periods'] ?> <?= (int) $b['sam_rate']['periods'] === 1 ? 'period' : 'periods' ?>, <?= (int) $b['sam_rate']['tiers'] ?> <?= (int) $b['sam_rate']['tiers'] === 1 ? 'tier' : 'tiers' ?>, <?= e((string) $b['sam_rate']['metering']) ?><?= $b['sam_rate']['monthly_fixed'] !== null ? ', $' . e(number_format((float) $b['sam_rate']['monthly_fixed'], 2)) . ' a month fixed' : '' ?><?= $b['sam_rate']['demand_charges'] ? ', demand charges' : '' ?></dd>
					<?php if (safe_url($bs['uri']) !== null): ?><dt>On OpenEI</dt><dd><a href="<?= e(safe_url($bs['uri'])) ?>" target="_blank" rel="noopener">View the rate</a></dd><?php endif; ?>
				</dl>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ($rates): ?>
		<h3 class="mt"><?= count($rates['list']) ?> <?= count($rates['list']) === 1 ? 'rate' : 'rates' ?> in effect</h3>
		<p class="muted small"><?= e($rates['at']) ?>, <?= e(number_format((int) $rates['ms'] / 1000, 1)) ?> s</p>
		<?php if ($rates['list']): ?>
			<div class="table-wrap"><table class="table">
				<thead><tr><th>Utility</th><th>Rate</th><th>Since</th><th class="num">Energy $/kWh</th><th class="num">Fixed</th><th></th></tr></thead>
				<tbody>
				<?php foreach ($rates['list'] as $rt): ?>
					<tr>
						<td><?= e($rt['utility']) ?></td>
						<td><?= e($rt['name']) ?><?= $rt['is_default'] ? ' <span class="muted small">(default)</span>' : '' ?><?= $rt['periods'] > 1 ? ' <span class="muted small">(' . (int) $rt['periods'] . ' periods)</span>' : '' ?><?= $rt['demand'] ? ' <span class="muted small">(demand)</span>' : '' ?></td>
						<td><?= $rt['start'] ? e(gmdate('m/d/Y', $rt['start'])) : '' ?></td>
						<td class="num"><?php if ($rt['energy_min'] !== null): ?><?= e(number_format($rt['energy_min'], 4)) ?><?= $rt['energy_max'] > $rt['energy_min'] ? ' to ' . e(number_format($rt['energy_max'], 4)) : '' ?><?php endif; ?></td>
						<td class="num"><?= $rt['fixed'] !== null ? '$' . e(number_format($rt['fixed'], 2)) . ' <span class="muted small">' . e($rt['fixed_units']) . '</span>' : '' ?></td>
						<td>
							<form method="post" action="/admin/proposal-apis/rates#rates">
								<?= Csrf::field() ?>
								<input type="hidden" name="lat" value="<?= e($rf['lat']) ?>">
								<input type="hidden" name="lon" value="<?= e($rf['lon']) ?>">
								<input type="hidden" name="sector" value="<?= e($rf['sector']) ?>">
								<input type="hidden" name="kwh" value="<?= e($rf['kwh']) ?>">
								<input type="hidden" name="label" value="<?= e($rt['label']) ?>">
								<button type="submit" class="btn btn-small">Bill</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>
	<?php endif; ?>
</section>

<section class="card mt" id="pdf">
	<h2>PDF</h2>
	<p class="muted small">WeasyPrint turns the app's HTML into PDF (for proposals later). The test page has the logo, a table, an SVG chart, two columns, a page break and page numbers in the footer.</p>
	<?php if ($pdfError): ?>
		<div class="flash flash-error"><?= e($pdfError) ?></div>
	<?php endif; ?>
	<form method="post" action="/admin/proposal-apis/pdf" target="_blank" class="mt">
		<?= Csrf::field() ?>
		<button type="submit" class="btn btn-primary"<?= $pdfInstalled ? '' : ' disabled' ?>>Test PDF</button>
		<span class="muted small"><?= $pdfInstalled ? 'Opens in a new tab.' : 'Not installed: rebuild the Docker image.' ?></span>
	</form>
</section>

<section class="card mt">
	<h2>Setup</h2>
	<dl class="kv">
		<dt>Installed</dt><dd><?= $installed ? 'Yes' : 'No: rebuild the Docker image' ?></dd>
		<dt>Python</dt><dd><code><?= e($python) ?></code></dd>
		<dt>Libraries</dt><dd><code><?= e($libFolder) ?></code></dd>
		<dt>NSRDB key</dt><dd><?= $nlrConfigured ? 'Set' : 'Not set' ?></dd>
		<dt>OpenEI key</dt><dd><?= $openeiConfigured ? 'Set' : 'Not set' ?></dd>
		<dt>Google key</dt><dd><?= $googleConfigured ? 'Set' : 'Not set' ?></dd>
	</dl>
	<form method="post" action="/admin/proposal-apis" class="mt">
		<?= Csrf::field() ?>
		<button type="submit" class="btn btn-primary">Run check</button>
		<span class="muted small">Starts SAM, reads the library files and runs the detailed PV model once (about a second).</span>
	</form>
</section>
