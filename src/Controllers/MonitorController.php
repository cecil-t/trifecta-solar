<?php
declare(strict_types=1);

namespace App\Controllers;

use App\APsystems;
use App\Db;
use App\Enlighten;
use App\Growatt;
use App\MonitorCollector;
use App\Sma;
use App\SolarEdge;
use App\View;

/**
 * Lists > Monitor APIs (admin): the monitoring collector's connections. Per manufacturer: whether it is
 * set up, its last scheduled pull, the sign-in or session controls it needs (Growatt's captcha, forgetting
 * a saved session), and one check button that makes a single list call and shows what came back.
 * Responses are not stored.
 */
final class MonitorController
{
	/** The one check per manufacturer: [what it reads, cost note]. */
	private const CHECKS = [
		'enphase' => ['systems table', 'one request, free'],
		'apsystems' => ['system list', 'one of the 1,000 calls a month'],
		'growatt' => ['site list', 'one request, free'],
		'sma' => ['status list', 'one request, free'],
		'solaredge' => ['site list', 'one credit'],
	];

	/** .env names each manufacturer needs. */
	public const ENV = [
		'enphase' => ['ENPHASE_ENLIGHTEN_EMAIL', 'ENPHASE_ENLIGHTEN_PASSWORD'],
		'apsystems' => ['APSYSTEMS_APP_ID', 'APSYSTEMS_APP_SECRET'],
		'growatt' => ['GROWATT_USERNAME', 'GROWATT_PASSWORD'],
		'sma' => ['SMA_USERNAME', 'SMA_PASSWORD'],
		'solaredge' => ['SOLAREDGE_API_KEY'],
	];

	public function test(): void
	{
		$this->render(null, null);
	}

	public function run(): void
	{
		$vendor = (string) ($_POST['check'] ?? '');
		if (!isset(self::CHECKS[$vendor])) {
			$this->render(null, 'Unknown check.');
			return;
		}
		if (!self::configured($vendor)) {
			$this->render(null, 'Add ' . implode(' and ', self::ENV[$vendor]) . ' to the server .env file.');
			return;
		}
		if ($vendor === 'growatt' && Growatt::status() === null) {
			$this->render(null, 'Growatt is not connected yet. Click Connect Growatt first.');
			return;
		}
		$result = match ($vendor) {
			'enphase' => Enlighten::systems(),
			'apsystems' => APsystems::request('POST', '/systems', [], ['page' => 1, 'size' => 50]),
			'growatt' => Growatt::get('/web/v1/sites/1/10?name=&filter='),
			'sma' => Sma::plants(),
			'solaredge' => SolarEdge::get('/sites', ['page' => 1, 'sites-in-page' => 1000]),
		};
		$result['call'] = MonitorCollector::VENDORS[$vendor] . ': ' . self::CHECKS[$vendor][0];
		$result['at'] = date('m/d/Y g:i:s A');
		$this->render($result, null);
	}

	/** Growatt connect, step 1: fetch a captcha for a person to read. */
	public function growattCaptcha(): void
	{
		if (!self::configured('growatt')) {
			$this->render(null, 'Add GROWATT_USERNAME and GROWATT_PASSWORD to the server .env file.');
			return;
		}
		$c = Growatt::captcha();
		if ($c['error'] !== null) {
			$this->render(null, $c['error']);
			return;
		}
		$_SESSION['growatt_captcha_key'] = $c['key'];
		$this->render(null, null, $c['image']);
	}

	/** Growatt connect, step 2: sign in with the code the person typed. */
	public function growattLogin(): void
	{
		$key = (string) ($_SESSION['growatt_captcha_key'] ?? '');
		unset($_SESSION['growatt_captcha_key']);
		$code = trim((string) ($_POST['captcha'] ?? ''));
		if ($key === '') {
			$this->render(null, 'The captcha expired. Click Connect Growatt again.');
			return;
		}
		if (!preg_match('/^[A-Za-z0-9]{3,8}$/', $code)) {
			$this->render(null, 'Type the characters shown in the captcha.');
			return;
		}
		$result = Growatt::login($code, $key);
		$result['call'] = 'Growatt: connect (sign in)';
		$result['at'] = date('m/d/Y g:i:s A');
		$this->render($result, str_starts_with((string) $result['note'], 'Signed in') ? null : 'Growatt did not sign in. Click Connect Growatt for a new captcha and try again.');
	}

