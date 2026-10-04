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
