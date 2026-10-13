<?php
use App\Projects;
use App\Municipalities;

// Printable kickoff summary for the designer: core project facts, no phases, steps or dates.
$id = (int) $p['id'];
$fmtNum = static fn ($n, $d = 1) => rtrim(rtrim(number_format((float) $n, $d), '0'), '.');
$notSet = '<span class="muted">Not set</span>';
$pitch = static function (float $deg) use ($fmtNum): string {
	return $deg > 0 && $deg < 90 ? $fmtNum(round(tan(deg2rad($deg)) * 24) / 2) . '/12' : '';
};
$provider = static function (array $slot) use ($p, $notSet): string {
	return match ((string) $p[$slot['by']]) {
		'municipality' => 'Municipality',
		'county' => 'County',
		'third_party' => e($p[str_replace('_id', '_name', $slot['org'])] ?? '') ?: 'Third party (not chosen)',
		default => $notSet,
	};
};
// Yes / No for a question-type task, looked up by name; null when the project doesn't have it
$answer = static function (string $name) use ($tree): ?string {
	foreach ($tree as $tasks) {
		foreach ($tasks as $t) {
			if (strcasecmp($t['name'], $name) === 0) {
				return match ((string) $t['needed']) {
					'1' => 'Yes',
					'0' => 'No',
					default => '<span class="muted">Not answered</span>',
				};
			}
		}
	}
	return null;
};
// Utility work orders: the "Work order #" reference on Interconnection and on each Utility upgrade
$workOrders = [];
foreach ($tree as $tasks) {
	foreach ($tasks as $t) {
		if (stripos((string) $t['ref_label'], 'work order') === 0 && trim((string) $t['reference']) !== '' && (string) $t['needed'] !== '0') {
			$ref = trim((string) $t['reference']);
			$what = strcasecmp($t['name'], 'Interconnection') === 0 ? '' : ucfirst((string) preg_replace('/^utility\s+/i', '', $t['name'])) . ' ';
			$workOrders[] = $what . (preg_match('/^w\.?o\b/i', $ref) ? $ref : 'WO ' . $ref);
		}
	}
}
$siteAddr = trim(implode(', ', array_filter([trim((string) $p['site_street']), trim((string) $p['site_city']), trim(($p['site_state'] ?? '') . ' ' . ($p['site_zip'] ?? ''))])));
$mailAddr = $customer ? trim(implode(', ', array_filter([trim((string) $customer['street']), trim((string) $customer['city']), trim(($customer['state'] ?? '') . ' ' . ($customer['zip'] ?? ''))]))) : '';
$hasCoords = ($p['geo_status'] ?? '') === 'found' && $p['geo_lat'] !== null && $p['geo_lon'] !== null;
$roofPanels = array_sum(array_map(static fn ($r) => (int) $r['panels'], $roofs));
$priceUrl = '/projects/' . $id . '/summary' . ($showPrice ? '' : '?price=1');
?>
<div class="sheet-tools no-print">
	<a href="/projects/<?= $id ?>" class="back">&larr; Back to project</a>
	<div class="sheet-tools-actions">
		<a href="<?= e($priceUrl) ?>" class="btn btn-ghost btn-small"><?= $showPrice ? 'Hide contract price' : 'Include contract price' ?></a>
		<button type="button" class="btn btn-primary btn-small" data-print>Print or save as PDF</button>
	</div>
</div>

<header class="sheet-head">
	<img src="<?= asset('assets/img/logo-horizontal.png') ?>" alt="Trifecta Solar" width="143" height="42">
	<div class="sheet-title">
		<div class="sheet-kicker">Project summary</div>
		<h1><span class="pnum"><?= e($p['project_number']) ?></span> <?= e($p['name']) ?></h1>
	</div>
</header>

