<?php
declare(strict_types=1);

namespace App;

final class Csrf
{
	public static function token(): string
	{
		if (empty($_SESSION['csrf'])) {
			$_SESSION['csrf'] = bin2hex(random_bytes(32));
		}
		return $_SESSION['csrf'];
	}

	public static function field(): string
	{
		return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
	}

	public static function valid(): bool
	{
		$sent = $_POST['_csrf'] ?? '';
		return is_string($sent) && $sent !== '' && hash_equals(self::token(), $sent);
	}
}
