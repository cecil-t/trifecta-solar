<?php
use App\Csrf;
use App\Projects;

/**
 * @var array    $o          opportunity row or the form as typed
 * @var int|null $id
 * @var array    $customers  id, name
 * @var array    $users      id, name
 */
$v = static fn (string $k, $d = '') => $o[$k] ?? $d;
$sel = static fn ($a, $b) => (string) $a === (string) $b && (string) $a !== '' ? 'selected' : '';
$isNew = $id === null;
?>
<div class="page-head">
	<div>
		<a href="<?= $isNew ? '/presales' : '/presales/' . (int) $id ?>" class="back">&larr; <?= $isNew ? 'Pre-Sales' : e($v('name')) ?></a>
		<h1><?= $isNew ? 'New opportunity' : 'Edit opportunity' ?></h1>
		<?php if ($isNew): ?><p class="muted">A customer and a site being quoted. Saving opens its first build sheet.</p><?php endif; ?>
	</div>
</div>

<form method="post" action="<?= $isNew ? '/presales' : '/presales/' . (int) $id ?>" class="project-form">
	<?= Csrf::field() ?>
	<section class="card">
		<h2>Customer</h2>
		<div class="grid-form">
			<label class="span-2">Opportunity name
				<input type="text" name="name" value="<?= e($v('name')) ?>" required placeholder="e.g. Smith residence, Main St barn">
			</label>
			<label>Salesperson
				<select name="salesperson_id">
					<option value="">Choose</option>
					<?php foreach ($users as $u): ?>
						<option value="<?= (int) $u['id'] ?>" <?= $sel($v('salesperson_id'), $u['id']) ?>><?= e($u['name']) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
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
				<small class="hint">Residential or not decides the deposit choices.</small>
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

	<section class="card">
		<h2>Site</h2>
		<div class="grid-form">
			<label class="span-2">Street <input type="text" name="site_street" value="<?= e($v('site_street')) ?>"></label>
			<label>City <input type="text" name="site_city" value="<?= e($v('site_city')) ?>"></label>
			<label>State
				<select name="site_state">
					<?php foreach (['PA', 'MD', 'DE'] as $st): ?>
						<option value="<?= $st ?>" <?= $sel($v('site_state', 'PA'), $st) ?>><?= $st ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>ZIP <input type="text" name="site_zip" value="<?= e($v('site_zip')) ?>" class="input-short" inputmode="numeric"></label>
			<label class="span-2">Drive folder link <input type="url" name="drive_url" value="<?= e($v('drive_url')) ?>" placeholder="https://drive.google.com/..."></label>
			<label class="span-2">Notes <textarea name="notes" rows="3" data-autogrow><?= e($v('notes')) ?></textarea></label>
		</div>
	</section>

	<div class="form-actions">
		<button class="btn btn-primary"><?= $isNew ? 'Create and open build sheet' : 'Save' ?></button>
		<a href="<?= $isNew ? '/presales' : '/presales/' . (int) $id ?>" class="btn btn-ghost">Cancel</a>
	</div>
</form>
<script src="<?= asset('assets/js/forms.js') ?>"></script>
