<?php
use App\Csrf;
use App\Service;

$v = static fn (string $k, $d = '') => $t[$k] ?? $d;
$sel = static fn ($a, $b) => (string) $a === (string) $b && (string) $a !== '' ? 'selected' : '';
$amount = $t['bill_amount'] ?? (isset($t['bill_amount_cents']) && $t['bill_amount_cents'] !== null ? number_format($t['bill_amount_cents'] / 100, 2, '.', '') : '');
$isNew = $id === null;
$back = $isNew ? '/service' : '/service/' . (int) $id;
?>
<div class="page-head">
	<div>
		<a href="<?= $back ?>" class="back">&larr; <?= $isNew ? 'Service' : e($t['ticket_number']) ?></a>
		<h1><?= $isNew ? 'New service ticket' : 'Edit service ticket' ?></h1>
	</div>
</div>

<form method="post" action="<?= $isNew ? '/service' : '/service/' . (int) $id ?>" class="project-form">
	<?= Csrf::field() ?>

	<section class="card">
		<h2>Request</h2>
		<div class="grid-form">
			<label>Service #
				<input type="text" name="ticket_number" value="<?= e($v('ticket_number')) ?>" required>
			</label>
			<label>Opened
				<input type="date" name="opened_on" value="<?= e($v('opened_on')) ?>" required>
			</label>
			<label>Came from
				<select name="source">
					<option value="">Choose</option>
					<?php foreach (Service::SOURCES as $k => $l): ?><option value="<?= $k ?>" <?= $sel($v('source'), $k) ?>><?= e($l) ?></option><?php endforeach; ?>
				</select>
			</label>
			<label>Owner
				<select name="owner_id">
					<option value="">Choose</option>
					<?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $sel($v('owner_id'), $u['id']) ?>><?= e($u['name']) ?></option><?php endforeach; ?>
				</select>
			</label>
			<label class="span-all">Problem / request
				<textarea name="description" rows="3" required placeholder="e.g. Inverter offline in monitoring portal, SolarEdge SiteID 1809815"><?= e($v('description')) ?></textarea>
			</label>
			<label class="span-2">Google Drive service folder
				<input type="url" name="drive_url" value="<?= e($v('drive_url')) ?>" placeholder="https://drive.google.com/...">
			</label>
			<label class="span-2">Monitoring portal
				<input type="url" name="monitoring_url" value="<?= e($v('monitoring_url')) ?>" placeholder="https://monitoring.solaredge.com/...">
				<small class="hint">Direct link to this system in SolarEdge, Enphase, SMA, etc.</small>
			</label>
		</div>
	</section>

	<section class="card">
		<h2>Customer and site</h2>
		<div class="grid-form">
			<label class="span-2">Customer
				<select name="customer_id" data-reveal="new" data-target="#new-customer">
					<option value="">Choose</option>
					<option value="new" <?= $sel($v('customer_id'), 'new') ?>>+ New customer</option>
					<?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel($v('customer_id'), $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
				</select>
			</label>
			<label class="span-2">Original project (if we installed it)
				<select name="project_id">
					<option value="">None / not in the tracker</option>
					<?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $sel($v('project_id'), $p['id']) ?>><?= e($p['project_number'] . ' ' . $p['name']) ?></option><?php endforeach; ?>
				</select>
				<small class="hint">Picking a project marks it as our install and fills a blank site address.</small>
			</label>
		</div>
		<div id="new-customer" class="grid-form reveal" hidden>
			<label class="span-2">New customer name <input type="text" name="new_customer_name" value="<?= e($v('new_customer_name')) ?>" placeholder="e.g. Allen Residence"></label>
			<label>Phone <input type="tel" name="new_customer_phone" value="<?= e($v('new_customer_phone')) ?>"></label>
			<label>Email <input type="email" name="new_customer_email" value="<?= e($v('new_customer_email')) ?>"></label>
		</div>
		<div class="grid-form mt-sm">
			<label class="span-2">Site street <input type="text" name="site_street" value="<?= e($v('site_street')) ?>"></label>
			<label>City <input type="text" name="site_city" value="<?= e($v('site_city')) ?>"></label>
			<div class="grid-pair">
				<label>State <input type="text" name="site_state" value="<?= e($v('site_state', 'PA')) ?>" maxlength="2"></label>
				<label>ZIP <input type="text" name="site_zip" value="<?= e($v('site_zip')) ?>"></label>
			</div>
			<label>Trifecta install?
				<select name="trifecta_install">
					<option value="">Unknown</option>
					<option value="1" <?= $sel($v('trifecta_install'), 1) ?>>Yes</option>
					<option value="0" <?= $sel($v('trifecta_install'), 0) ?>>No (another installer)</option>
				</select>
			</label>
			<label>Coverage
				<select name="coverage">
					<option value="">Choose</option>
					<?php foreach (Service::COVERAGE as $k => $l): ?><option value="<?= $k ?>" <?= $sel($v('coverage'), $k) ?>><?= e($l) ?></option><?php endforeach; ?>
				</select>
				<small class="hint">Billable and SolarInsure go to the Ready to invoice list when completed.</small>
			</label>
		</div>
	</section>

	<section class="card">
		<h2>Schedule and billing</h2>
		<div class="grid-form">
			<label>Next visit scheduled
				<input type="date" name="scheduled_on" value="<?= e($v('scheduled_on')) ?>">
			</label>
			<label>Completed
				<input type="date" name="completed_on" value="<?= e($v('completed_on')) ?>">
			</label>
			<label>Amount billed
				<input type="text" name="bill_amount" value="<?= e($amount) ?>" inputmode="decimal" placeholder="0.00">
				<small class="hint">The total to invoice, parts and labor.</small>
			</label>
			<div></div>
			<label class="span-all">Billing note
				<input type="text" name="billing_note" value="<?= e($v('billing_note')) ?>" placeholder="e.g. Quoted $4,700 for the upgrade, trip free. Parts: 1 disconnect, 2 fuses.">
				<small class="hint">Anything the office needs to build the invoice. Visits and man-hours are pulled in automatically.</small>
			</label>
			<div class="checkfield">
				<span class="field-label">Invoiced</span>
				<label class="boxwrap"><input type="checkbox" name="invoiced" value="1" <?= (int) $v('invoiced', 0) ? 'checked' : '' ?>> In QuickBooks</label>
			</div>
			<label>Invoice date
				<input type="date" name="invoiced_on" value="<?= e($v('invoiced_on')) ?>">
			</label>
			<label>Invoice #
				<input type="text" name="invoice_number" value="<?= e($v('invoice_number')) ?>">
			</label>
		</div>
	</section>

	<div class="form-actions">
		<button class="btn btn-primary"><?= $isNew ? 'Open ticket' : 'Save changes' ?></button>
		<a href="<?= $back ?>" class="btn btn-ghost">Cancel</a>
	</div>
</form>

<script src="<?= asset('assets/js/forms.js') ?>"></script>
