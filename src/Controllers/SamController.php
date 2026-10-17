<?php
declare(strict_types=1);

namespace App\Controllers;

use App\GoogleMaps;
use App\Pdf;
use App\Sam;
use App\SolarResource;
use App\UtilityRates;
use App\View;
use App\Weather;

/**
 * Lists > Proposal APIs (admin): checks that SAM (System Advisor Model) is installed and runs, fetches
 * NSRDB weather for a point and runs SAM's example system on it, and lists OpenEI utility rates at
 * a point with SAM's bill for one of them, looks up an address with Google (point, roof segments and
 * an aerial image) and makes a test PDF with WeasyPrint. A test bench for the production and savings
 * estimates the proposal system will need. Downloaded weather files are kept for reuse; nothing else
 * is saved.
 */
final class SamController
{
	public function test(): void
	{
		$this->render();
	}

	/** The page's first address. */
	public function moved(): void
	{
		redirect('/admin/proposal-apis');
	}

	public function run(): void
	{
		$result = Sam::call(['action' => 'check']);
		$result['at'] = date('m/d/Y g:i:s A');
		$this->render(['result' => $result]);
	}

	public function weather(): void
	{
		$num = static function (string $k): ?float {
			$v = trim((string) ($_POST[$k] ?? ''));
			return is_numeric($v) ? (float) $v : null;
		};
		$lat = $num('lat');
		$lon = $num('lon');
		$point = ['lat' => trim((string) ($_POST['lat'] ?? '')), 'lon' => trim((string) ($_POST['lon'] ?? ''))];
		$error = SolarResource::pointError($lat, $lon);
		if ($error !== null) {
			$this->render(['point' => $point, 'weatherError' => $error]);
			return;
		}
		$file = SolarResource::forPoint($lat, $lon);
		if (!$file['ok']) {
			$this->render(['point' => $point, 'weatherError' => $file['error']]);
			return;
		}
		$sam = Sam::call(['action' => 'weather', 'weather_file' => $file['path']]);
		$this->render(['point' => $point, 'weather' => ['file' => $file, 'sam' => $sam, 'at' => date('m/d/Y g:i:s A')]]);
	}

	/** OpenEI utility rates at a point; with a label, also SAM's bill for that rate. */
	public function rates(): void
	{
		$num = static function (string $k): ?float {
			$v = trim((string) ($_POST[$k] ?? ''));
			return is_numeric($v) ? (float) $v : null;
		};
		$lat = $num('lat');
		$lon = $num('lon');
		$sector = in_array($_POST['sector'] ?? '', UtilityRates::SECTORS, true) ? (string) $_POST['sector'] : 'Residential';
		$kwh = $num('kwh');
		$form = ['lat' => trim((string) ($_POST['lat'] ?? '')), 'lon' => trim((string) ($_POST['lon'] ?? '')), 'sector' => $sector,
			'kwh' => $kwh !== null && $kwh > 0 && $kwh <= 1000000 ? (string) $kwh : '1000'];
		$vars = ['point' => ['lat' => $form['lat'], 'lon' => $form['lon']], 'rateForm' => $form];
		$error = SolarResource::pointError($lat, $lon);
		if ($error !== null) {
			$this->render($vars + ['ratesError' => $error]);
			return;
		}
		$found = UtilityRates::search($lat, $lon, $sector);
		if (!$found['ok']) {
			$this->render($vars + ['ratesError' => $found['error']]);
			return;
		}
		$vars['rates'] = ['list' => $found['rates'], 'ms' => $found['ms'], 'at' => date('m/d/Y g:i:s A')];
		$label = (string) ($_POST['label'] ?? '');
		if ($label !== '') {
			$rate = UtilityRates::rate($label);
			$vars['bill'] = $rate['ok']
				? ['summary' => UtilityRates::summary($rate['rate']), 'sam' => Sam::call(['action' => 'bill', 'rate' => $rate['rate'], 'monthly_kwh' => (float) $form['kwh']])]
				: ['error' => $rate['error']];
		}
		$this->render($vars);
	}

	/** Town points in the service area for the rate history check (approximate centers, one per utility). */
	public const HISTORY_PLACES = [
		'40.1630,-76.3950' => 'Manheim, PA (PPL)',
		'40.2737,-76.8844' => 'Harrisburg, PA (PPL)',
		'39.9600,-75.6050' => 'West Chester, PA (PECO)',
		'40.3360,-75.9270' => 'Reading, PA (Met-Ed)',
		'39.9630,-76.7280' => 'York, PA (Met-Ed)',
		'40.7930,-77.8600' => 'State College, PA (West Penn Power)',
		'40.5190,-78.3950' => 'Altoona, PA (Penelec)',
		'39.6070,-75.8330' => 'Elkton, MD (Delmarva)',
		'39.4500,-75.7160' => 'Middletown, DE (Delmarva)',
	];

