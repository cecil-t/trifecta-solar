<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Designs;
use App\Geo;
use App\GoogleMaps;
use App\Layout;
use App\Presales;
use App\View;

/**
 * The site layout page (panels drawn on the satellite view) and what it calls: save, Google's roof
 * faces, satellite tiles and their attribution. Tiles and roof data come through the server so the
 * Google key stays here and the Content Security Policy stays as it is.
 */
final class LayoutController
{
	public function show(int $id, int $designId): void
	{
		[$o, $d] = $this->find($id, $designId);
		$lines = Designs::lines($designId);
		$module = Layout::module($lines);
		$layout = Layout::decode($d['layout_json']);
		$origin = $layout['origin'] ?? null;
		$originNote = '';
		if (!$origin && $o['site_lat'] !== null && $o['site_lon'] !== null) {
			$origin = ['lat' => (float) $o['site_lat'], 'lon' => (float) $o['site_lon']];
		}
		if (!$origin && GoogleMaps::configured()) {
			$address = Geo::address($o['site_street'], $o['site_city'], $o['site_state'], $o['site_zip']);
			$g = $address !== '' ? GoogleMaps::geocode($address) : ['ok' => false];
			if ($g['ok']) {
				$origin = ['lat' => $g['lat'], 'lon' => $g['lon']];
				$originNote = 'Started from the site address, since the opportunity has no map point.';
			}
		}
		$base = '/presales/' . $id . '/designs/' . $designId;
		$config = [
			'origin' => $origin,
			'module' => $module,
			'gap' => ['panel' => Presales::num('layout_gap_in') * 0.0254, 'row' => Presales::num('layout_row_gap_in') * 0.0254],
			'layout' => $layout,
			'tiles' => '/presales/layout-tiles/',
			'viewport' => '/presales/layout-viewport',
			'roof' => $base . '/layout/roof',
			'placeholder' => !GoogleMaps::configured(),
		];
		View::render('presales/layout', [
			'title' => 'Site layout - ' . $d['name'], 'wide' => true,
			'o' => $o, 'd' => $d, 'base' => $base, 'config' => $config, 'module' => $module, 'origin' => $origin, 'originNote' => $originNote,
			'who' => $d['layout_by'] ? Db::value('SELECT name FROM users WHERE id = ?', [(int) $d['layout_by']]) : null,
		], 'layout');
	}

	public function save(int $id, int $designId): void
	{
		[, $d] = $this->find($id, $designId);
		$base = '/presales/' . $id . '/designs/' . $designId;
		try {
			$layout = Layout::fromInput((string) ($_POST['layout'] ?? ''));
		} catch (\InvalidArgumentException $e) {
			flash('error', $e->getMessage() . ' Nothing was saved.');
			redirect($base . '/layout');
		}
		$before = Layout::decode($d['layout_json']);
		$changes = Db::transaction(static function () use ($d, $layout): array {
			Db::update('presales_designs', (int) $d['id'], ['layout_json' => json_encode($layout), 'layout_at' => now_utc(), 'layout_by' => Auth::id()]);
			$changes = Layout::syncArrays($d, $layout);
			// Price and size follow the new panel count
			$fresh = Designs::find((int) $d['id']);
			$calc = Designs::calc($fresh, Designs::lines((int) $d['id']), Designs::arrays((int) $d['id']));
			Db::update('presales_designs', (int) $d['id'], ['price_total' => $calc['price'], 'dc_watts' => $calc['dc_watts'],
				'updated_by' => Auth::id(), 'updated_at' => now_utc()]);
			if (abs((float) $d['price_total'] - $calc['price']) > 0.5) {
				$changes[] = 'price ' . Presales::money((float) $d['price_total']) . ' to ' . Presales::money($calc['price']);
			}
			Db::update('presales_opportunities', (int) $d['opportunity_id'], ['updated_at' => now_utc()]);
			return $changes;
		});
		$n = Layout::panels($layout);
		$what = $before ? 'saved' : 'drawn';
		Activity::event('presales', $id, 'Site layout for "' . $d['name'] . '" ' . $what . ': ' . $n . ' panels on ' . count($layout['arrays']) . ' '
			. (count($layout['arrays']) === 1 ? 'array' : 'arrays') . ($changes ? '; ' . implode(', ', $changes) : ''));
		$typed = (int) Db::value('SELECT COUNT(*) FROM presales_design_arrays WHERE design_id = ? AND layout_key IS NULL', [$designId]);
		flash('success', 'Layout saved: ' . $n . ' panels.' . ($changes ? ' Build sheet updated (' . implode(', ', $changes) . ').' : '')
			. ($typed ? ' The build sheet also has ' . $typed . ' typed ' . ($typed === 1 ? 'array' : 'arrays') . ' that count too; remove any the drawing replaces.' : ''));
		redirect(($_POST['then'] ?? '') === 'sheet' ? $base . '#sec-arrays' : $base . '/layout');
	}

