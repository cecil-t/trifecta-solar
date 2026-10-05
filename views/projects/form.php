<?php
use App\Csrf;
use App\Projects;
use App\Municipalities;

$v = static fn (string $k, $d = '') => $p[$k] ?? $d;
$sel = static fn ($a, $b) => (string) $a === (string) $b && (string) $a !== '' ? 'selected' : '';
$price = $p['contract_price'] ?? (isset($p['contract_price_cents']) && $p['contract_price_cents'] !== null ? number_format($p['contract_price_cents'] / 100, 2, '.', '') : '');
$fundingIds = array_map('intval', $p['funding'] ?? $p['funding_ids'] ?? []);
$eqRows = static function (string $key) use ($p): array {
	$rows = array_values((array) ($p[$key] ?? []));
	return $rows ?: [[]];
};
$isNew = $id === null;
// Saved tilts show as plain degrees (26.6); text typed before a failed save shows as typed (6/12)
$tiltVal = static fn ($t) => is_numeric($t) ? rtrim(rtrim(number_format((float) $t, 1, '.', ''), '0'), '.') : (string) $t;
?>
<div class="page-head">
	<div>
		<a href="<?= $isNew ? '/projects' : '/projects/' . (int) $id ?>" class="back">&larr; <?= $isNew ? 'Projects' : e($p['project_number'] . ' ' . $p['name']) ?></a>
		<h1><?= $isNew ? 'New project' : 'Edit project' ?></h1>
	</div>
</div>

