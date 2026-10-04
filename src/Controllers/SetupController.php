<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Password;
use App\Users;
use App\View;

/**
 * First-run only: while no account has a password yet. A fresh install has no accounts, so this
 * creates the first admin; if admin accounts exist without passwords, one of them sets its
 * password here instead. After that this page permanently redirects to /login.
 */
final class SetupController
{
	public static function needed(): bool
	{
		return (int) Db::value('SELECT COUNT(*) FROM users WHERE password_hash IS NOT NULL') === 0;
	}

	public function form(): void
	{
		if (!self::needed()) {
			redirect('/login');
		}
		View::render('setup', ['title' => 'First-time setup', 'admins' => self::admins()], 'auth_layout');
	}

	public function save(): void
	{
		if (!self::needed()) {
			redirect('/login');
		}
		$admins = self::admins();
		if ($admins) {
			$userId = (int) ($_POST['user_id'] ?? 0);
			$error = in_array($userId, array_map('intval', array_column($admins, 'id')), true) ? null : 'Choose an admin account.';
		} else {
			$new = [
				'name' => trim((string) ($_POST['name'] ?? '')),
				'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
				'initials' => Users::cleanInitials($_POST['initials'] ?? ''),
				'is_admin' => 1,
				'is_active' => 1,
			];
			$error = match (true) {
				$new['name'] === '' => 'Enter your name.',
				!filter_var($new['email'], FILTER_VALIDATE_EMAIL) => 'Enter a valid email address.',
				default => Users::initialsError($new['initials'], 0),
			};
		}
		$error ??= Password::validate($_POST['password'] ?? '', $_POST['password_confirm'] ?? '');
		if ($error) {
			keep_old(array_diff_key($_POST, ['password' => 1, 'password_confirm' => 1]));
			flash('error', $error);
			redirect('/setup');
		}
		if (!$admins) {
			$userId = Db::insert('users', $new);
			Activity::event('user', $userId, 'Created as the first admin during setup', $userId);
		}
		Db::update('users', $userId, [
			'password_hash' => Password::hash($_POST['password']),
			'password_changed_at' => now_utc(),
			'updated_at' => now_utc(),
		]);
		Activity::event('user', $userId, 'Completed first-time setup and set password', $userId);
		clear_old();
		Auth::startDevice($userId);
		flash('success', 'Setup complete. Next: add the rest of the team under Users and give each a temporary password.');
		redirect('/users');
	}

	private static function admins(): array
	{
		return Db::all('SELECT id, name, email FROM users WHERE is_admin = 1 AND is_active = 1 ORDER BY id');
	}
}
