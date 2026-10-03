<h1 class="auth-title">First-time setup</h1>
<p class="muted small">Set the password for an admin account. This page only works until the first password is set.</p>
<form method="post" action="/setup" class="stack">
    <?= App\Csrf::field() ?>
    <label>Admin account
        <select name="user_id" required>
            <?php foreach ($admins as $a): ?>
                <option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?> (<?= e($a['email']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Password
        <input type="password" name="password" autocomplete="new-password" required minlength="8">
    </label>
    <label>Confirm password
        <input type="password" name="password_confirm" autocomplete="new-password" required minlength="8">
    </label>
    <p class="hint"><?= e(App\Password::RULES) ?></p>
    <button type="submit" class="btn btn-primary btn-block">Save and sign in</button>
</form>
