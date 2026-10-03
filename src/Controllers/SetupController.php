<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Password;
use App\View;

/**
 * First-run only: while no account has a password yet, an admin account can set its password here.
 * After that this page permanently redirects to /login.
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
        $admins = Db::all('SELECT id, name, email FROM users WHERE is_admin = 1 AND is_active = 1 ORDER BY id');
        View::render('setup', ['title' => 'First-time setup', 'admins' => $admins], 'auth_layout');
    }

    public function save(): void
    {
        if (!self::needed()) {
            redirect('/login');
        }
        $userId = (int) ($_POST['user_id'] ?? 0);
        $user = Db::one('SELECT * FROM users WHERE id = ? AND is_admin = 1 AND is_active = 1', [$userId]);
        $error = $user ? Password::validate($_POST['password'] ?? '', $_POST['password_confirm'] ?? '') : 'Choose an admin account.';
        if ($error) {
            flash('error', $error);
            redirect('/setup');
        }
        Db::update('users', $userId, [
            'password_hash' => Password::hash($_POST['password']),
            'password_changed_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
        Activity::event('user', $userId, 'Completed first-time setup and set password', $userId);
        Auth::startDevice($userId);
        flash('success', 'Setup complete. Next: set temporary passwords for the rest of the team under Users.');
        redirect('/users');
    }
}
