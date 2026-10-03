<?php
use App\Csrf;

$isNew = $u === null;
$val = static fn (string $k, $default = '') => $isNew ? old($k, $default) : ($u[$k] ?? $default);
?>
<div class="page-head">
    <div>
        <a href="/users" class="back">&larr; Users</a>
        <h1><?= $isNew ? 'Add user' : e($u['name']) ?></h1>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Details</h2>
        <form method="post" action="<?= $isNew ? '/users' : '/users/' . (int) $u['id'] ?>" class="stack">
            <?= Csrf::field() ?>
            <label>Full name
                <input type="text" name="name" value="<?= e($val('name')) ?>" required>
            </label>
            <label>Email (used to sign in)
                <input type="email" name="email" value="<?= e($val('email')) ?>" required>
            </label>
            <label>Initials
                <input type="text" name="initials" value="<?= e($val('initials')) ?>" maxlength="4" class="input-short">
            </label>
            <label class="check"><input type="checkbox" name="is_admin" value="1" <?= $val('is_admin', 0) ? 'checked' : '' ?>> Admin</label>
            <label class="check"><input type="checkbox" name="is_active" value="1" <?= $val('is_active', 1) ? 'checked' : '' ?>> Active (can sign in)</label>
            <div><button class="btn btn-primary"><?= $isNew ? 'Add user' : 'Save changes' ?></button></div>
        </form>
        <?php clear_old(); ?>
    </section>

    <?php if (!$isNew): ?>
        <section class="card">
            <h2><?= $u['password_hash'] ? 'Reset password' : 'Set temporary password' ?></h2>
            <form method="post" action="/users/<?= (int) $u['id'] ?>/password" class="stack">
                <?= Csrf::field() ?>
                <label>New password
                    <input type="password" name="password" autocomplete="new-password" required minlength="8">
                </label>
                <label>Confirm
                    <input type="password" name="password_confirm" autocomplete="new-password" required minlength="8">
                </label>
                <p class="hint"><?= e(App\Password::RULES) ?></p>
                <?php if ($u['password_hash']): ?>
                    <label class="check"><input type="checkbox" name="revoke_devices" value="1"> Also sign them out everywhere</label>
                <?php endif; ?>
                <div><button class="btn btn-secondary">Set password</button></div>
            </form>

            <h2 class="mt">Devices (<?= count($devices) ?>)</h2>
            <ul class="devices">
                <?php foreach ($devices as $d): ?>
                    <li><div><strong><?= e($d['label']) ?></strong>
                        <div class="muted small">since <?= e(fmt_dt($d['created_at'], 'm/d/Y')) ?> &middot; last active <?= e(time_ago($d['last_seen_at'])) ?></div></div></li>
                <?php endforeach; ?>
                <?php if (!$devices): ?><li class="muted">Not signed in anywhere.</li><?php endif; ?>
            </ul>
            <?php if ($devices): ?>
                <form method="post" action="/users/<?= (int) $u['id'] ?>/revoke-devices">
                    <?= Csrf::field() ?>
                    <button class="btn btn-ghost">Sign out all devices</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="card span-2">
            <h2>Activity</h2>
            <?= App\View::partial('partials/activity', ['entries' => $activity]) ?>
        </section>
    <?php endif; ?>
</div>
