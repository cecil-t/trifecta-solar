<?php
use App\Csrf;
use App\Tasks;

$isNew = empty($t['id']);
$sel = static fn ($a, $b) => (string) $a === (string) $b ? 'selected' : '';
?>
<div class="page-head">
	<div>
		<a href="/admin/template" class="back">&larr; Task template</a>
		<h1><?= $isNew ? ($parent ? 'Add sub-task to ' . e($parent['name']) : 'Add task') : 'Edit "' . e($t['name']) . '"' ?></h1>
		<?php if ($parent && !$isNew): ?><p class="muted">Sub-task of <?= e($parent['name']) ?></p><?php endif; ?>
	</div>
</div>
<section class="card narrow">
	<form method="post" action="<?= $isNew ? '/admin/template' : '/admin/template/' . (int) $t['id'] ?>" class="grid-form">
		<?= Csrf::field() ?>
		<input type="hidden" name="parent_id" value="<?= (int) ($t['parent_id'] ?? 0) ?>">
		<label class="span-2">Name <input type="text" name="name" value="<?= e($t['name']) ?>" required></label>
		<label>Order <input type="number" name="sort_order" value="<?= (int) $t['sort_order'] ?>" class="input-short"></label>
		<?php if (!$parent): ?>
			<label>Phase
				<select name="phase"><?php foreach (Tasks::PHASES as $k => $l): ?><option value="<?= $k ?>" <?= $sel($t['phase'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
			</label>
		<?php else: ?>
			<input type="hidden" name="phase" value="<?= e($t['phase']) ?>">
		<?php endif; ?>
		<label>Needed by default
			<select name="default_needed">
				<option value="1" <?= $sel($t['default_needed'], 1) ?>>Yes</option>
				<option value="0" <?= $sel($t['default_needed'], 0) ?>>No</option>
				<option value="" <?= $t['default_needed'] === null ? 'selected' : '' ?>>Ask (leave blank)</option>
			</select>
			<small class="hint">"Ask" makes it a question item: projects show a Yes / No / ? choice for it (top-level items only).</small>
		</label>
		<label>Applies to
			<select name="applies_when">
				<option value="all" <?= $sel($t['applies_when'], 'all') ?>>All projects</option>
				<option value="pv" <?= $sel($t['applies_when'], 'pv') ?>>Projects with solar PV</option>
				<option value="storage" <?= $sel($t['applies_when'], 'storage') ?>>Projects with batteries</option>
			</select>
		</label>
		<label>Gate
			<select name="gate">
				<option value="">None</option>
				<?php foreach (Tasks::GATES as $k => $l): ?><option value="<?= $k ?>" <?= $sel($t['gate'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
			</select>
		</label>
		<?php if (!$parent): ?>
			<label>Reference # label <input type="text" name="ref_label" value="<?= e($t['ref_label']) ?>" placeholder="e.g. Permit #, Work order #"></label>
		<?php endif; ?>
		<label>Default owner
			<select name="default_owner_id">
				<option value=""><?= $parent ? 'Same as task' : 'None' ?></option>
				<?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $sel($t['default_owner_id'], $u['id']) ?>><?= e($u['name']) ?></option><?php endforeach; ?>
			</select>
		</label>
		<label class="check self-end"><input type="checkbox" name="is_active" value="1" <?= $t['is_active'] ? 'checked' : '' ?>> Active (included in new projects)</label>
		<div class="span-all"><button class="btn btn-primary"><?= $isNew ? 'Add' : 'Save' ?></button></div>
	</form>
	<p class="hint mt">Gates drive project status: <strong>Starts day counter</strong> (contract signed), <strong>Required for Clear to install</strong> (any one done within the same task counts), <strong>Moves to Installation</strong>, <strong>Moves to Closeout</strong> (PTO).</p>
</section>
