<?php
declare(strict_types=1);

namespace App\Controllers;

/**
 * The web app manifest, served from PHP so the icon URLs carry a version (asset()). An installed
 * app then picks up new icons instead of reusing week-old cached copies at the same URLs.
 */
final class ManifestController
{
	private const ICONS = [
		['assets/img/icon-192.png', '192x192', null],
		['assets/img/icon-512.png', '512x512', null],
		['assets/img/icon-maskable-512.png', '512x512', 'maskable'],
	];

	public function show(): void
	{
		$icons = [];
		foreach (self::ICONS as [$path, $sizes, $purpose]) {
			$icons[] = ['src' => asset($path), 'sizes' => $sizes, 'type' => 'image/png'] + ($purpose ? ['purpose' => $purpose] : []);
		}
		header('Content-Type: application/manifest+json');
		header('Cache-Control: no-cache');
		echo json_encode([
			'name' => 'Trifecta Solar Ops Tracker',
			'short_name' => 'Trifecta',
			'start_url' => '/',
			'scope' => '/',
			'display' => 'standalone',
			'background_color' => '#f3f5f2',
			'theme_color' => '#025586',
			'icons' => $icons,
		], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
	}

	/** Changes whenever an icon file changes, for the manifest link's ?v=. */
	public static function version(): int
	{
		$v = 0;
		foreach (self::ICONS as [$path]) {
			$v = max($v, (int) @filemtime(APP_ROOT . '/public/' . $path));
		}
		return $v;
	}
}
