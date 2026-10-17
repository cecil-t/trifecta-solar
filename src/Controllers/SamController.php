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
		'39.7880,-75.6970' => 'Hockessin, DE (Delmarva)',
	];

	/**
	 * How rates changed over time, for any number of places and sectors in one run: every version
	 * OpenEI has (retired ones too), each billed by SAM at the same flat use, and the yearly change
	 * over 3, 5 and 10 years (UtilityRates::trend). On screen, or as a text file (format=text). Nothing is saved.
	 */
	public function rateHistory(): void
	{
		$places = array_values(array_intersect(array_keys(self::HISTORY_PLACES), array_map('strval', (array) ($_POST['places'] ?? []))));
		$sectors = array_values(array_intersect(UtilityRates::SECTORS, array_map('strval', (array) ($_POST['sectors'] ?? []))));
		$kwh = is_numeric($_POST['kwh'] ?? null) && (float) $_POST['kwh'] > 0 ? min(1000000, (float) $_POST['kwh']) : 1000.0;
		$lat = is_numeric($_POST['lat'] ?? null) ? (float) $_POST['lat'] : null;
		$lon = is_numeric($_POST['lon'] ?? null) ? (float) $_POST['lon'] : null;
		$all = !empty($_POST['unapproved']);
		$form = ['places' => $places, 'sectors' => $sectors, 'lat' => (string) ($_POST['lat'] ?? ''), 'lon' => (string) ($_POST['lon'] ?? ''), 'kwh' => (string) $kwh, 'unapproved' => $all];
		$points = [];
		foreach ($places as $k) {
			$points[] = [self::HISTORY_PLACES[$k], ...array_map('floatval', explode(',', $k))];
		}
		if ($lat !== null || $lon !== null) {
			if ($err = SolarResource::pointError($lat, $lon)) {
				$this->render(['historyForm' => $form, 'historyError' => 'Typed point: ' . $err]);
				return;
			}
			$points[] = ['Typed point ' . $lat . ', ' . $lon, $lat, $lon];
		}
		if (!$points || !$sectors) {
			$this->render(['historyForm' => $form, 'historyError' => 'Check at least one place (or type a point) and one sector.']);
			return;
		}
		set_time_limit(600);
		$start = microtime(true);
		$runs = [];
		foreach ($points as [$label, $plat, $plon]) {
			foreach ($sectors as $sector) {
				$run = ['place' => $label, 'sector' => $sector, 'groups' => [], 'count' => 0, 'failed' => 0, 'error' => null];
				$found = UtilityRates::history($plat, $plon, $sector, !$all);
				if (!$found['ok']) {
					$runs[] = ['error' => $found['error']] + $run;
					continue;
				}
				$bills = [];
				foreach (UtilityRates::billAll($found['items'], $kwh) as $rateLabel => $bill) {
					if ($bill === null) {
						$run['failed']++;
					} else {
						$bills[$rateLabel] = $bill;
					}
				}
				$sam = ['ok' => true];
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
				$runs[] = ['groups' => $groups, 'count' => count($found['items']), 'error' => $sam['ok'] ? null : 'SAM: ' . $sam['error']] + $run;
			}
		}
		$history = ['runs' => $runs, 'kwh' => $kwh, 'unapproved' => $all, 'ms' => (int) round((microtime(true) - $start) * 1000), 'at' => date('m/d/Y g:i:s A')];
		if (($_POST['format'] ?? '') === 'text') {
			header('Content-Type: text/plain; charset=utf-8');
			header('Content-Disposition: attachment; filename="rate-history-' . date('Y-m-d-His') . '.txt"');
			echo self::historyText($history);
			return;
		}
		$this->render(['historyForm' => $form, 'history' => $history]);
	}

	/** The rate history run as plain text: a summary line per rate, then every version. */
	private static function historyText(array $h): string
	{
		$pct = static fn (array $c): string => $c['pct'] === null ? 'n/a (' . $c['why'] . ')' : sprintf('%+.1f%%', $c['pct']) . sprintf(' (%.2f to %.2f cents, %d versions)', $c['from'], $c['to'], $c['versions']);
		$out = ['Rate history (OpenEI), ' . $h['at'] . ', ' . number_format($h['kwh']) . ' kWh a month' . (!empty($h['unapproved']) ? ', unapproved versions included' : ', approved versions only') . ', ' . number_format($h['ms'] / 1000, 1) . ' s', ''];
		foreach ($h['runs'] as $r) {
			$out[] = '=== ' . $r['place'] . ', ' . $r['sector'] . ': ' . ($r['error'] ?? ($r['count'] . ' versions' . ($r['failed'] ? ', ' . $r['failed'] . ' SAM could not bill' : '')));
			foreach ($r['groups'] as $g) {
				$out[] = $g['utility'] . ': ' . $g['name'];
				$out[] = '  3 years ' . $pct($g['y3']) . ' | 5 years ' . $pct($g['y5']) . ' | 10 years ' . $pct($g['y10']) . ($g['generation'] !== null ? sprintf(' | generation now %.2f cents', $g['generation']) : '');
				foreach ($g['rows'] as $row) {
					$out[] = sprintf('  %s  %s  %-14s %s%s', $row['start'] ? gmdate('m/d/Y', $row['start']) : '          ', $row['end'] ? gmdate('m/d/Y', $row['end']) : 'current   ',
						['full' => 'full service', 'delivery' => 'delivery only', 'dup' => 'same date', 'bad' => 'bad data', 'none' => 'no bill'][$row['kind']] ?? '',
						$row['cents'] !== null ? sprintf('%6.2f cents', $row['cents']) : '      n/a',
						$row['sell'] ? '  sell ' . implode('/', array_map(static fn ($v) => sprintf('%.2f', $v * 100), $row['sell'])) : '')
						. ($row['approved'] ? '' : '  UNAPPROVED');
				}
			}
			$out[] = '';
		}
		return implode("\n", $out) . "\n";
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
