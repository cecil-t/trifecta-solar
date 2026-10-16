<?php
declare(strict_types=1);

namespace App;

use App\Controllers\MonitorController;

/**
 * Every outside service and outside software the app uses, for the About page: what it is for, how
 * it is reached and which version, whether its key or login is set, when it was last used (where the
 * app keeps a record) and what it stores on this server. Keys and logins are only reported as set or
 * not set. Add a service here when the app starts using one.
 */
final class ThirdParty
{
	/** @return list<array{group:string, services:list<array>}> */
	public static function all(): array
	{
		return [
			['group' => 'Inverter monitoring (Monitor page, collected every 15 minutes)', 'services' => self::monitoring()],
			['group' => 'Addresses, maps and roofs', 'services' => [
				[
					'name' => 'US Census Geocoder', 'url' => 'https://geocoding.geo.census.gov/geocoder/',
					'for' => 'Township or borough and county for project addresses',
					'interface' => 'Geocoder REST API: onelineaddress and coordinates, benchmark Public_AR_Current, vintage Current_Current',
					'access' => 'Free, no key', 'configured' => null,
					'last' => self::value('SELECT MAX(fetched_at) FROM geo_cache'),
					'storage' => [
						['Lookup cache', self::n(self::rows('geo_cache'), 'address', 'addresses') . ' (table geo_cache; a match is kept a year, no match 30 days)'],
						['On projects', self::n(self::rows('projects', 'geo_source IS NOT NULL'), 'project', 'projects') . ' with a point, township and county saved'],
					],
				],
				[
					'name' => 'OpenStreetMap Nominatim', 'url' => 'https://nominatim.org/',
					'for' => 'Finds the point when the Census cannot match an address (common on rural roads)',
					'interface' => 'Search API (jsonv2), one request a second at most',
					'access' => 'Free, no key (OpenStreetMap usage policy; data under ODbL)', 'configured' => null,
					'last' => null,
					'storage' => [['Lookup cache', 'Shares the Census cache above']],
				],
				[
					'name' => 'Google Maps Platform', 'url' => 'https://developers.google.com/maps',
					'for' => 'Address to point, roof faces and aerial images (Proposal APIs); address links open Google Maps',
					'interface' => 'Geocoding API, Solar API v1 (buildingInsights:findClosest), Maps Static API; Map Tiles API enabled for later',
					'access' => 'API key, called from the server only', 'configured' => GoogleMaps::configured(),
					'last' => null,
					'storage' => [['Stored here', 'Nothing; aerial images are passed through to the browser, never stored']],
				],
			]],
			['group' => 'Weather, solar resource and utility rates', 'services' => [
				[
					'name' => 'Open-Meteo', 'url' => 'https://open-meteo.com/',
					'for' => 'Five-day forecast on the dashboard',
					'interface' => 'Forecast API v1',
					'access' => 'Free, no key (data CC BY 4.0)', 'configured' => null,
					'last' => self::fileTime('weather.json'),
					'storage' => [['Forecast cache', self::fileText('weather.json', 'refreshed every 30 minutes')]],
				],
				[
					'name' => 'NSRDB (National Laboratory of the Rockies)', 'url' => 'https://developer.nlr.gov/docs/solar/nsrdb/',
					'for' => 'Typical-year hourly weather for production estimates (Proposal APIs)',
					'interface' => 'GOES TMY PSM v4 download API (CSV), dataset ' . SolarResource::DATASET,
					'access' => 'API key and email', 'configured' => SolarResource::configured(),
					'last' => self::value('SELECT MAX(fetched_at) FROM solar_resource_files'),
					'storage' => [
						['Weather files', self::n(self::rows('solar_resource_files'), 'file', 'files') . ', ' . self::size((float) self::value('SELECT COALESCE(SUM(bytes), 0) FROM solar_resource_files')) . ' in data/solar/ (one per NSRDB grid cell, about 2 km; not backed up, downloaded again if lost)'],
						['Lookups', self::n(self::rows('solar_resource_lookups'), 'point', 'points') . ' remembered (table solar_resource_lookups)'],
					],
				],
				[
					'name' => 'OpenEI Utility Rate Database', 'url' => 'https://openei.org/wiki/Utility_Rate_Database',
					'for' => 'Utility rates at a point, billed through SAM (Proposal APIs)',
					'interface' => 'utility_rates API, version latest',
					'access' => 'API key from the same developer system as NSRDB (NLR_API_KEY, or its own OPENEI_API_KEY)', 'configured' => UtilityRates::configured(),
					'last' => null,
					'storage' => [['Stored here', 'Nothing; rates are looked up when needed']],
				],
			]],
			['group' => 'Software built into the server image', 'services' => self::software()],
			['group' => 'Code', 'services' => [
				[
					'name' => 'GitHub', 'url' => Version::REPO_URL,
					'for' => 'The code; the server pulls updates from it',
					'interface' => 'git over HTTPS (public repository)',
					'access' => 'Public, no key to read', 'configured' => null,
					'last' => null,
					'storage' => [['Code', 'The app folder is a git clone']],
				],
			]],
		];
	}

