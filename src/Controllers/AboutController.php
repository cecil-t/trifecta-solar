<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Backup;
use App\Db;
use App\Migrator;
use App\ServerInfo;
use App\Version;
use App\View;

/** About this app: credits, the deployed code version, where it runs, and the data it holds. */
final class AboutController
{
	public function show(): void
	{
		$dbPath = Db::path();
		$dbBytes = (@filesize($dbPath) ?: 0) + (@filesize($dbPath . '-wal') ?: 0);

		$os = null;
		if (is_readable('/etc/os-release') && preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', (string) file_get_contents('/etc/os-release'), $m)) {
			$os = $m[1];
		}
		$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

		$backups = glob(rtrim(Backup::dir(), '/') . '/trifecta-*.sqlite') ?: [];
		usort($backups, static fn ($a, $b) => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));
		$lastBackup = $backups ? ['time' => @filemtime($backups[0]) ?: null, 'bytes' => @filesize($backups[0]) ?: 0] : null;

		$applied = Migrator::applied();
		View::render('about', [
			'title' => 'About',
			'version' => Version::info(),
			'repo' => Version::REPO_URL,
			'host' => [
				'url' => ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''),
				'server' => $_SERVER['SERVER_SOFTWARE'] ?? null,
				'apache' => self::apacheVersion(),
				'php' => PHP_VERSION . ' (' . PHP_SAPI . ')',
				'os' => $os,
				'docker' => is_file('/.dockerenv'),
				'hostname' => gethostname() ?: null,
				'timezone' => date_default_timezone_get(),
				'server_time' => time(),
				'sqlite' => (string) Db::value('SELECT sqlite_version()'),
				'journal' => strtoupper((string) Db::value('PRAGMA journal_mode')),
				'db_bytes' => $dbBytes,
			],
			'server' => ServerInfo::all() + [
				'disk_free' => @disk_free_space(dirname($dbPath)) ?: null,
				'disk_total' => @disk_total_space(dirname($dbPath)) ?: null,
			],
			'stats' => [
				'projects' => (int) Db::value('SELECT COUNT(*) FROM projects'),
				'service' => (int) Db::value('SELECT COUNT(*) FROM service_tickets'),
				'tasks' => (int) Db::value('SELECT COUNT(*) FROM todos'),
				'customers' => (int) Db::value("SELECT COUNT(*) FROM organizations WHERE type = 'customer'"),
				'users' => (int) Db::value('SELECT COUNT(*) FROM users'),
				'schema' => $applied ? end($applied) : null,
				'migrations' => count($applied),
				'last_backup' => $lastBackup,
				'backup_count' => count($backups),
			],
		]);
	}

	/**
	 * Apache's version. ServerTokens Prod hides it from SERVER_SOFTWARE (and from response
	 * headers, on purpose), so read the installed Debian package instead; the php:apache
	 * image installs Apache with apt. Returns e.g. ['2.4.65', '2.4.65-1~deb13u1'], or null.
	 */
	private static function apacheVersion(): ?array
	{
		$status = @file_get_contents('/var/lib/dpkg/status');
		if (!$status || !str_starts_with((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'Apache')) {
			return null;
		}
		foreach (['apache2-bin', 'apache2'] as $package) {
			if (preg_match('/^Package: ' . $package . '\n(?:[^\n]+\n)*?Version: ([^\n]+)/m', $status, $m)) {
				$full = trim($m[1]);
				return [preg_replace('/^\d+:|-[^-]+$/', '', $full), $full];
			}
		}
		return null;
	}
}
