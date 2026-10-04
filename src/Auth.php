<?php
declare(strict_types=1);

namespace App;

/**
 * Email + password login with persistent per-device sessions.
 *
 * On login a random 256-bit token is placed in a long-lived HttpOnly cookie and its SHA-256
 * hash is stored in device_sessions. Sessions never expire on their own; they end only when
 * the user logs out on that device, or the device is revoked (by the user or an admin).
 * Browsers cap cookie lifetime (Chrome: 400 days), so the cookie is re-issued whenever it's used.
 */
final class Auth
{
	private const COOKIE = 'tsd';
	private const COOKIE_DAYS = 400;
	private const MAX_FAILS_PER_EMAIL = 8;
	private const MAX_FAILS_PER_IP = 25;
	private const LOCK_MINUTES = 15;

	private static ?array $user = null;
	private static ?int $deviceId = null;

	public static function user(): ?array
	{
		return self::$user;
	}

	public static function id(): ?int
	{
		return self::$user ? (int) self::$user['id'] : null;
	}

	public static function isAdmin(): bool
	{
		return (bool) (self::$user['is_admin'] ?? false);
	}

	public static function deviceId(): ?int
	{
		return self::$deviceId;
	}

	private const USER_SQL = 'SELECT u.id, u.email, u.name, u.initials, u.is_admin, u.is_active,
			d.id AS device_id, d.last_seen_at
		FROM device_sessions d JOIN users u ON u.id = d.user_id
		WHERE d.revoked_at IS NULL AND u.is_active = 1 AND u.password_hash IS NOT NULL';

	/** Resolve the current user from the PHP session, falling back to the device cookie. */
	public static function init(): void
	{
		if (isset($_SESSION['device_id'])) {
			$row = Db::one(self::USER_SQL . ' AND d.id = ?', [$_SESSION['device_id']]);
			if ($row) {
				self::setCurrent($row);
				return;
			}
			unset($_SESSION['device_id']);
		}

		$token = $_COOKIE[self::COOKIE] ?? '';
		if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
			$row = Db::one(self::USER_SQL . ' AND d.token_hash = ?', [hash('sha256', $token)]);
			if ($row) {
				session_regenerate_id(true);
				$_SESSION['device_id'] = (int) $row['device_id'];
				self::setCookie($token); // slide the browser's expiry forward
				self::setCurrent($row);
				return;
			}
			self::clearCookie();
		}
	}

	private static function setCurrent(array $row): void
	{
		self::$user = $row;
		self::$deviceId = (int) $row['device_id'];
		$stale = !$row['last_seen_at'] || strtotime($row['last_seen_at']) < time() - 600;
		if ($stale) {
			Db::run('UPDATE device_sessions SET last_seen_at = ?, last_ip = ? WHERE id = ?', [now_utc(), client_ip(), self::$deviceId]);
		}
	}

	/** @return string|null error message, or null on success */
	public static function attempt(string $email, string $password): ?string
	{
		$email = strtolower(trim($email));
		$ip = client_ip();
		$since = gmdate('Y-m-d\TH:i:s\Z', time() - self::LOCK_MINUTES * 60);

		$fails = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND email = ? AND created_at > ?', [$email, $since]);
		$ipFails = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND ip = ? AND created_at > ?', [$ip, $since]);
		if ($fails >= self::MAX_FAILS_PER_EMAIL || $ipFails >= self::MAX_FAILS_PER_IP) {
			return 'Too many failed attempts. Please wait ' . self::LOCK_MINUTES . ' minutes and try again.';
		}

		$user = Db::one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
		// Verify against a dummy hash when the user doesn't exist so timing doesn't reveal valid emails.
		$hash = $user['password_hash'] ?? Password::hash(bin2hex(random_bytes(8)));
		$ok = $user && $user['password_hash'] && password_verify($password, $hash);
		if (!$user || !$user['password_hash']) {
			password_verify($password, $hash); // burn equivalent time
		}

		Db::insert('login_attempts', ['email' => $email, 'ip' => $ip, 'success' => $ok ? 1 : 0]);
		if (!$ok) {
			return 'That email and password combination was not recognized.';
		}

		if (Password::needsRehash($user['password_hash'])) {
			Db::update('users', (int) $user['id'], ['password_hash' => Password::hash($password)]);
		}
		self::startDevice((int) $user['id']);
		return null;
	}

	/** Create a device session for the user and sign this browser in. */
	public static function startDevice(int $userId): void
	{
		$token = bin2hex(random_bytes(32));
		$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
		$deviceId = Db::insert('device_sessions', [
			'user_id'      => $userId,
			'token_hash'   => hash('sha256', $token),
			'label'        => device_label($ua),
			'user_agent'   => $ua,
			'created_ip'   => client_ip(),
			'last_ip'      => client_ip(),
			'last_seen_at' => now_utc(),
		]);
		session_regenerate_id(true);
		$_SESSION['device_id'] = $deviceId;
		self::setCookie($token);
	}

	public static function logout(): void
	{
		if (self::$deviceId) {
			Db::run('UPDATE device_sessions SET revoked_at = ? WHERE id = ?', [now_utc(), self::$deviceId]);
		}
		self::clearCookie();
		$_SESSION = [];
		session_regenerate_id(true);
		self::$user = null;
		self::$deviceId = null;
	}

	public static function revokeDevice(int $deviceId, ?int $userId = null): void
	{
		$sql = 'UPDATE device_sessions SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL';
		$params = [now_utc(), $deviceId];
		if ($userId !== null) {
			$sql .= ' AND user_id = ?';
			$params[] = $userId;
		}
		Db::run($sql, $params);
	}

	public static function revokeAllForUser(int $userId, ?int $exceptDeviceId = null): int
	{
		$sql = 'UPDATE device_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL';
		$params = [now_utc(), $userId];
		if ($exceptDeviceId) {
			$sql .= ' AND id <> ?';
			$params[] = $exceptDeviceId;
		}
		return Db::run($sql, $params)->rowCount();
	}

	private static function setCookie(string $token): void
	{
		setcookie(self::COOKIE, $token, [
			'expires'  => time() + self::COOKIE_DAYS * 86400,
			'path'     => '/',
			'secure'   => is_https(),
			'httponly' => true,
			'samesite' => 'Lax',
		]);
	}

	private static function clearCookie(): void
	{
		setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
	}
}
