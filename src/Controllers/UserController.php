<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Password;
use App\Users;
use App\View;

/** Admin-only user management. */
final class UserController
{
	private const TRACKED = [
		'name' => 'Name', 'email' => 'Email', 'initials' => 'Initials',
		'is_admin' => 'Admin', 'is_active' => 'Active',
	];

	public function index(): void
	{
		$users = Db::all(
			'SELECT u.*,
				(SELECT COUNT(*) FROM device_sessions d WHERE d.user_id = u.id AND d.revoked_at IS NULL) AS device_count,
				(SELECT MAX(last_seen_at) FROM device_sessions d WHERE d.user_id = u.id) AS last_seen_at
			 FROM users u ORDER BY u.is_active DESC, u.name'
		);
		View::render('users/index', ['title' => 'Users', 'users' => $users]);
	}

	public function create(): void
	{
		View::render('users/form', ['title' => 'Add user', 'u' => null, 'devices' => [], 'activity' => []]);
	}

	public function store(): void
	{
		$data = $this->input();
		if ($error = $this->validate($data)) {
			keep_old($_POST);
			flash('error', $error);
			redirect('/users/new');
		}
		$id = Db::insert('users', $data);
		Activity::event('user', $id, 'Created user ' . $data['name']);
		clear_old();
		flash('success', $data['name'] . ' was added. Set a temporary password for them below.');
		redirect('/users/' . $id);
	}

	public function edit(int $id): void
	{
		$u = $this->find($id);
		$devices = Db::all('SELECT * FROM device_sessions WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC', [$id]);
		View::render('users/form', [
			'title' => $u['name'], 'u' => $u, 'devices' => $devices,
			'activity' => Activity::feed('user', $id),
		]);
	}

	public function update(int $id): void
	{
		$before = $this->find($id);
		$data = $this->input();
		$error = $this->validate($data, $id);
		if (!$error && $id === Auth::id() && (!$data['is_admin'] || !$data['is_active'])) {
			$error = 'You cannot remove your own admin access or deactivate yourself.';
		}
		if (!$error && $before['is_admin'] && (!$data['is_admin'] || !$data['is_active'])) {
			$admins = (int) Db::value('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_active = 1 AND id <> ?', [$id]);
			if ($admins === 0) {
				$error = 'At least one active admin is required.';
			}
		}
		if ($error) {
			flash('error', $error);
			redirect('/users/' . $id);
		}

		$yesNo = static fn ($v) => $v ? 'Yes' : 'No';
		Db::transaction(function () use ($id, $before, $data, $yesNo) {
			Db::update('users', $id, $data + ['updated_at' => now_utc()]);
			Activity::changes('user', $id, $before, $data, self::TRACKED, ['is_admin' => $yesNo, 'is_active' => $yesNo]);
			if ($before['is_active'] && !$data['is_active']) {
				Auth::revokeAllForUser($id);
				Activity::event('user', $id, 'Deactivated; all devices signed out');
			}
		});
		flash('success', 'Saved.');
		redirect('/users/' . $id);
	}

	public function setPassword(int $id): void
	{
		$u = $this->find($id);
		$error = Password::validate($_POST['password'] ?? '', $_POST['password_confirm'] ?? '');
		if ($error) {
			flash('error', $error);
			redirect('/users/' . $id);
		}
		Db::update('users', $id, [
			'password_hash' => Password::hash($_POST['password']),
			'password_changed_at' => now_utc(),
			'updated_at' => now_utc(),
		]);
		$signedOut = !empty($_POST['revoke_devices']) ? Auth::revokeAllForUser($id, $id === Auth::id() ? Auth::deviceId() : null) : 0;
		Activity::event('user', $id, 'Password set by ' . Auth::user()['name'] . ($signedOut ? " ($signedOut device(s) signed out)" : ''));
		flash('success', 'Password set for ' . $u['name'] . '. Share it with them privately; they can change it under My account.');
		redirect('/users/' . $id);
	}

	public function revokeDevices(int $id): void
	{
		$u = $this->find($id);
		$n = Auth::revokeAllForUser($id, $id === Auth::id() ? Auth::deviceId() : null);
		Activity::event('user', $id, "Signed out of $n device(s) by " . Auth::user()['name']);
		flash('success', "Signed {$u['name']} out of $n device(s).");
		redirect('/users/' . $id);
	}

	private function find(int $id): array
	{
		$u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
		if (!$u) {
			http_response_code(404);
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			exit;
		}
		return $u;
	}

	private function input(): array
	{
		return [
			'name'      => trim((string) ($_POST['name'] ?? '')),
			'email'     => strtolower(trim((string) ($_POST['email'] ?? ''))),
			'initials'  => Users::cleanInitials($_POST['initials'] ?? ''),
			'is_admin'  => empty($_POST['is_admin']) ? 0 : 1,
			'is_active' => empty($_POST['is_active']) ? 0 : 1,
		];
	}

	private function validate(array $data, ?int $id = null): ?string
	{
		if ($data['name'] === '') {
			return 'Name is required.';
		}
		if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
			return 'A valid email address is required.';
		}
		$dupe = Db::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$data['email'], $id ?? 0]);
		if ($dupe) {
			return 'Another user already has that email.';
		}
		return Users::initialsError($data['initials'], $id ?? 0);
	}
}