<form method="post" action="<?= $isNew ? '/projects' : '/projects/' . (int) $id ?>" class="project-form">
	<?= Csrf::field() ?>

	<section class="card">
		<h2>Status</h2>
		<div class="grid-form">
			<label class="span-2">Status note
				<input type="text" name="status_note" value="<?= e($v('status_note')) ?>">
				<small class="hint">Where it stands. Shown under the name on the project list.</small>
			</label>
			<label>Hold / cancel
				<select name="hold_state" data-reveal-any="on_hold,cancelled" data-target="#hold-reason">
					<option value="">Active</option>
					<option value="on_hold" <?= $sel($v('hold_state'), 'on_hold') ?>>On hold</option>
					<option value="cancelled" <?= $sel($v('hold_state'), 'cancelled') ?>>Cancelled</option>
				</select>
			</label>
			<div class="checkfield">
				<span class="field-label">Completed</span>
				<label class="boxwrap"><input type="checkbox" name="archived" value="1" <?= $v('archived_at') || $v('archived') ? 'checked' : '' ?>> Mark completed</label>
				<small class="hint">Moves it to the Completed tab, even with open items. Otherwise it gets there on its own after PTO once Closeout and Payments are all resolved.</small>
			</div>
			<label id="hold-reason" class="span-2 reveal" hidden>Reason
				<input type="text" name="hold_reason" value="<?= e($v('hold_reason')) ?>" placeholder="e.g. Waiting on Mechatron, zoning hearing">
			</label>
		</div>
	</section>

	<section class="card">
		<h2>Basics</h2>
		<div class="grid-form">
			<label>Project #
				<input type="text" name="project_number" value="<?= e($v('project_number')) ?>" required>
			</label>
			<label class="span-2">Project name
				<input type="text" name="name" value="<?= e($v('name')) ?>" required>
				<small class="hint">Must be unique, e.g. "Maple Hollow Farms LLC" or "Riverside Warehouse".</small>
			</label>
			<?php if ($isNew): ?>
				<label>Contract signed
					<input type="date" name="contract_signed" value="<?= e($v('contract_signed')) ?>">
				</label>
			<?php else: ?>
				<label>OpenSolar quote #
					<input type="text" name="quote_number" value="<?= e($v('quote_number')) ?>">
				</label>
			<?php endif; ?>
			<label>Salesperson
				<select name="salesperson_id">
					<option value="">Choose</option>
					<?php foreach ($users as $u): ?>
						<option value="<?= (int) $u['id'] ?>" <?= $sel($v('salesperson_id'), $u['id']) ?>><?= e($u['name']) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>Customer type
				<select name="customer_type">
					<option value="">Choose</option>
					<?php foreach (Projects::CUSTOMER_TYPES as $k => $label): ?>
						<option value="<?= $k ?>" <?= $sel($v('customer_type'), $k) ?>><?= e($label) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<div class="checkfield">
				<span class="field-label">Agricultural</span>
				<label class="boxwrap"><input type="checkbox" name="is_agricultural" value="1" <?= $v('is_agricultural') ? 'checked' : '' ?>> Farm / ag</label>
			</div>
			<?php if ($isNew): ?>
				<label>OpenSolar quote #
					<input type="text" name="quote_number" value="<?= e($v('quote_number')) ?>">
				</label>
			<?php else: ?>
				<div></div>
			<?php endif; ?>
			<label class="span-all">Google Drive project folder
				<input type="url" name="drive_url" value="<?= e($v('drive_url')) ?>" placeholder="https://drive.google.com/drive/folders/...">
			</label>
		</div>
	</section>

	<section class="card">
		<h2>Customer</h2>
		<div class="grid-form">
			<label class="span-2">Customer
				<select name="customer_id" data-reveal="new" data-target="#new-customer">
					<option value="">Choose</option>
					<option value="new" <?= $sel($v('customer_id'), 'new') ?>>+ New customer</option>
					<?php foreach ($customers as $c): ?>
						<option value="<?= (int) $c['id'] ?>" <?= $sel($v('customer_id'), $c['id']) ?>><?= e($c['name']) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>
		<div id="new-customer" class="grid-form reveal" hidden>
			<label class="span-2">New customer name <input type="text" name="new_customer_name" value="<?= e($v('new_customer_name')) ?>"></label>
			<label>Phone <input type="tel" name="new_customer_phone" value="<?= e($v('new_customer_phone')) ?>"></label>
			<label>Email <input type="email" name="new_customer_email" value="<?= e($v('new_customer_email')) ?>"></label>
			<p class="hint span-all">Add more contacts on the customer's page afterward.</p>
		</div>
	</section>

	<section class="card">
		<h2>Site and jurisdiction</h2>
		<div class="grid-form">
			<label class="span-2">Site street <input type="text" name="site_street" value="<?= e($v('site_street')) ?>" data-geo-field="street"></label>
			<label>City <input type="text" name="site_city" value="<?= e($v('site_city')) ?>" data-geo-field="city"></label>
			<div class="grid-pair">
				<label>State <input type="text" name="site_state" value="<?= e($v('site_state', 'PA')) ?>" maxlength="2" data-geo-field="state"></label>
				<label>ZIP <input type="text" name="site_zip" value="<?= e($v('site_zip')) ?>" data-geo-field="zip"></label>
			</div>
			<label>Utility
				<select name="utility_id">
					<option value="">Choose</option>
					<?php foreach ($utilities as $u): ?>
						<option value="<?= (int) $u['id'] ?>" <?= $sel($v('utility_id'), $u['id']) ?>><?= e($u['name']) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="span-2">Municipality (AHJ)
				<select name="municipality_id" data-reveal="new" data-target="#new-muni">
					<option value="">Choose</option>
					<option value="new" <?= $sel($v('municipality_id'), 'new') ?>>+ Add a municipality</option>
					<?php foreach ($municipalities as $m): ?>
						<option value="<?= (int) $m['id'] ?>" <?= $sel($v('municipality_id'), $m['id']) ?>><?= e(Municipalities::label($m)) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="span-2-phone">SREC provider
				<select name="srec_provider">
					<option value="">Choose</option>
					<?php foreach (Projects::SREC_PROVIDERS as $k => $label): ?>
						<option value="<?= $k ?>" <?= $sel($v('srec_provider'), $k) ?>><?= e($label) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<div id="geo-note" class="geo-note span-all" aria-live="polite" hidden
				data-geo-url="/geo/lookup"
				<?php if (!$isNew): ?>data-geo-check="/projects/<?= (int) $id ?>/geo-check" data-csrf="<?= e(Csrf::token()) ?>"<?php endif; ?>
				data-geo="<?= e(json_encode($geo ?? new stdClass())) ?>"></div>
		</div>
		<div id="new-muni" class="grid-form reveal" hidden>
			<label class="span-2">Municipality name <input type="text" name="new_muni_name" value="<?= e($v('new_muni_name')) ?>" placeholder="e.g. Penn Township"></label>
			<label>County
				<select name="new_muni_county_id">
					<option value="">Choose</option>
					<?php foreach ($counties as $c): ?>
						<option value="<?= (int) $c['id'] ?>" <?= $sel($v('new_muni_county_id'), $c['id']) ?>><?= e($c['name'] . ' (' . $c['state_code'] . ')') ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>

		<?php if (!$isNew): ?>
			<div class="grid-form mt-sm">
				<?php foreach (Municipalities::SLOTS as $key => $slot): ?>
					<div class="provider">
						<label><?= e($slot['label']) ?> by
							<select name="<?= $slot['by'] ?>" data-reveal="third_party" data-target="#<?= $key ?>-org">
								<?php foreach (Municipalities::BY as $bk => $bl): ?>
									<option value="<?= $bk ?>" <?= $sel($v($slot['by']), $bk) ?>><?= e($bl) ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label id="<?= $key ?>-org" hidden>Third party
							<select name="<?= $slot['org'] ?>">
								<option value="">Choose</option>
								<?php foreach ($agencies as $a): ?>
									<option value="<?= (int) $a['id'] ?>" <?= $sel($v($slot['org']), $a['id']) ?>><?= e($a['name']) ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="hint">Defaults come from the municipality when it's chosen. Admins add third parties under Lists &rsaquo; Directory.</p>
		<?php else: ?>
			<p class="hint">Who handles zoning, the building permit and inspections is copied from the municipality's usual setup and can be changed after the project is created.</p>
		<?php endif; ?>
	</section>

	<section class="card">
		<h2>System</h2>
		<div class="grid-form">
			<div class="checkfield span-2">
				<span class="field-label">System includes</span>
				<div class="boxrow">
					<label class="boxwrap"><input type="checkbox" name="has_pv" value="1" <?= $v('has_pv', 1) ? 'checked' : '' ?> data-toggle="#pv-block"> Solar PV</label>
					<label class="boxwrap"><input type="checkbox" name="has_batteries" value="1" <?= $v('has_batteries') ? 'checked' : '' ?> data-toggle="#battery-block"> Batteries</label>
				</div>
				<small class="hint">Decides which template tasks apply (panels, SREC, battery commissioning). Turning one on later adds its missing tasks; turning one off never deletes tasks.</small>
			</div>
			<label>Install type
				<select name="install_type" data-reveal="roof" data-target="#roof-block">
					<option value="">Choose</option>
					<?php foreach (Projects::INSTALL_TYPES as $k => $label): ?>
						<option value="<?= $k ?>" <?= $sel($v('install_type'), $k) ?>><?= e($label) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>Installer
				<select name="installer_org_id">
					<option value="">Trifecta (in-house)</option>
					<?php foreach ($contractors as $c): ?>
						<option value="<?= (int) $c['id'] ?>" <?= $sel($v('installer_org_id'), $c['id']) ?>><?= e($c['name']) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="span-2">Racking / tracker
				<input type="text" name="racking" value="<?= e($v('racking')) ?>" list="racking-list" placeholder="e.g. SunAction 48, Mechatron, IronRidge">
				<datalist id="racking-list"><?php foreach ($racking as $r): ?><option value="<?= e($r) ?>"><?php endforeach; ?></datalist>
			</label>
			<label>Designer
				<select name="designer_org_id">
					<option value="">Choose</option>
					<?php foreach ($designers as $d): ?>
						<option value="<?= (int) $d['id'] ?>" <?= $sel($v('designer_org_id'), $d['id']) ?>><?= e($d['name']) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>

		<div id="pv-block">
			<h3 class="sub">Modules</h3>
			<div class="eq" data-eq="modules">
				<div class="eq-head"><span>Qty</span><span>Watts</span><span>Description (optional)</span><span></span></div>
				<?php foreach ($eqRows('modules') as $i => $r): ?>
					<div class="eq-row">
						<input type="number" name="modules[<?= $i ?>][qty]" value="<?= e($r['qty'] ?? '') ?>" min="0" placeholder="Qty">
						<input type="number" name="modules[<?= $i ?>][watts]" value="<?= e($r['watts'] ?? '') ?>" step="any" min="0" placeholder="590">
						<input type="text" name="modules[<?= $i ?>][description]" value="<?= e($r['description'] ?? '') ?>" placeholder="SEG 590 Grade B">
						<button type="button" class="btn btn-ghost btn-small eq-del" title="Remove">&times;</button>
					</div>
				<?php endforeach; ?>
				<button type="button" class="btn btn-ghost btn-small eq-add">+ Module line</button>
			</div>
		</div>

		<div id="roof-block" hidden>
			<h3 class="sub">Roof faces</h3>
			<div class="eq eq-roof" data-eq="roofs">
				<div class="eq-head"><span>Face / building</span><span>Roof material</span><span>Azimuth</span><span>Tilt</span><span>Panels</span><span></span></div>
				<?php foreach ($eqRows('roofs') as $i => $r): ?>
					<div class="eq-row">
						<input type="text" name="roofs[<?= $i ?>][name]" value="<?= e($r['name'] ?? '') ?>" placeholder="e.g. East face, Bank barn" aria-label="Face or building">
						<select name="roofs[<?= $i ?>][material]" aria-label="Roof material">
							<option value="">Material</option>
							<?php foreach (Projects::ROOF_MATERIALS as $k => $label): ?>
								<option value="<?= $k ?>" <?= $sel($r['material'] ?? '', $k) ?>><?= e($label) ?></option>
							<?php endforeach; ?>
						</select>
						<label class="eq-cell"><span class="eq-mlabel">Azimuth</span><input type="number" name="roofs[<?= $i ?>][azimuth]" value="<?= e($r['azimuth'] ?? '') ?>" min="0" max="359" placeholder="180" aria-label="Azimuth in degrees"></label>
						<label class="eq-cell"><span class="eq-mlabel">Tilt</span><input type="text" name="roofs[<?= $i ?>][tilt]" value="<?= e($tiltVal($r['tilt'] ?? null)) ?>" placeholder="27 or 6/12" aria-label="Tilt in degrees or pitch"></label>
						<label class="eq-cell"><span class="eq-mlabel">Panels</span><input type="number" name="roofs[<?= $i ?>][panels]" value="<?= e($r['panels'] ?? '') ?>" min="0" placeholder="0" aria-label="Panels on this face"></label>
						<button type="button" class="btn btn-ghost btn-small eq-del" title="Remove">&times;</button>
					</div>
				<?php endforeach; ?>
				<button type="button" class="btn btn-ghost btn-small eq-add">+ Roof face</button>
			</div>
			<p class="hint">Azimuth is degrees from true north (90 east, 180 south, 270 west). Tilt takes degrees or a roof pitch like 6/12. Panels per face should add up to the module count.</p>
		</div>

		<h3 class="sub">Inverters</h3>
		<div class="eq" data-eq="inverters">
			<div class="eq-head"><span>Qty</span><span>AC kW each</span><span>Description (optional)</span><span></span></div>
			<?php foreach ($eqRows('inverters') as $i => $r): ?>
				<div class="eq-row">
					<input type="number" name="inverters[<?= $i ?>][qty]" value="<?= e($r['qty'] ?? '') ?>" min="0" placeholder="Qty">
					<input type="number" name="inverters[<?= $i ?>][ac_kw]" value="<?= e($r['ac_kw'] ?? '') ?>" step="any" min="0" placeholder="11.4">
					<input type="text" name="inverters[<?= $i ?>][description]" value="<?= e($r['description'] ?? '') ?>" placeholder="SolarEdge Home Hub">
					<button type="button" class="btn btn-ghost btn-small eq-del" title="Remove">&times;</button>
				</div>
			<?php endforeach; ?>
			<button type="button" class="btn btn-ghost btn-small eq-add">+ Inverter line</button>
		</div>
		<div class="grid-form mt-sm">
			<label class="span-2">Inverter internet
				<select name="inverter_internet">
					<option value="">Choose</option>
					<?php foreach (Projects::INVERTER_INTERNET as $k => $label): ?>
						<option value="<?= $k ?>" <?= $sel($v('inverter_internet'), $k) ?>><?= e($label) ?></option>
					<?php endforeach; ?>
				</select>
				<small class="hint">How the inverter reports to monitoring.</small>
			</label>
		</div>

		<div id="battery-block" <?= $v('has_batteries') ? '' : 'hidden' ?>>
			<h3 class="sub">Batteries</h3>
			<div class="eq eq-4" data-eq="batteries">
				<div class="eq-head"><span>Qty</span><span>kWh each</span><span>kW each</span><span>Description (optional)</span><span></span></div>
				<?php foreach ($eqRows('batteries') as $i => $r): ?>
					<div class="eq-row">
						<input type="number" name="batteries[<?= $i ?>][qty]" value="<?= e($r['qty'] ?? '') ?>" min="0" placeholder="Qty">
						<input type="number" name="batteries[<?= $i ?>][kwh]" value="<?= e($r['kwh'] ?? '') ?>" step="any" min="0" placeholder="10">
						<input type="number" name="batteries[<?= $i ?>][kw]" value="<?= e($r['kw'] ?? '') ?>" step="any" min="0" placeholder="5">
						<input type="text" name="batteries[<?= $i ?>][description]" value="<?= e($r['description'] ?? '') ?>" placeholder="SolarEdge Energy Bank">
						<button type="button" class="btn btn-ghost btn-small eq-del" title="Remove">&times;</button>
					</div>
				<?php endforeach; ?>
				<button type="button" class="btn btn-ghost btn-small eq-add">+ Battery line</button>
			</div>
		</div>
	</section>

	<section class="card">
		<h2>Contract and funding</h2>
		<div class="grid-form">
			<label>Contract price
				<input type="text" name="contract_price" value="<?= e($price) ?>" inputmode="decimal" placeholder="0.00">
				<small class="hint">Including change orders.</small>
			</label>
			<label>Est. annual production (kWh)
				<input type="text" name="est_annual_kwh" value="<?= e($v('est_annual_kwh')) ?>" inputmode="numeric">
			</label>
			<label>Tax exempt
				<select name="tax_exempt">
					<option value="" <?= $v('tax_exempt') === null || $v('tax_exempt') === '' ? 'selected' : '' ?>>Unknown</option>
					<option value="1" <?= $sel($v('tax_exempt'), 1) ?>>Yes (certificate)</option>
					<option value="0" <?= $sel($v('tax_exempt'), 0) ?>>No</option>
				</select>
			</label>
		</div>
		<fieldset class="checks">
			<legend>Funding</legend>
			<?php foreach ($fundingSources as $f): ?>
				<label class="check"><input type="checkbox" name="funding[]" value="<?= (int) $f['id'] ?>" <?= in_array((int) $f['id'], $fundingIds, true) ? 'checked' : '' ?>> <?= e($f['name']) ?></label>
			<?php endforeach; ?>
		</fieldset>
		<label>Funding note
			<input type="text" name="funding_note" value="<?= e($v('funding_note')) ?>" placeholder="e.g. REAP awarded 6/2026, loan through Fulton">
		</label>
		<p class="hint mt-sm">SolarInsure, utility rebate and VNM are answered on the project page (Closeout tasks).</p>
	</section>

	<div class="form-actions">
		<button class="btn btn-primary"><?= $isNew ? 'Create project' : 'Save changes' ?></button>
		<a href="<?= $isNew ? '/projects' : '/projects/' . (int) $id ?>" class="btn btn-ghost">Cancel</a>
	</div>
</form>

<script src="<?= asset('assets/js/forms.js') ?>"></script>
<script src="<?= asset('assets/js/geo.js') ?>"></script>
