<?php
use App\Auth;
use App\Csrf;
use App\Controllers\OrganizationController;

$isNew = empty($o['id']);
$canManage ??= OrganizationController::canManage($o['type']);
$isCustomer = $o['type'] === 'customer';
$disabled = $canManage ? '' : 'disabled';
?>
<div class="page-head">
    <div>
        <a href="<?= $isCustomer ? '/customers' : '/directory' ?>" class="back">&larr; <?= $isCustomer ? 'Customers' : 'Directory' ?></a>
        <h1><?= $isNew ? 'Add ' . e(strtolower(OrganizationController::TYPES[$o['type']])) : e($o['name']) ?></h1>
        <?php if (!$isNew): ?><p class="muted"><?= e(OrganizationController::TYPES[$o['type']]) ?></p><?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Details</h2>
        <form method="post" action="<?= $isNew ? '/organizations' : '/organizations/' . (int) $o['id'] ?>" class="grid-form">
            <?= Csrf::field() ?>
            <?php if (!$isCustomer && Auth::isAdmin()): ?>
                <label>Type
                    <select name="type" <?= $disabled ?>>
                        <?php foreach (OrganizationController::TYPES as $k => $l): if ($k === 'customer') { continue; } ?>
                            <option value="<?= $k ?>" <?= $o['type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php else: ?>
                <input type="hidden" name="type" value="<?= e($o['type']) ?>">
            <?php endif; ?>
            <label class="span-2">Name <input type="text" name="name" value="<?= e($o['name'] ?? '') ?>" required <?= $disabled ?>></label>
            <label>Phone <input type="text" name="phone" value="<?= e($o['phone'] ?? '') ?>" <?= $disabled ?>></label>
            <label>Email <input type="email" name="email" value="<?= e($o['email'] ?? '') ?>" <?= $disabled ?>></label>
            <label class="span-2">Street <input type="text" name="street" value="<?= e($o['street'] ?? '') ?>" <?= $disabled ?>></label>
            <label>City <input type="text" name="city" value="<?= e($o['city'] ?? '') ?>" <?= $disabled ?>></label>
            <label>State <input type="text" name="state" value="<?= e($o['state'] ?? '') ?>" maxlength="2" class="input-short" <?= $disabled ?>></label>
            <label>ZIP <input type="text" name="zip" value="<?= e($o['zip'] ?? '') ?>" class="input-short" <?= $disabled ?>></label>
            <label class="span-all">Notes <textarea name="notes" rows="2" <?= $disabled ?>><?= e($o['notes'] ?? '') ?></textarea></label>
            <label class="check"><input type="checkbox" name="is_active" value="1" <?= ($o['is_active'] ?? 1) ? 'checked' : '' ?> <?= $disabled ?>> Active</label>
            <?php if ($canManage): ?><div class="span-all"><button class="btn btn-primary"><?= $isNew ? 'Add' : 'Save' ?></button></div><?php endif; ?>
        </form>
    </section>

    <?php if (!$isNew): ?>
        <section class="card">
            <h2>Contacts</h2>
            <?= App\View::partial('partials/contacts', ['contacts' => $contacts, 'ownerType' => 'organization', 'ownerId' => (int) $o['id'], 'canManage' => $canManage]) ?>

            <h2 class="mt">Projects</h2>
            <?php if (!$projects): ?><p class="muted">None yet.</p><?php endif; ?>
            <ul class="plain">
                <?php foreach ($projects as $pr): ?>
                    <li><a href="/projects/<?= (int) $pr['id'] ?>"><strong><?= e($pr['project_number']) ?></strong> <?= e($pr['name']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="card span-2">
            <h2>Activity</h2>
            <?= App\View::partial('partials/activity', ['entries' => $activity]) ?>
        </section>
    <?php endif; ?>
</div>
