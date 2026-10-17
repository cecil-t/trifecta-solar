<?php
use App\Csrf;
use App\Projects;

/**
 * @var array    $o          opportunity row or the form as typed
 * @var int|null $id
 * @var array    $customers  id, name
 * @var array    $users      salespeople and admins: id, name
 * @var bool     $canAssign  admins pick the salesperson; a salesperson's opportunities are their own
 * @var array    $municipalities
 * @var array    $counties
 */
$v = static fn (string $k, $d = '') => $o[$k] ?? $d;
$sel = static fn ($a, $b) => (string) $a === (string) $b && (string) $a !== '' ? 'selected' : '';
$isNew = $id === null;
?>
<div class="page-head">
	<div>
		<a href="<?= $isNew ? '/presales' : '/presales/' . (int) $id ?>" class="back">&larr; <?= $isNew ? 'Sales' : e($v('name')) ?></a>
		<h1><?= $isNew ? 'New opportunity' : 'Edit opportunity' ?></h1>
		<?php if ($isNew): ?><p class="muted">A customer and a site being quoted. Saving opens its first build sheet.</p><?php endif; ?>
	</div>
</div>

<form method="post" action="<?= $isNew ? '/presales' : '/presales/' . (int) $id ?>" class="project-form">
	<?= Csrf::field() ?>
	<section class="card band">
		<h2>Customer</h2>
		<div class="grid-form">
			<label class="span-2">Opportunity name
				<input type="text" name="name" value="<?= e($v('name')) ?>" placeholder="Leave blank to use the customer's name">
				<small class="hint">Add the site when a customer has more than one (e.g. "Smith, Main St barn").</small>
			</label>
			<?php if ($canAssign): ?>
				<label>Salesperson
					<select name="salesperson_id">
						<option value="">Choose</option>
						<?php foreach ($users as $u): ?>
							<option value="<?= (int) $u['id'] ?>" <?= $sel($v('salesperson_id'), $u['id']) ?>><?= e($u['name']) ?></option>
						<?php endforeach; ?>
					</select>
					<small class="hint">Only that salesperson and admins see this opportunity.</small>
				</label>
			<?php else: ?>
				<div class="checkfield">
					<span class="field-label">Salesperson</span>
					<span class="boxwrap"><?php foreach ($users as $u) { if ((int) $u['id'] === (int) $v('salesperson_id', App\Auth::id())) { echo e($u['name']); } } ?></span>
					<small class="hint">Only you and admins see this opportunity. Admins can reassign it.</small>
				</div>
			<?php endif; ?>
			<label class="span-2">Customer
				<select name="customer_id" data-reveal="new" data-target="#new-customer">
					<option value="">Choose</option>
					<option value="new" <?= $sel($v('customer_id'), 'new') ?>>+ New customer</option>
					<?php foreach ($customers as $c): ?>
						<option value="<?= (int) $c['id'] ?>" <?= $sel($v('customer_id'), $c['id']) ?>><?= e($c['name']) ?></option>
					<?php endforeach; ?>
				</select>
				<small class="hint">Repeat customers keep their customer record; a new site or an expansion is a new opportunity.</small>
			</label>
			<label>Customer type
				<select name="customer_type">
					<?php foreach (Projects::CUSTOMER_TYPES as $k => $label): ?>
						<option value="<?= $k ?>" <?= $sel($v('customer_type', 'residential'), $k) ?>><?= e($label) ?></option>
					<?php endforeach; ?>
				</select>
				<small class="hint">Commercial, agricultural, government and non-profit jobs are all Non-Residential: that decides the deposit and which BOS, permit and interconnection items show.</small>
			</label>
		</div>
		<div id="new-customer" class="grid-form reveal" hidden>
			<label class="span-2">New customer name <input type="text" name="new_customer_name" value="<?= e($v('new_customer_name')) ?>"></label>
			<label>Phone <input type="tel" name="new_customer_phone" value="<?= e($v('new_customer_phone')) ?>"></label>
			<label>Email <input type="email" name="new_customer_email" value="<?= e($v('new_customer_email')) ?>"></label>
		</div>
		<div class="grid-form mt-sm">
			<div class="checkfield">
				<span class="field-label">Agricultural</span>
				<label class="boxwrap"><input type="checkbox" name="is_agricultural" value="1" <?= $v('is_agricultural') ? 'checked' : '' ?>> Farm / agricultural</label>
			</div>
		</div>
	</section>

	<section class="card band">
		<h2>Lead</h2>
		<div class="grid-form">
			<label>Lead source
				<select name="lead_source" id="lead-source">
					<option value="">Choose</option>
					<?php foreach (App\Presales::LEAD_SOURCES as $k => $label): ?>
						<option value="<?= $k ?>" <?= $sel($v('lead_source'), $k) ?>><?= e($label) ?></option>
					<?php endforeach; ?>
				</select>
				<small class="hint">Self-generated pays the self-generated bonus (net-new customers only); a referral pays the referrer instead.</small>
			</label>
			<label data-lead-show="trifecta" <?= $v('lead_source') === 'trifecta' ? '' : 'hidden' ?>>How it came in
				<select name="lead_detail">
					<option value="">Choose</option>
					<?php foreach (App\Presales::LEAD_DETAILS as $k => $label): ?>
						<option value="<?= $k ?>" <?= $sel($v('lead_detail'), $k) ?>><?= e($label) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<div class="checkfield span-2" data-lead-show="referral" <?= $v('lead_source') === 'referral' ? '' : 'hidden' ?>>
				<label for="referrer" class="field-label">Referred by</label>
				<div class="picker" data-picker-url="/organizations/search">
					<input type="text" id="referrer" name="referrer_name" value="<?= e($v('referrer_label', $v('referrer_name'))) ?>" class="picker-input" autocomplete="off" placeholder="Search the directory, or type a name" role="combobox" aria-expanded="false" aria-controls="referrer-list" aria-autocomplete="list">
					<input type="hidden" name="referrer_org_id" value="<?= e((string) $v('referrer_org_id')) ?>" class="picker-id">
					<ul class="picker-list" id="referrer-list" role="listbox" hidden></ul>
				</div>
				<small class="hint">A customer, someone we quoted, a friend, or a referral partner such as a roofer. Pick from the directory when they are in it.</small>
			</div>
			<label>Handed off by
				<select name="handoff_user_id">
					<option value="">Nobody (found by the salesperson or Trifecta)</option>
					<?php foreach ($users as $u): ?>
						<option value="<?= (int) $u['id'] ?>" <?= $sel($v('handoff_user_id'), $u['id']) ?>><?= e($u['name']) ?></option>
					<?php endforeach; ?>
				</select>
				<small class="hint">Another salesperson who handed this lead over. They get the in-house share of the whole payout.</small>
			</label>
		</div>
	</section>

	<section class="card band">
		<h2>Site</h2>
		<div class="grid-form">
			<?= App\View::partial('partials/address_full', ['value' => '']) ?>
			<label class="span-2">Street <input type="text" name="site_street" value="<?= e($v('site_street')) ?>" data-addr="street" data-geo-field="street"></label>
			<label>City <input type="text" name="site_city" value="<?= e($v('site_city')) ?>" data-addr="city" data-geo-field="city"></label>
			<div class="grid-pair">
				<label>State
					<select name="site_state" data-addr="state" data-geo-field="state">
						<?php foreach (['PA', 'MD', 'DE'] as $st): ?>
							<option value="<?= $st ?>" <?= $sel($v('site_state', 'PA'), $st) ?>><?= $st ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>ZIP <input type="text" name="site_zip" value="<?= e($v('site_zip')) ?>" inputmode="numeric" data-addr="zip" data-geo-field="zip"></label>
			</div>
			<?= App\View::partial('partials/municipality_pick', [
				'value' => (string) $v('municipality_id'), 'municipalities' => $municipalities, 'counties' => $counties,
				'newName' => (string) $v('new_muni_name'), 'newCounty' => (string) $v('new_muni_county_id'), 'geoAttrs' => 'data-geo="{}"',
			]) ?>
			<details class="point-box span-all">
				<summary class="small muted">Map point: <span data-point-summary><?= $v('site_lat') !== '' && $v('site_lat') !== null ? e(number_format((float) $v('site_lat'), 5) . ', ' . number_format((float) $v('site_lon'), 5)) . ($v('geo_source') ? ' (' . e(App\Address::sourceLabel((string) $v('geo_source'))) . ')' : '') : 'found from the address when you save' ?></span> &middot; change</summary>
				<div class="point-pair mt-xs">
					<input type="text" name="site_lat" value="<?= e((string) $v('site_lat')) ?>" inputmode="decimal" placeholder="Latitude" aria-label="Latitude" data-addr="lat">
					<input type="text" name="site_lon" value="<?= e((string) $v('site_lon')) ?>" inputmode="decimal" placeholder="Longitude" aria-label="Longitude" data-addr="lon">
				</div>
				<input type="hidden" name="geo_source" value="<?= e((string) $v('geo_source')) ?>" data-addr="source">
				<small class="hint">Set automatically from the address; production weather and utility rates use it. Only change it when the address can't be found (right-click the spot in Google Maps to copy it).</small>
			</details>
			<label class="span-2">Drive folder link <input type="url" name="drive_url" value="<?= e($v('drive_url')) ?>" placeholder="https://drive.google.com/..."></label>
			<label class="span-2">Notes <textarea name="notes" rows="3" data-autogrow><?= e($v('notes')) ?></textarea></label>
		</div>
	</section>

	<section class="card band">
		<h2>Utility bill</h2>
		<p class="hint">From the customer's electric bill. It all carries into the project.</p>
		<div class="grid-form">
			<label class="span-2">Name on utility bill <input type="text" name="bill_name" value="<?= e($v('bill_name')) ?>"></label>
			<label>Utility
				<select name="utility_id" data-addr="utility">
					<option value="">Choose</option>
					<?php foreach ($utilities as $u): ?>
						<option value="<?= (int) $u['id'] ?>" <?= $sel($v('utility_id'), $u['id']) ?>><?= e($u['name']) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>Last 12 months usage (kWh) <input type="text" name="annual_usage_kwh" value="<?= e($v('annual_usage_kwh')) ?>" inputmode="numeric" placeholder="e.g. 14,200"></label>
			<label>Account # <input type="text" name="utility_account" value="<?= e($v('utility_account')) ?>"></label>
			<label>Meter # <input type="text" name="utility_meter" value="<?= e($v('utility_meter')) ?>"></label>
			<div class="checkfield span-all rate-picker" data-rate-picker="/presales/utility-rates">
				<span class="field-label">Utility rate (OpenEI)</span>
				<input type="hidden" name="rate_label" value="<?= e((string) $v('rate_label')) ?>">
				<div class="rate-row">
					<span class="rate-chosen"><?= $v('rate_label') ? '<strong>' . e((string) $v('rate_name')) . '</strong>' . ($v('rate_utility') ? ' <span class="muted small">' . e((string) $v('rate_utility')) . '</span>' : '') : '<span class="muted">No rate picked.</span>' ?></span>
					<button type="button" class="btn btn-secondary btn-small rate-find">Find rates</button>
					<button type="button" class="btn btn-ghost btn-small rate-clear" <?= $v('rate_label') ? '' : 'hidden' ?>>Clear</button>
				</div>
				<small class="hint rate-status" aria-live="polite"></small>
				<ul class="rate-list"></ul>
			</div>
			<label>Rate name on the bill <input type="text" name="utility_tariff" value="<?= e($v('utility_tariff')) ?>" placeholder="e.g. RS" <?= $v('rate_label') && $v('utility_tariff') === $v('rate_name') ? 'data-from-rate="1"' : '' ?>>
				<small class="hint">Filled from the picked rate; type it if OpenEI doesn't list it.</small>
			</label>
			<label>Service address, if not the site <input type="text" name="service_address" value="<?= e($v('service_address')) ?>"></label>
		</div>
	</section>

	<div class="form-actions">
		<button class="btn btn-primary"><?= $isNew ? 'Create and open build sheet' : 'Save' ?></button>
		<a href="<?= $isNew ? '/presales' : '/presales/' . (int) $id ?>" class="btn btn-ghost">Cancel</a>
	</div>
</form>
<script src="<?= asset('assets/js/forms.js') ?>"></script>
<script src="<?= asset('assets/js/picker.js') ?>"></script>
<script src="<?= asset('assets/js/address.js') ?>"></script>
<script src="<?= asset('assets/js/geo.js') ?>"></script>
