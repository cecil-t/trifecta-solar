<?php
declare(strict_types=1);

namespace App;

/**
 * HTML to PDF with WeasyPrint (installed in /opt/pdf by the Dockerfile). The HTML is a complete
 * document built for print: CSS @page rules set the paper, margins, headers and footers, and charts
 * are SVG drawn on the server (WeasyPrint runs no JavaScript).
 *
 * Assets resolve against public/, so templates refer to them relatively (assets/img/logo-horizontal.png,
 * assets/css/pdf.css), never with a leading slash.
 *
 * PDF_PYTHON in .env overrides the Python path (for development outside Docker).
 */
final class Pdf
{
	public const TIMEOUT = 60;

	public static function python(): string
	{
		return Config::get('PDF_PYTHON', '/opt/pdf/bin/python');
	}

	public static function installed(): bool
	{
		return is_executable(self::python());
	}

	/**
	 * Render a document. Returns ok, and pdf (the bytes) or error, plus ms.
	 *
	 * @return array{ok:bool, pdf?:string, error?:string, ms:int}
	 */
	public static function fromHtml(string $html, int $timeout = self::TIMEOUT): array
	{
		$start = microtime(true);
		$done = static fn (array $r): array => $r + ['ms' => (int) round((microtime(true) - $start) * 1000)];
		if (!self::installed()) {
			return $done(['ok' => false, 'error' => 'WeasyPrint is not installed on this server (no ' . self::python() . '). Rebuild the Docker image.']);
		}
		$base = 'file://' . APP_ROOT . '/public/';
		// -I: isolated mode, so nothing in the working folder or environment can change what Python loads
		$r = Process::run([self::python(), '-I', '-m', 'weasyprint', '--quiet', '--base-url', $base, '-', '-'], $html, $timeout,
			['PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'HOME' => sys_get_temp_dir()]);
		if ($r['timed_out']) {
			return $done(['ok' => false, 'error' => 'WeasyPrint did not finish within ' . $timeout . ' seconds.']);
		}
		if ($r['code'] !== 0 || !str_starts_with($r['out'], '%PDF-')) {
			$why = trim(substr($r['err'], -1500));
			return $done(['ok' => false, 'error' => 'WeasyPrint failed (exit ' . $r['code'] . ')' . ($why !== '' ? ': ' . $why : '.')]);
		}
		return $done(['ok' => true, 'pdf' => $r['out']]);
	}
}
