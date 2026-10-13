<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\View;

/**
 * Lists > Display links (admin): named keys for office screens. Each key opens /display?key=... ,
 * the Monitor page without navigation, with no sign-in; it never reaches any other page. Revoking a
 * key stops its link right away.
 */
final class DisplayKeyController
{
	public function index(): void
	{
		$keys = Db::all(
			'SELECT k.*, u.name AS created_by_name FROM display_keys k LEFT JOIN users u ON u.id = k.created_by
			 ORDER BY k.revoked_at IS NOT NULL, k.created_at DESC'
		);
		View::render('display_keys/index', ['title' => 'Display links', 'keys' => $keys, 'base' => self::base()]);
	}

	public function store(): void
	{
		$name = $this->name();
		if ($name === null) {
			flash('error', 'Give the link a name (up to 80 characters), such as "Office TV".');
			redirect('/admin/display-links');
		}
		$id = Db::insert('display_keys', [
			'name' => $name,
			'token' => rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='),
			'created_at' => now_utc(),
			'created_by' => Auth::id(),
		]);
		Activity::event('display_key', $id, 'Created display link "' . $name . '"');
		flash('success', 'Display link "' . $name . '" created. Open it on the screen it is for.');
		redirect('/admin/display-links');
	}

	public function rename(int $id): void
	{
		$key = $this->find($id);
		$name = $this->name();
		if ($name === null) {
			flash('error', 'A name is needed (up to 80 characters).');
			redirect('/admin/display-links');
		}
		if ($name !== $key['name']) {
			Db::update('display_keys', $id, ['name' => $name]);
			Activity::event('display_key', $id, 'Renamed display link "' . $key['name'] . '" to "' . $name . '"');
			flash('success', 'Renamed to "' . $name . '".');
		}
		redirect('/admin/display-links');
	}

	public function revoke(int $id): void
	{
		$key = $this->find($id);
		if ($key['revoked_at'] === null) {
			Db::update('display_keys', $id, ['revoked_at' => now_utc()]);
			Activity::event('display_key', $id, 'Revoked display link "' . $key['name'] . '"');
			flash('success', 'Display link "' . $key['name'] . '" revoked; its screen stops updating at the next reload.');
		}
		redirect('/admin/display-links');
	}

	private function name(): ?string
	{
		$name = trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? '')) ?? '');
		return $name !== '' && mb_strlen($name) <= 80 ? $name : null;
	}

	private function find(int $id): array
	{
		$key = Db::one('SELECT * FROM display_keys WHERE id = ?', [$id]);
		if (!$key) {
			flash('error', 'That display link no longer exists.');
			redirect('/admin/display-links');
		}
		return $key;
	}

	/** This site's address, for showing the full link to copy. */
	private static function base(): string
	{
		$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
		if (!preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host)) {
			$host = 'localhost';
		}
		return (is_https() ? 'https://' : 'http://') . $host;
	}
}