	/** Google's roof faces for the building nearest a point (JSON for the layout page). */
	public function roof(int $id, int $designId): void
	{
		$this->find($id, $designId);
		session_write_close();
		$lat = (float) ($_GET['lat'] ?? 0);
		$lon = (float) ($_GET['lon'] ?? 0);
		header('Content-Type: application/json');
		if (abs($lat) > 85 || abs($lon) > 180 || ($lat == 0.0 && $lon == 0.0)) {
			echo json_encode(['ok' => false, 'error' => 'No point to look up.']);
			return;
		}
		$r = GoogleMaps::buildingInsights($lat, $lon);
		if (!$r['ok']) {
			echo json_encode(['ok' => false, 'error' => $r['error']]);
			return;
		}
		$b = $r['building'];
		echo json_encode(['ok' => true, 'roof' => [
			'quality' => $b['quality'], 'date' => $b['imagery_date'], 'lat' => $b['center_lat'], 'lon' => $b['center_lon'],
			'segments' => array_values(array_filter(array_map(static fn ($s) => $s['box'] ? ['pitch' => round($s['pitch'], 2),
				'azimuth' => round($s['azimuth'], 2), 'area_m2' => round($s['area_m2'], 1), 'box' => $s['box']] : null, $b['segments']))),
		]]);
	}

	/** One satellite tile, passed through from Google (or a plain placeholder without a key). */
	public function tile(int $z, int $x, int $y): void
	{
		Presales::requireUse();
		session_write_close();  // tiles load in parallel; don't hold the session lock
		$n = 2 ** min($z, 30);
		if ($z < 1 || $z > 22 || $x < 0 || $y < 0 || $x >= $n || $y >= $n) {
			http_response_code(404);
			return;
		}
		if (!GoogleMaps::configured()) {
			header('Content-Type: image/svg+xml');
			header('Cache-Control: private, max-age=3600');
			echo self::placeholderTile($z, $x, $y);
			return;
		}
		$r = GoogleMaps::tile($z, $x, $y);
		if (!$r['ok']) {
			http_response_code($r['status']);
			header('Content-Type: text/plain; charset=utf-8');
			echo $r['error'];
			return;
		}
		header('Content-Type: ' . $r['type']);
		header('Content-Length: ' . strlen($r['bytes']));
		header('Cache-Control: ' . (preg_match('/^[\w ,=-]+$/', $r['cache']) ? 'private, ' . preg_replace('/\b(public|private),?\s*/', '', $r['cache']) : 'private, max-age=86400'));
		echo $r['bytes'];
	}

	/** Attribution and the deepest imagery zoom for the part of the map in view (JSON). */
	public function viewport(): void
	{
		Presales::requireUse();
		session_write_close();
		header('Content-Type: application/json');
		$z = (int) ($_GET['z'] ?? 0);
		$b = array_map(static fn ($k) => (float) ($_GET[$k] ?? 0), ['n' => 'n', 's' => 's', 'e' => 'e', 'w' => 'w']);
		if (!GoogleMaps::configured()) {
			echo json_encode(['ok' => true, 'copyright' => 'Placeholder map: no Google key on this server', 'max_zoom' => 21]);
			return;
		}
		if ($z < 1 || $z > 22 || $b['n'] <= $b['s'] || abs($b['n']) > 85 || abs($b['s']) > 85) {
			echo json_encode(['ok' => false, 'error' => 'Bad area.']);
			return;
		}
		echo json_encode(GoogleMaps::tileViewport($z, $b['n'], $b['s'], $b['e'], $b['w']));
	}

	/** A gray tile with a grid every 5 m, for working on the page without a Google key. */
	private static function placeholderTile(int $z, int $x, int $y): string
	{
		$shade = ($x + $y) % 2 ? '#d9ded8' : '#d2d8d1';
		return '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256"><rect width="256" height="256" fill="' . $shade . '"/>'
			. '<path d="M0 0H256V256" fill="none" stroke="#b6beb4" stroke-width="1"/>'
			. '<text x="8" y="20" font-family="sans-serif" font-size="12" fill="#8a948a">' . $z . '/' . $x . '/' . $y . '</text></svg>';
	}

	private function find(int $oppId, int $id): array
	{
		Presales::requireUse();
		$o = Db::one('SELECT * FROM presales_opportunities WHERE id = ?', [$oppId]);
		$d = $o ? Db::one('SELECT * FROM presales_designs WHERE id = ? AND opportunity_id = ?', [$id, $oppId]) : null;
		if (!$o || !$d || !Presales::canSee($o)) {
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			exit;
		}
		return [$o, $d];
	}
}
