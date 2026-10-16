<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Sam;
use App\SolarResource;
use App\View;
use App\Weather;

/**
 * Lists > SAM (admin): checks that SAM (System Advisor Model) is installed and runs, and fetches
 * NSRDB weather for a point and runs SAM's example system on it. A test bench for the production
 * estimates the proposal system will need. Downloaded weather files are kept for reuse; nothing
 * else is saved.
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
		]);
	}
}