<div class="sheet-grid">
	<section class="sheet-sec">
		<h2>Customer</h2>
		<dl class="kv">
			<dt>Customer</dt><dd><?= $customer ? e($customer['name']) : $notSet ?></dd>
			<?php if ($mailAddr !== '' && $mailAddr !== $siteAddr): ?><dt>Mailing</dt><dd><?= e($mailAddr) ?></dd><?php endif; ?>
			<?php if ($customer && $customer['phone']): ?><dt>Phone</dt><dd><?= e($customer['phone']) ?></dd><?php endif; ?>
			<?php if ($customer && $customer['email']): ?><dt>Email</dt><dd><?= e($customer['email']) ?></dd><?php endif; ?>
			<dt>Type</dt><dd><?= e(Projects::CUSTOMER_TYPES[$p['customer_type']] ?? '') ?><?= $p['is_agricultural'] ? ' &middot; Agricultural' : '' ?></dd>
			<?php if (!empty($p['salesperson_name'])): ?><dt>Salesperson</dt><dd><?= e($p['salesperson_name']) ?></dd><?php endif; ?>
		</dl>
		<?php if ($contacts): ?>
			<h3>Contacts</h3>
			<ul class="sheet-contacts">
				<?php foreach ($contacts as $c): ?>
					<li>
						<strong><?= e($c['name']) ?></strong><?= $c['role'] ? ' <span class="muted">' . e($c['role']) . '</span>' : '' ?>
						<?php if ($c['phone'] || $c['email']): ?><div><?= e(implode(' · ', array_filter([(string) $c['phone'], (string) $c['email']]))) ?></div><?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="sheet-sec">
		<h2>Site and jurisdiction</h2>
		<dl class="kv">
			<dt>Site</dt><dd><?= $siteAddr !== '' ? e($siteAddr) : $notSet ?><?php if ($hasCoords): ?><div class="muted small"><?= e(number_format((float) $p['geo_lat'], 5) . ', ' . number_format((float) $p['geo_lon'], 5)) ?></div><?php endif; ?></dd>
			<dt>Municipality</dt><dd><?= $p['municipality_id'] ? e($p['municipality_name']) . '<div class="muted small">' . e($p['county_name'] . ' Co., ' . $p['county_state']) . '</div>' : $notSet ?></dd>
			<?php foreach (Municipalities::SLOTS as $slot): ?>
				<dt><?= e($slot['label']) ?></dt><dd><?= $provider($slot) ?></dd>
			<?php endforeach; ?>
			<dt>Utility</dt><dd><?= $p['utility_name'] ? e($p['utility_name']) : $notSet ?><?php foreach ($workOrders as $wo): ?><div class="small"><?= e($wo) ?></div><?php endforeach; ?></dd>
			<dt>SREC</dt><dd><?= isset(Projects::SREC_PROVIDERS[$p['srec_provider'] ?? '']) ? e(Projects::SREC_PROVIDERS[$p['srec_provider']]) : $notSet ?></dd>
			<dt>Designer</dt><dd><?= $p['designer_name'] ? e($p['designer_name']) : $notSet ?></dd>
			<dt>Installer</dt><dd><?= e($p['installer_name'] ?? 'Trifecta (in-house)') ?></dd>
		</dl>
	</section>

	<section class="sheet-sec">
		<h2>System</h2>
		<div class="metrics">
			<div><span class="m-num"><?= $fmtNum($totals['dc_kw'], 2) ?></span><span class="m-label">kW DC</span></div>
			<div><span class="m-num"><?= $fmtNum($totals['ac_kw'], 2) ?></span><span class="m-label">kW AC</span></div>
			<div><span class="m-num"><?= $totals['ratio'] ? number_format($totals['ratio'], 2) : '-' ?></span><span class="m-label">DC/AC</span></div>
			<?php if ($p['has_batteries'] || $eq['batteries']): ?><div><span class="m-num"><?= $fmtNum($totals['storage_kwh'], 1) ?></span><span class="m-label">kWh storage</span></div><?php endif; ?>
		</div>
		<dl class="kv">
			<dt>Install</dt><dd><?= e(Projects::INSTALL_TYPES[$p['install_type']] ?? '') ?: $notSet ?></dd>
			<dt>Racking</dt><dd><?= $p['racking'] ? e($p['racking']) : $notSet ?></dd>
			<dt>Internet</dt><dd><?= isset(Projects::INVERTER_INTERNET[$p['inverter_internet'] ?? '']) ? e(Projects::INVERTER_INTERNET[$p['inverter_internet']]) : $notSet ?></dd>
			<?php if ($p['est_annual_kwh']): ?><dt>Est. annual</dt><dd><?= number_format((int) $p['est_annual_kwh']) ?> kWh</dd><?php endif; ?>
		</dl>
	</section>

	<section class="sheet-sec">
		<h2>Contract</h2>
		<dl class="kv">
			<?php if ($showPrice): ?>
				<dt>Price</dt><dd><?= $p['contract_price_cents'] !== null ? e(Projects::money((int) $p['contract_price_cents'])) : $notSet ?></dd>
				<?php if ($totals['price_per_watt']): ?><dt>$/W</dt><dd>$<?= number_format($totals['price_per_watt'], 2) ?></dd><?php endif; ?>
			<?php endif; ?>
			<dt>Funding</dt><dd><?= e($funding) ?: $notSet ?><?= $p['funding_note'] ? '<div class="muted small">' . e($p['funding_note']) . '</div>' : '' ?></dd>
			<dt>Tax exempt</dt><dd><?= $p['tax_exempt'] === null ? '<span class="muted">Unknown</span>' : ((int) $p['tax_exempt'] ? 'Yes' : 'No') ?></dd>
			<?php foreach (['SolarInsure' => 'SolarInsure registration', 'Utility rebate' => 'Utility rebate', 'VNM' => 'Virtual net metering'] as $label => $task): ?>
				<?php if (($a = $answer($task)) !== null): ?><dt><?= e($label) ?></dt><dd><?= $a ?></dd><?php endif; ?>
			<?php endforeach; ?>
		</dl>
	</section>
