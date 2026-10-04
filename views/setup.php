<h1 class="auth-title">First-time setup</h1>
<?php if ($admins): ?>
	<p class="muted small">Set the password for an admin account. This page only works until the first password is set.</p>
<?php else: ?>
	<p class="muted small">Create the first admin account. This page only works until it has been used once.</p>
<?php endif; ?>
<form method="post" action="/setup" class="stack">
	<?= App\Csrf::field() ?>
	<?php if ($admins): ?>
		<label>Admin account
			<select name="user_id" required>
				<?php foreach ($admins as $a): ?>
					<option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?> (<?= e($a['email']) ?>)</option>
				<?php endforeach; ?>
			</select>
		</label>
	<?php else: ?>
		<label>Full name
			<input type="text" name="name" value="<?= e(old('name')) ?>" autocomplete="name" required>
		</label>
		<label>Email (used to sign in)
			<input type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="username" required>
		</label>
		<label>Initials
			<input type="text" name="initials" value="<?= e(old('initials')) ?>" maxlength="4" pattern="[A-Za-z]{1,4}" class="input-short">
		</label>
	<?php endif; ?>
	<label>Password
		<input type="password" name="password" autocomplete="new-password" required minlength="8">
	</label>
	<label>Confirm password
		<input type="password" name="password_confirm" autocomplete="new-password" required minlength="8">
	</label>
	<p class="hint"><?= e(App\Password::RULES) ?></p>
	<button type="submit" class="btn btn-primary btn-block">Save and sign in</button>
</form>
<?php clear_old(); ?>
