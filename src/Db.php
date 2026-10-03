<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOStatement;

/** Thin wrapper around a single SQLite connection. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function path(): string
    {
        return Config::get('DB_PATH', APP_ROOT . '/data/trifecta.sqlite');
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $path = self::path();
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // WAL lets readers and the single writer work at the same time;
            // busy_timeout makes concurrent writes wait instead of failing.
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Insert a row. $table and keys are trusted internal identifiers; values are bound. */
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        self::run($sql, array_values($row));
        return (int) self::pdo()->lastInsertId();
    }

    /** Update a row by id. $table and keys are trusted internal identifiers; values are bound. */
    public static function update(string $table, int $id, array $row): void
    {
        $sets = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($row)));
        self::run("UPDATE $table SET $sets WHERE id = ?", [...array_values($row), $id]);
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
