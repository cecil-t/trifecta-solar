<?php
declare(strict_types=1);

namespace App;

/**
 * The deployed code version, read straight from the repo's .git folder (no git binary
 * needed inside the container). Handles loose objects (what a small `git pull` writes)
 * and packed objects (a fresh clone); a deltified commit in a pack just leaves out the
 * message and date.
 */
final class Version
{
    public const REPO_URL = 'https://github.com/cecil-t/trifecta-solar';

    /** @return array{hash:string,short:string,branch:?string,subject:?string,author:?string,committed:?int,updated:?int}|null */
    public static function info(): ?array
    {
        $git = APP_ROOT . '/.git';
        $head = is_file($git . '/HEAD') ? trim((string) @file_get_contents($git . '/HEAD')) : '';
        if ($head === '') {
            return null;
        }
        $branch = null;
        $hash = $head;
        $refFile = $git . '/HEAD';
        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $branch = preg_replace('#^refs/heads/#', '', $ref);
            $refFile = $git . '/' . $ref;
            $hash = is_file($refFile) ? trim((string) @file_get_contents($refFile)) : self::packedRef($git, $ref);
            if (!is_file($refFile)) {
                $refFile = $git . '/packed-refs';
            }
        }
        if (!is_string($hash) || !preg_match('/^[0-9a-f]{40}$/', $hash)) {
            return null;
        }
        $info = ['hash' => $hash, 'short' => substr($hash, 0, 7), 'branch' => $branch, 'subject' => null,
            'author' => null, 'committed' => null, 'updated' => @filemtime($refFile) ?: null];
        $body = self::readObject($git, $hash);
        if ($body !== null) {
            if (preg_match('/^committer .*? (\d+) [+-]\d{4}$/m', $body, $m)) {
                $info['committed'] = (int) $m[1];
            }
            if (preg_match('/^author (.+?) </m', $body, $m)) {
                $info['author'] = $m[1];
            }
            $parts = explode("\n\n", $body, 2);
            $info['subject'] = isset($parts[1]) ? trim(strtok($parts[1], "\n")) : null;
        }
        return $info;
    }

    private static function packedRef(string $git, string $ref): ?string
    {
        foreach (@file($git . '/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_ends_with($line, ' ' . $ref)) {
                return substr($line, 0, 40);
            }
        }
        return null;
    }

    /** Body of a commit object (without the "commit <size>\0" header), or null. */
    private static function readObject(string $git, string $hash): ?string
    {
        $loose = $git . '/objects/' . substr($hash, 0, 2) . '/' . substr($hash, 2);
        if (is_file($loose)) {
            $raw = @gzuncompress((string) file_get_contents($loose));
            if (is_string($raw) && str_starts_with($raw, 'commit ') && ($nul = strpos($raw, "\0")) !== false) {
                return substr($raw, $nul + 1);
            }
            return null;
        }
        foreach (glob($git . '/objects/pack/pack-*.idx') ?: [] as $idx) {
            $offset = self::packOffset($idx, hex2bin($hash));
            if ($offset !== null) {
                return self::readPacked(substr($idx, 0, -4) . '.pack', $offset);
            }
        }
        return null;
    }

    /** Offset of an object in a version 2 pack index, or null. */
    private static function packOffset(string $idxFile, string $sha): ?int
    {
        $f = @fopen($idxFile, 'rb');
        if (!$f) {
            return null;
        }
        try {
            if (fread($f, 8) !== "\xfftOc\x00\x00\x00\x02") {
                return null;
            }
            $fanout = array_values(unpack('N256', fread($f, 1024)));
            $total = $fanout[255];
            $first = ord($sha[0]);
            $lo = $first === 0 ? 0 : $fanout[$first - 1];
            $hi = $fanout[$first] - 1;
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                fseek($f, 8 + 1024 + $mid * 20);
                $cmp = strcmp(fread($f, 20), $sha);
                if ($cmp === 0) {
                    fseek($f, 8 + 1024 + $total * 24 + $mid * 4);
                    $off = unpack('N', fread($f, 4))[1];
                    if ($off & 0x80000000) {           // large offset table
                        fseek($f, 8 + 1024 + $total * 28 + ($off & 0x7fffffff) * 8);
                        $p = unpack('N2', fread($f, 8));
                        $off = ($p[1] << 32) | $p[2];
                    }
                    return $off;
                }
                $cmp < 0 ? $lo = $mid + 1 : $hi = $mid - 1;
            }
            return null;
        } finally {
            fclose($f);
        }
    }

    private static function readPacked(string $packFile, int $offset): ?string
    {
        $f = @fopen($packFile, 'rb');
        if (!$f) {
            return null;
        }
        try {
            fseek($f, $offset);
            $b = ord(fread($f, 1));
            $type = ($b >> 4) & 7;
            $size = $b & 15;
            $shift = 4;
            while ($b & 0x80) {
                $b = ord(fread($f, 1));
                $size |= ($b & 0x7f) << $shift;
                $shift += 7;
            }
            if ($type !== 1 || $size > 1_000_000) {    // 1 = commit; deltas are not resolved
                return null;
            }
            $ctx = inflate_init(ZLIB_ENCODING_DEFLATE);
            $out = '';
            while (strlen($out) < $size && !feof($f)) {
                $chunk = fread($f, 4096);
                $out .= (string) @inflate_add($ctx, $chunk, ZLIB_SYNC_FLUSH);
                if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                    break;
                }
            }
            return strlen($out) >= $size ? substr($out, 0, $size) : null;
        } finally {
            fclose($f);
        }
    }
}
