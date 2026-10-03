<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Password;
use App\View;

/** The signed-in user's own profile: password and devices. */
final class AccountController
{
    public function show(): void
    {
        $devices = Db::all(
            'SELECT * FROM device_sessions WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC',
            [Auth::id()]
        );
        View::render('account', ['title' => 'My account', 'user' => Auth::user(), 'devices' => $devices]);
    }

    public function password(): void
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [Auth::id()]);
        if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $user['password_hash'])) {
            flash('error', 'Your current password was not correct.');
            redirect('/account');
        }
        $error = Password::validate($_POST['password'] ?? '', $_POST['password_confirm'] ?? '');
        if ($error) {
            flash('error', $error);
            redirect('/account');
        }
        Db::update('users', Auth::id(), [
            'password_hash' => Password::hash($_POST['password']),
            'password_changed_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        Activity::event('user', Auth::id(), 'Changed their password');
        flash('success', 'Password updated. Your other devices stay signed in; sign them out below if needed.');
        redirect('/account');
    }

    public function revokeDevice(int $id): void
    {
        Auth::revokeDevice($id, Auth::id());
        if ($id === Auth::deviceId()) {
            Auth::logout();
            redirect('/login');
        }
        flash('success', 'That device has been signed out.');
        redirect('/account');
    }

    public function revokeOthers(): void
    {
        $n = Auth::revokeAllForUser(Auth::id(), Auth::deviceId());
        flash('success', $n === 1 ? 'Signed out 1 other device.' : "Signed out $n other devices.");
        redirect('/account');
    }
}