	/**
	 * How a point's rates changed over time: every version OpenEI has (retired ones too), each billed
	 * by SAM at the same flat use, and the yearly change over 3 and 5 years. Also shows whether the
	 * rates carry a sell (export) rate. Nothing is saved.
	 */
	public function rateHistory(): void
	{
		$place = (string) ($_POST['place'] ?? '');
		[$lat, $lon] = isset(self::HISTORY_PLACES[$place]) ? array_map('floatval', explode(',', $place)) : [
			is_numeric($_POST['lat'] ?? null) ? (float) $_POST['lat'] : null, is_numeric($_POST['lon'] ?? null) ? (float) $_POST['lon'] : null];
		$sector = in_array($_POST['sector'] ?? '', UtilityRates::SECTORS, true) ? (string) $_POST['sector'] : 'Residential';
		$kwh = is_numeric($_POST['kwh'] ?? null) && (float) $_POST['kwh'] > 0 ? min(1000000, (float) $_POST['kwh']) : 1000.0;
		$form = ['place' => $place, 'lat' => $lat === null ? '' : (string) $lat, 'lon' => $lon === null ? '' : (string) $lon, 'sector' => $sector, 'kwh' => (string) $kwh];
		$error = SolarResource::pointError($lat, $lon);
		if ($error !== null) {
			$this->render(['historyForm' => $form, 'historyError' => $error]);
			return;
		}
		$found = UtilityRates::history($lat, $lon, $sector);
		if (!$found['ok']) {
			$this->render(['historyForm' => $form, 'historyError' => $found['error']]);
			return;
		}
		set_time_limit(180);
		$sam = Sam::call(['action' => 'bills', 'rates' => $found['items'], 'monthly_kwh' => $kwh], 150);
		$bills = [];
		$failed = 0;
		foreach ($sam['ok'] ? $sam['bills'] : [] as $b) {
			if (isset($b['annual_bill'])) {
				$bills[(string) $b['label']] = (float) $b['annual_bill'];
			} else {
				$failed++;
			}
		}
		$groups = [];
		foreach ($found['items'] as $item) {
			$key = strtolower(trim((string) ($item['utility'] ?? '')) . '|' . trim((string) ($item['name'] ?? '')));
			$groups[$key]['utility'] = (string) ($item['utility'] ?? '');
			$groups[$key]['name'] = (string) ($item['name'] ?? '');
			$groups[$key]['versions'][] = $item;
		}
		foreach ($groups as $k => $g) {
			$groups[$k] += UtilityRates::trend($g['versions'], $bills, $kwh);
			unset($groups[$k]['versions']);
		}
		uasort($groups, static fn ($a, $b) => [$a['utility'], $a['name']] <=> [$b['utility'], $b['name']]);
		$this->render(['historyForm' => $form, 'history' => [
			'groups' => $groups, 'count' => count($found['items']), 'failed' => $failed, 'samError' => $sam['ok'] ? null : $sam['error'],
			'ms' => $found['ms'] + (int) ($sam['ms'] ?? 0), 'at' => date('m/d/Y g:i:s A'),
		]]);
	}

	/** Google: geocode an address, then Solar API Building Insights at that point; the point fills the other forms. */
	public function google(): void
	{
		$address = trim((string) ($_POST['address'] ?? ''));
		if ($address === '' || strlen($address) > 300) {
			$this->render(['address' => $address, 'googleError' => 'Type or paste an address.']);
			return;
		}
		$geo = GoogleMaps::geocode($address);
		if (!$geo['ok']) {
			$this->render(['address' => $address, 'googleError' => $geo['error']]);
			return;
		}
		$point = ['lat' => number_format($geo['lat'], 6, '.', ''), 'lon' => number_format($geo['lon'], 6, '.', '')];
		$this->render(['address' => $address, 'point' => $point, 'google' => [
			'geo' => $geo,
			'building' => GoogleMaps::buildingInsights($geo['lat'], $geo['lon']),
			'at' => date('m/d/Y g:i:s A'),
		]]);
	}

	/** The aerial image for the Google section, passed through from Maps Static (never stored). */
	public function aerial(): void
	{
		$lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
		$lon = filter_var($_GET['lon'] ?? null, FILTER_VALIDATE_FLOAT);
		$zoom = filter_var($_GET['zoom'] ?? 20, FILTER_VALIDATE_INT) ?: 20;
		if ($lat === false || $lon === false || abs($lat) > 90 || abs($lon) > 180) {
			http_response_code(400);
			return;
		}
		$img = GoogleMaps::aerial($lat, $lon, $zoom);
		if (!$img['ok']) {
			http_response_code(502);
			header('Content-Type: text/plain; charset=utf-8');
			echo $img['error'];
			return;
		}
		header('Content-Type: ' . $img['type']);
		header('Cache-Control: private, no-store');
		echo $img['bytes'];
	}

	/** A test PDF from WeasyPrint, shown in the browser; on failure, the page with the error. */
	public function pdf(): void
	{
		$r = Pdf::fromHtml(View::partial('pdf/test', ['at' => date('m/d/Y g:i A')]));
		if (!$r['ok']) {
			$this->render(['pdfError' => $r['error']]);
			return;
		}
		header('Content-Type: application/pdf');
		header('Content-Disposition: inline; filename="pdf-test.pdf"');
		header('Content-Length: ' . strlen($r['pdf']));
		header('X-Render-Ms: ' . $r['ms']);
		echo $r['pdf'];
	}

	private function render(array $vars = []): void
	{
		$office = Weather::location();
		View::render('sam/test', $vars + [
			'title' => 'Proposal APIs',
			'installed' => Sam::installed(),
			'python' => Sam::python(),
			'libFolder' => Sam::libFolder(),
			'result' => null,
			'nlrConfigured' => SolarResource::configured(),
			'dataset' => SolarResource::DATASET,
			'files' => SolarResource::files(),
			'point' => ['lat' => $office ? (string) $office['lat'] : '', 'lon' => $office ? (string) $office['lon'] : ''],
			'weather' => null,
			'weatherError' => null,
			'openeiConfigured' => UtilityRates::configured(),
			'sectors' => UtilityRates::SECTORS,
			'rateForm' => null,
			'rates' => null,
			'ratesError' => null,
			'bill' => null,
			'pdfInstalled' => Pdf::installed(),
			'pdfError' => null,
			'googleConfigured' => GoogleMaps::configured(),
			'address' => '',
			'google' => null,
			'googleError' => null,
			'historyForm' => null,
			'history' => null,
			'historyError' => null,
			'historyPlaces' => self::HISTORY_PLACES,
		]);
	}
}
