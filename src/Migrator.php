<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Applies migrations/NNN_name.sql files in order, once each, inside a transaction.
 * Never edit a migration that has already run anywhere; add a new numbered file instead.
 */
final class Migrator
{
	public static function files(): array
	{
		$files = glob(APP_ROOT . '/migrations/*.sql') ?: [];
		sort($files, SORT_STRING);
		return $files;
	}

	public static function applied(): array
	{
		$pdo = Db::pdo();
		$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
		return $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
	}

	public static function hasPending(): bool
	{
		return count(self::files()) > count(self::applied());
	}

	/** @return string[] versions applied by this run */
	public static function migrate(): array
	{
		$pdo = Db::pdo();
		$lock = fopen(dirname(Db::path()) . '/.migrate.lock', 'c');
		flock($lock, LOCK_EX);
		try {
			$done = array_flip(self::applied());
			$ran = [];
			foreach (self::files() as $file) {
				$version = basename($file, '.sql');
				if (isset($done[$version])) {
					continue;
				}
				$sql = file_get_contents($file);
				// A table rebuild (SQLite can't change a CHECK constraint in place) must run with
				// foreign keys off, or dropping the old table cascades into its child tables.
				// The pragma is ignored inside a transaction, so it is set before BEGIN. Such a file
				// must keep every id it copies, so references stay valid.
				$fkOff = (bool) preg_match('/^-- migrator: foreign_keys off\s*$/m', $sql);
				if ($fkOff) {
					$pdo->exec('PRAGMA foreign_keys = OFF');
				}
				$pdo->beginTransaction();
				try {
					$pdo->exec($sql);
					$pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')
						->execute([$version, now_utc()]);
					$pdo->commit();
					$ran[] = $version;
				} catch (\Throwable $e) {
					$pdo->rollBack();
					throw new RuntimeException("Migration $version failed: " . $e->getMessage(), 0, $e);
				} finally {
					if ($fkOff) {
						$pdo->exec('PRAGMA foreign_keys = ON');
					}
				}
			}
			return $ran;
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}
}
