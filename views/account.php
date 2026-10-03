<?php use App\Auth; use App\Csrf; ?>
<div class="page-head">
    <div>
        <h1>My account</h1>
        <p class="muted"><?= e($user['name']) ?> &middot; <?= e($user['email']) ?><?= $user['is_admin'] ? ' &middot; Admin' : '' ?></p>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Change password</h2>
        <form method="post" action="/account/password" class="stack">
            <?= Csrf::field() ?>
            <label>Current password
                <input type="password" name="current_password" autocomplete="current-password" required>
            </label>
            <label>New password
                <input type="password" name="password" autocomplete="new-password" required minlength="8">
            </label>
            <label>Confirm new password
                <input type="password" name="password_confirm" autocomplete="new-password" required minlength="8">
            </label>
            <p class="hint"><?= e(App\Password::RULES) ?></p>
            <div><button class="btn btn-primary">Update password</button></div>
        </form>
    </section>

    <section class="card">
        <h2>Signed-in devices</h2>
        <ul class="devices">
            <?php foreach ($devices as $d): ?>
                <li>
                    <div>
                        <strong><?= e($d['label'] ?: 'Unknown device') ?></strong>
                        <?php if ((int) $d['id'] === Auth::deviceId()): ?><span class="chip chip-green">This device</span><?php endif; ?>
                        <div class="muted small">Signed in <?= e(fmt_dt($d['created_at'], 'm/d/Y')) ?> &middot; last active <?= e(time_ago($d['last_seen_at'])) ?></div>
                    </div>
                    <form method="post" action="/account/devices/<?= (int) $d['id'] ?>/revoke">
                        <?= Csrf::field() ?>
                        <button class="btn btn-small btn-ghost">Sign out</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($devices) > 1): ?>
            <form method="post" action="/account/devices/revoke-others">
                <?= Csrf::field() ?>
                <button class="btn btn-secondary">Sign out all other devices</button>
            </form>
        <?php endif; ?>
    </section>
</div>