</div>

<section class="sheet-sec sheet-wide sheet-keep">
	<h2>Equipment</h2>
	<?php if ($eq['modules'] || $eq['inverters'] || $eq['batteries']): ?>
		<table class="sheet-table">
			<thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Rating</th><th>Make and model</th><th class="num">Total</th></tr></thead>
			<tbody>
				<?php foreach ($eq['modules'] as $m): ?>
					<tr><td>Modules</td><td class="num"><?= (int) $m['qty'] ?></td><td class="num"><?= $fmtNum($m['watts']) ?> W</td><td><?= e((string) $m['description']) ?></td><td class="num"><?= $fmtNum($m['qty'] * $m['watts'] / 1000, 2) ?> kW DC</td></tr>
				<?php endforeach; ?>
				<?php foreach ($eq['inverters'] as $i): ?>
					<tr><td>Inverters</td><td class="num"><?= (int) $i['qty'] ?></td><td class="num"><?= $fmtNum($i['ac_kw'], 2) ?> kW AC</td><td><?= e((string) $i['description']) ?></td><td class="num"><?= $fmtNum($i['qty'] * $i['ac_kw'], 2) ?> kW AC</td></tr>
				<?php endforeach; ?>
				<?php foreach ($eq['batteries'] as $b): ?>
					<tr><td>Batteries</td><td class="num"><?= (int) $b['qty'] ?></td><td class="num"><?= e(implode(' / ', array_filter([$b['kwh'] ? $fmtNum($b['kwh']) . ' kWh' : '', $b['kw'] ? $fmtNum($b['kw']) . ' kW' : '']))) ?></td><td><?= e((string) $b['description']) ?></td><td class="num"><?= $b['kwh'] ? $fmtNum($b['qty'] * $b['kwh'], 1) . ' kWh' : '' ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else: ?>
		<p class="muted">No equipment entered yet.</p>
	<?php endif; ?>
</section>

<?php if ($roofs || $p['install_type'] === 'roof'): ?>
	<section class="sheet-sec sheet-wide sheet-keep">
		<h2>Roof faces</h2>
		<?php if ($roofs): ?>
			<table class="sheet-table">
				<thead><tr><th>Face</th><th>Material</th><th class="num">Azimuth</th><th class="num">Tilt</th><th class="num">Panels</th></tr></thead>
				<tbody>
					<?php foreach ($roofs as $n => $r): ?>
						<tr>
							<td><?= e($r['name'] ?: 'Roof face ' . ($n + 1)) ?></td>
							<td><?= isset(Projects::ROOF_MATERIALS[$r['material'] ?? '']) ? e(Projects::ROOF_MATERIALS[$r['material']]) : '' ?></td>
							<td class="num"><?= $r['azimuth'] !== null ? (int) $r['azimuth'] . '&deg; ' . Projects::compass((int) $r['azimuth']) : '' ?></td>
							<td class="num"><?= $r['tilt'] !== null ? $fmtNum($r['tilt']) . '&deg;' . (($pt = $pitch((float) $r['tilt'])) !== '' ? ' (' . $pt . ')' : '') : '' ?></td>
							<td class="num"><?= $r['panels'] !== null ? (int) $r['panels'] : '' ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<?php if ($roofPanels): ?>
					<tfoot><tr><td colspan="4">Total</td><td class="num"><?= $roofPanels ?></td></tr></tfoot>
				<?php endif; ?>
			</table>
			<p class="small muted">Azimuth from true north (180&deg; = due south).<?php if ($roofPanels && $roofPanels !== (int) $totals['panels']): ?> <strong class="text-red">Roof faces total <?= $roofPanels ?> panels; the module lines total <?= (int) $totals['panels'] ?>.</strong><?php endif; ?></p>
		<?php else: ?>
			<p class="muted">No roof faces entered yet.</p>
		<?php endif; ?>
	</section>
<?php endif; ?>

<section class="sheet-sec sheet-wide">
	<h2>Notes</h2>
	<?php if (trim((string) $p['notes']) !== ''): ?>
		<div class="sheet-notes"><?= nl2br(e((string) $p['notes']), false) ?></div>
	<?php else: ?>
		<p class="muted">No notes.</p>
	<?php endif; ?>
</section>

<footer class="sheet-foot">Trifecta Solar &middot; Project <?= e($p['project_number']) ?> &middot; Printed <?= e(date('m/d/Y')) ?></footer>
