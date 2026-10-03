<?php
use App\Csrf;

$v = static fn (string $k, $def = '') => $d[$k] ?? $def;
$sel = static fn ($a, $b) => (string) $a === (string) $b && (string) $a !== '' ? 'selected' : '';
$isNew = $id === null;
$back = $v('back', $isNew ? '/tasks' : '/tasks/' . (int) $id);
?>
<div class="page-head">
    <div>
        <a href="<?= e($back) ?>" class="back">&larr; Back</a>
        <h1><?= $isNew ? 'Assign a task' : 'Edit task' ?></h1>
    </div>
</div>

<form method="post" action="<?= $isNew ? '/tasks' : '/tasks/' . (int) $id ?>" class="card project-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="grid-form">
        <label class="span-all">What needs to be done
            <input type="text" name="title" value="<?= e($v('title')) ?>" required maxlength="300" placeholder="e.g. Call PPL about the Riverside meter swap" <?= $isNew ? 'autofocus' : '' ?>>
        </label>
        <label>Assign to
            <select name="assigned_to" required>
                <option value="">Choose</option>
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $sel($v('assigned_to'), $u['id']) ?>><?= e($u['name']) ?><?= (int) $u['id'] === App\Auth::id() ? ' (me)' : '' ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Due
            <input type="date" name="due_on" value="<?= e($v('due_on')) ?>">
            <small class="hint">Optional.</small>
        </label>
        <label>Project
            <select name="project_id">
                <option value="">None</option>
                <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $sel($v('project_id'), $p['id']) ?>><?= e($p['project_number'] . ' ' . $p['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Service ticket
            <select name="service_id">
                <option value="">None</option>
                <?php foreach ($tickets as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $sel($v('service_id'), $t['id']) ?>><?= e($t['ticket_number'] . ' ' . $t['customer']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="span-all">Details
            <textarea name="details" rows="4" placeholder="Anything they need to know"><?= e($v('details')) ?></textarea>
        </label>
    </div>
    <div class="form-actions">
        <button class="btn btn-primary"><?= $isNew ? 'Assign task' : 'Save changes' ?></button>
        <a href="<?= e($back) ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>
