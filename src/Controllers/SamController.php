<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Sam;
use App\SolarResource;
use App\UtilityRates;
use App\View;
use App\Weather;

/**
 * Lists > SAM (admin): checks that SAM (System Advisor Model) is installed and runs, fetches
 * NSRDB weather for a point and runs SAM's example system on it, and lists OpenEI utility rates at
 * a point with SAM's bill for one of them. A test bench for the production and savings estimates
 * the proposal system will need. Downloaded weather files are kept for reuse; nothing else is saved.
 */
final class SamController
{
	public function test(): void
	{
		$this->render();
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

	private function render(array $vars = []): void
	{
		$office = Weather::location();
		View::render('sam/test', $vars + [
			'title' => 'SAM',
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
		]);
	}
}
