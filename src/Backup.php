<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Consistent SQLite snapshots via VACUUM INTO (safe while the app is running),
 * each verified with an integrity check, with old snapshots pruned.
 */
final class Backup
{
    public static function dir(): string
    {
        return Config::get('BACKUP_DIR', APP_ROOT . '/backups');
    }

    /** @return array{file:string,bytes:int,pruned:int} */
    public static function run(?int $keep = null): array
    {
        $keep ??= (int) Config::get('BACKUP_KEEP', '30');
        $dir = self::dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/trifecta-' . date('Ymd-His') . '.sqlite';
        for ($n = 2; file_exists($file); $n++) {
            $file = $dir . '/trifecta-' . date('Ymd-His') . "-$n.sqlite";
        }

        Db::pdo()->prepare('VACUUM INTO ?')->execute([$file]);

        $check = new PDO('sqlite:' . $file);
        $result = $check->query('PRAGMA integrity_check')->fetchColumn();
        $check = null;
        if ($result !== 'ok') {
            throw new RuntimeException("Backup written but failed integrity check: $result");
        }

        $all = glob($dir . '/trifecta-*.sqlite') ?: [];
        rsort($all, SORT_STRING);
        $pruned = 0;
        foreach (array_slice($all, max($keep, 1)) as $old) {
            unlink($old);
            $pruned++;
        }
        return ['file' => $file, 'bytes' => filesize($file), 'pruned' => $pruned];
    }
}