	public function growattForget(): void
	{
		Growatt::forget();
		flash('success', 'Growatt session removed from this server.');
		redirect('/admin/monitor-test');
	}

	public function enlightenForget(): void
	{
		Enlighten::forget();
		flash('success', 'Enlighten session removed from this server. The next pull signs in again.');
		redirect('/admin/monitor-test');
	}

	public function smaForget(): void
	{
		Sma::forget();
		flash('success', 'SMA session removed from this server. The next pull signs in again.');
		redirect('/admin/monitor-test');
	}

	private static function configured(string $vendor): bool
	{
		return match ($vendor) {
			'enphase' => Enlighten::configured() && Enlighten::available(),
			'apsystems' => APsystems::configured(),
			'growatt' => Growatt::configured() && Growatt::available(),
			'sma' => Sma::configured() && Sma::available(),
			'solaredge' => SolarEdge::configured(),
			default => false,
		};
	}

	private function render(?array $result, ?string $error, ?string $growattCaptcha = null): void
	{
		// The list a check returned, as id / name / size / status rows.
		$rows = [];
		if ($result && ($result['status'] ?? 0) === 200 && is_array($result['json'])) {
			$j = $result['json'];
			$list = $j['sites']['site'] ?? $j['data']['systems'] ?? $j['data']['list'] ?? $j['data'] ?? $j['items'] ?? null;
			if (is_array($list) && array_is_list($list)) {
				// Enlighten Manager cells are either plain values or {text, url}
				$cell = static fn (mixed $v): string => is_array($v) ? (string) ($v['text'] ?? '') : (string) ($v ?? '');
				foreach ($list as $s) {
					if (!is_array($s)) {
						continue;
					}
					$rows[] = [
						'id' => $cell($s['siteId'] ?? $s['plantId'] ?? $s['sid'] ?? $s['id'] ?? ''),
						'name' => $cell($s['alias'] ?? null ?: ($s['site']['alias'] ?? $s['name'] ?? '')),
						'size' => $cell($s['peakPower'] ?? $s['capacity'] ?? $s['dcPowerInputMax'] ?? ''),
						'status' => $cell($s['activationStatus'] ?? $s['status'] ?? (isset($s['light']) ? ['', 'Green', 'Yellow', 'Red', 'Grey'][(int) $s['light']] ?? $s['light'] : ($s['state'] ?? ''))),
					];
				}
			}
		}

		$vendors = [];
		$scheduled = "vendor = ? AND finished_at IS NOT NULL AND COALESCE(message, '') NOT LIKE 'Backfill%'";
		foreach (MonitorCollector::VENDORS as $key => $label) {
			$vendors[$key] = [
				'label' => $label,
				'source' => MonitorCollector::SOURCES[$key][2] ?? '',
				'configured' => self::configured($key),
				'env' => self::ENV[$key],
				'check' => self::CHECKS[$key],
				'last' => Db::one("SELECT finished_at, ok, calls, message FROM monitor_runs WHERE $scheduled ORDER BY id DESC LIMIT 1", [$key]),
				'last_ok' => Db::value("SELECT MAX(finished_at) FROM monitor_runs WHERE $scheduled AND ok = 1", [$key]),
			];
		}
		[$seLeft] = MonitorCollector::seBudget();

		View::render('monitor/test', [
			'title' => 'Monitor APIs',
			'vendors' => $vendors,
			'enlightenSession' => Enlighten::hasSession(),
			'growattStatus' => Growatt::status(),
			'growattCaptcha' => $growattCaptcha,
			'smaStatus' => Sma::status(),
			'seCreditsLeft' => $seLeft,
			'result' => $result,
			'error' => $error,
			'rows' => $rows,
		]);
	}
}
