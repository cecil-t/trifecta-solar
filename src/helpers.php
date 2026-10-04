<?php
declare(strict_types=1);

use App\Config;

/** HTML-escape for output. */
function e(mixed $value): string
{
	return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * A return path from a form or link (?back=, POST back) if it is safely local, else $default.
 * Must start with a single "/"; a backslash anywhere is refused because browsers read "/\\host"
 * as "//host" (another site), and control characters are refused outright.
 */
function local_path(string $path, string $default = '/'): string
{
	return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\')
		&& !preg_match('/[\x00-\x1f\x7f]/', $path) ? $path : $default;
}

function redirect(string $path): never
{
	header('Location: ' . $path, true, 303);
	exit;
}

/** Current time as a UTC ISO-8601 string, the storage format for all timestamps. */
function now_utc(): string
{
	return gmdate('Y-m-d\TH:i:s\Z');
}

/** Format a stored UTC timestamp in local time. Dates always show the full year. */
function fmt_dt(?string $utc, string $format = 'm/d/Y g:i A'): string
{
	if (!$utc) {
		return '';
	}
	$dt = new DateTimeImmutable($utc);
	return $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($format);
}

/** Format a stored calendar date (YYYY-MM-DD) as MM/DD/YYYY. */
function fmt_date(?string $ymd): string
{
	if (!$ymd) {
		return '';
	}
	$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
	return $dt ? $dt->format('m/d/Y') : '';
}

/** "3 hours ago" style relative time for activity feeds. */
function time_ago(?string $utc): string
{
	if (!$utc) {
		return '';
	}
	$diff = time() - strtotime($utc);
	return match (true) {
		$diff < 60      => 'just now',
		$diff < 3600    => floor($diff / 60) . ' min ago',
		$diff < 86400   => floor($diff / 3600) . ' hr ago',
		$diff < 7 * 86400 => floor($diff / 86400) . ' days ago',
		default         => fmt_dt($utc, 'm/d/Y'),
	};
}

function flash(string $type, string $message): void
{
	$_SESSION['flash'][] = [$type, $message];
}

function take_flashes(): array
{
	$flashes = $_SESSION['flash'] ?? [];
	unset($_SESSION['flash']);
	return $flashes;
}

/** Remember submitted form values so a failed POST can re-fill the form. */
function old(string $key, mixed $default = ''): mixed
{
	return $_SESSION['old'][$key] ?? $default;
}

function keep_old(array $data): void
{
	$_SESSION['old'] = $data;
}

function clear_old(): void
{
	unset($_SESSION['old']);
}

/**
 * A stored link that is safe to put in an href: an http(s) address with a host, or null.
 * javascript:, data: and other schemes, and anything with control characters, are refused.
 */
function safe_url(?string $url): ?string
{
	$url = trim((string) $url);
	return preg_match('#^https?://[^\s/?\#@]+#i', $url) && !preg_match('/[\x00-\x1F\x7F\s]/', $url) ? $url : null;
}

/** Form check for a link field: null when blank or a safe http(s) address, else the message to show. */
function link_error(string $label, ?string $url): ?string
{
	return trim((string) $url) === '' || safe_url($url) !== null ? null : $label . ' must be a web address starting with https://';
}

/** Cache-busted URL for a file in public/. */
function asset(string $path): string
{
	$file = APP_ROOT . '/public/' . ltrim($path, '/');
	$version = is_file($file) ? filemtime($file) : 0;
	return '/' . ltrim($path, '/') . '?v=' . $version;
}

function is_https(): bool
{
	if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
		return true;
	}
	return Config::bool('TRUST_PROXY') && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function client_ip(): string
{
	if (Config::bool('TRUST_PROXY') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
	}
	return $_SERVER['REMOTE_ADDR'] ?? '';
}

function current_path(): string
{
	return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
}

/** Short human label for a device from its user agent, e.g. "Chrome on Android". */
function device_label(string $ua): string
{
	$browser = match (true) {
		str_contains($ua, 'Edg/')     => 'Edge',
		str_contains($ua, 'OPR/')     => 'Opera',
		str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
		str_contains($ua, 'Chrome/')  => 'Chrome',
		str_contains($ua, 'CriOS/')   => 'Chrome',
		str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/') => 'Firefox',
		str_contains($ua, 'Safari/')  => 'Safari',
		default => 'Browser',
	};
	$os = match (true) {
		str_contains($ua, 'iPhone')   => 'iPhone',
		str_contains($ua, 'iPad')     => 'iPad',
		str_contains($ua, 'Android')  => 'Android',
		str_contains($ua, 'CrOS')     => 'ChromeOS',
		str_contains($ua, 'Windows')  => 'Windows',
		str_contains($ua, 'Mac OS X') => 'Mac',
		str_contains($ua, 'Linux')    => 'Linux',
		default => 'unknown device',
	};
	return "$browser on $os";
}
