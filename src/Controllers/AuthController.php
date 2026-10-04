<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\View;

final class AuthController
{
	public function form(): void
	{
		if (SetupController::needed()) {
			redirect('/setup');
		}
		if (Auth::user()) {
			redirect('/');
		}
		View::render('login', ['title' => 'Sign in'], 'auth_layout');
	}

	public function login(): void
	{
		$email = (string) ($_POST['email'] ?? '');
		$error = Auth::attempt($email, (string) ($_POST['password'] ?? ''));
		if ($error) {
			keep_old(['email' => $email]);
			flash('error', $error);
			redirect('/login');
		}
		clear_old();
		$next = $_SESSION['after_login'] ?? '/';
		unset($_SESSION['after_login']);
		// Only allow local paths as a post-login destination.
		redirect(str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/');
	}

	public function logout(): void
	{
		Auth::logout();
		flash('success', 'You have been signed out on this device.');
		redirect('/login');
	}
}