	/** The five monitoring connections, from the collector's own descriptions and run log. */
	private static function monitoring(): array
	{
		$files = [
			'enphase' => [['enlighten-session.txt', 'Saved sign-in (cookies)']],
			'growatt' => [['growatt-session.txt', 'Saved sign-in (cookies)'], ['growatt-state.json', 'Session times']],
			'sma' => [['sma-session.json', 'Saved sign-in (tokens)']],
		];
		$out = [];
		foreach (MonitorCollector::VENDORS as $key => $label) {
			[$kind, , $detail] = MonitorCollector::SOURCES[$key] + [null, null, ''];
			$env = MonitorController::ENV[$key] ?? [];
			$set = $env !== [] && count(array_filter($env, static fn ($n) => (string) Config::get($n, '') !== '')) === count($env);
			$sites = self::rows('monitor_sites', 'vendor = ?', [$key]);
			$storage = [
				['Systems', self::n($sites, 'system', 'systems') . ' (table monitor_sites)'],
				['Production', self::count('SELECT COUNT(*) FROM monitor_daily d JOIN monitor_sites s ON s.id = d.site_id WHERE s.vendor = ?', [$key]) . ' daily and '
					. self::count('SELECT COUNT(*) FROM monitor_monthly m JOIN monitor_sites s ON s.id = m.site_id WHERE s.vendor = ?', [$key]) . ' monthly values'],
				['Run log', self::n(self::rows('monitor_runs', 'vendor = ?', [$key]), 'collector run', 'collector runs')],
			];
			foreach ($files[$key] ?? [] as [$file, $what]) {
				$storage[] = [$what, self::fileText($file)];
			}
			$out[] = [
				'name' => $label, 'url' => null,
				'for' => 'System list, status and production',
				'interface' => $detail,
				'access' => ($kind === 'API' ? 'API credentials' : 'Installer login') . ', in the server .env file',
				'configured' => $set,
				'last' => self::value('SELECT MAX(finished_at) FROM monitor_runs WHERE vendor = ? AND ok = 1', [$key]),
				'storage' => $storage,
			];
		}
		return $out;
	}

	/** SAM and WeasyPrint: versions read from what is installed (no program is started). */
	private static function software(): array
	{
		$samRoot = dirname(Sam::python(), 2);
		$pdfRoot = dirname(Pdf::python(), 2);
		$libRelease = is_file(Sam::libFolder() . '/VERSION') ? trim((string) file_get_contents(Sam::libFolder() . '/VERSION')) : null;
		$pysam = self::package($samRoot, 'nlr_pysam');
		$weasy = self::package($pdfRoot, 'weasyprint');
		$py = self::pythonVersion($samRoot);
		return [
			[
				'name' => 'SAM, System Advisor Model (National Laboratory of the Rockies)', 'url' => 'https://github.com/NatLabRockies/SAM',
				'for' => 'Production and utility bill models for proposals (Proposal APIs)',
				'interface' => 'PySAM ' . ($pysam ?? 'not installed') . ($py ? ' on Python ' . $py : '') . ($libRelease ? ', SAM release ' . $libRelease : '') . '; detailed PV model (Pvsamv1), Utilityrate5',
				'access' => 'Open source (BSD 3-Clause), runs on this server', 'configured' => null,
				'last' => null,
				'storage' => [['Program', 'In the Docker image (' . $samRoot . '), with SAM\'s CEC module and inverter libraries']],
			],
			[
				'name' => 'WeasyPrint', 'url' => 'https://weasyprint.org/',
				'for' => 'PDFs from the app\'s pages (proposals later)',
				'interface' => 'WeasyPrint ' . ($weasy ?? 'not installed') . ', HTML and CSS to PDF',
				'access' => 'Open source (BSD 3-Clause), runs on this server', 'configured' => null,
				'last' => null,
				'storage' => [['Program', 'In the Docker image (' . $pdfRoot . '); PDFs are made when asked for and not kept']],
			],
		];
	}

	/** Version of a package in a venv, from its dist-info folder name, or null. */
	private static function package(string $venv, string $name): ?string
	{
		$hits = glob($venv . '/lib/python3*/site-packages/' . $name . '-*.dist-info') ?: [];
		return $hits && preg_match('/-([^-]+)\.dist-info$/', $hits[0], $m) ? $m[1] : null;
	}

	private static function pythonVersion(string $venv): ?string
	{
		$cfg = @file_get_contents($venv . '/pyvenv.cfg');
		return $cfg && preg_match('/^version(?:_info)?\s*=\s*([\d.]+)/m', $cfg, $m) ? $m[1] : null;
	}

	/** "1 file", "3 files" from a formatted count. */
	private static function n(string $count, string $one, string $many): string
	{
		return $count . ' ' . ($count === '1' ? $one : $many);
	}

	private static function rows(string $table, string $where = '1 = 1', array $params = []): string
	{
		return self::count('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where, $params);
	}

	private static function count(string $sql, array $params = []): string
	{
		try {
			return number_format((int) Db::value($sql, $params));
		} catch (\Throwable) {
			return '0';
		}
	}

	private static function value(string $sql, array $params = []): ?string
	{
		try {
			$v = Db::value($sql, $params);
			return $v === null || $v === false || $v === '' ? null : (string) $v;
		} catch (\Throwable) {
			return null;
		}
	}

	/** A file next to the database, as "4.1 KB, updated 10/16/2026 1:40 AM", or "None". */
	private static function fileText(string $file, string $note = ''): string
	{
		$path = dirname(Db::path()) . '/' . $file;
		if (!is_file($path)) {
			return 'None (' . $file . ')';
		}
		return self::size((float) filesize($path)) . ', updated ' . date('m/d/Y g:i A', (int) filemtime($path)) . ' (' . $file . ($note !== '' ? '; ' . $note : '') . ')';
	}

	/** When a file next to the database last changed, as UTC text like the tables keep. */
	private static function fileTime(string $file): ?string
	{
		$path = dirname(Db::path()) . '/' . $file;
		return is_file($path) ? gmdate('Y-m-d\\TH:i:s\\Z', (int) filemtime($path)) : null;
	}

	private static function size(float $n): string
	{
		foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
			if ($n < 1024 || $unit === 'GB') {
				return ($i ? number_format($n, 1) : (string) (int) $n) . ' ' . $unit;
			}
			$n /= 1024;
		}
		return '';
	}
}
