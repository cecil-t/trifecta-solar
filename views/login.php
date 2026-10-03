<h1 class="auth-title">Sign in</h1>
<form method="post" action="/login" class="stack">
    <?= App\Csrf::field() ?>
    <label>Email
        <input type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="username" required autofocus>
    </label>
    <label>Password
        <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    <p class="muted small center">You'll stay signed in on this device until you sign out.</p>
</form>
<?php clear_old(); ?>
