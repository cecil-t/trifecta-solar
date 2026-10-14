<?php
declare(strict_types=1);

namespace App;

/** Rules shared by the admin Users screen and a user's own My account page. */
final class Users
{
	/** Uppercased, trimmed initials from a form value; blank becomes null (screens fall back to the name). */
	public static function cleanInitials(mixed $value): ?string
	{
		return strtoupper(trim((string) $value)) ?: null;
	}

	/** Up to 4 letters, and not already used by another user (owner pickers show initials). */
	/**
	 * A staff phone number as entered, tidied: a plain US number (10 digits, or 11 starting with 1)
	 * becomes 717-555-0100, keeping any extension ("x12"); anything else is kept as typed.
	 * Blank is null.
	 */
	public static function cleanPhone(mixed $value): ?string
	{
		$v = trim(preg_replace('/\s+/', ' ', is_string($value) ? $value : '') ?? '');
		if ($v === '') {
			return null;
		}
		if (preg_match('/^(.*?)\s*(?:x|ext\.?|extension)\s*(\d+)$/i', $v, $m)) {
			[$main, $ext] = [$m[1], $m[2]];
		} else {
			[$main, $ext] = [$v, ''];
		}
		$digits = preg_replace('/\D/', '', $main) ?? '';
		if (strlen($digits) === 11 && $digits[0] === '1') {
			$digits = substr($digits, 1);
		}
		if (strlen($digits) === 10 && preg_match('/^[\d\s().+\-]+$/', $main)) {
			return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6) . ($ext !== '' ? ' x' . $ext : '');
		}
		return mb_substr($v, 0, 40);
	}

	public static function initialsError(?string $initials, int $userId): ?string
	{
		if ($initials === null) {
			return null;
		}
		if (!preg_match('/^[A-Z]{1,4}$/', $initials)) {
			return 'Initials must be 1 to 4 letters.';
		}
		$dupe = Db::value('SELECT name FROM users WHERE UPPER(initials) = ? AND id <> ?', [$initials, $userId]);
		return $dupe ? 'Those initials are already used by ' . $dupe . '.' : null;
	}
}
