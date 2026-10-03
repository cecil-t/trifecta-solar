<?php
declare(strict_types=1);

namespace App;

final class Password
{
    public const RULES = '8+ characters with an uppercase letter, a lowercase letter, a number, and a symbol.';

    /** @return string[] list of unmet requirements (empty = acceptable) */
    public static function problems(string $password): array
    {
        $problems = [];
        if (mb_strlen($password) < 8) {
            $problems[] = 'at least 8 characters';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $problems[] = 'a lowercase letter';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $problems[] = 'an uppercase letter';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $problems[] = 'a number';
        }
        if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            $problems[] = 'a symbol';
        }
        return $problems;
    }

    public static function validate(string $password, string $confirm): ?string
    {
        if ($password !== $confirm) {
            return 'The two passwords do not match.';
        }
        $problems = self::problems($password);
        return $problems ? 'Password needs ' . implode(', ', $problems) . '.' : null;
    }

    private static function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /** Salted Argon2id hash (bcrypt fallback). The salt is generated and embedded by PHP. */
    public static function hash(string $password): string
    {
        return password_hash($password, self::algo());
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo());
    }
}
