<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Enphase;
use App\SolarEdge;
use App\View;

/**
 * Admin test page for the inverter monitoring integrations (SolarEdge V2 and Enphase v4).
 * Each call button makes exactly one API call from the server and shows the raw response, so the
 * cost of each call can be read off the vendor's usage page. Only the Enphase OAuth tokens are kept
 * (a private file next to the database); API responses are not stored.
 */
final class MonitorController
{
	private const CALLS = [
		'sites' => 'Site list (all sites)',
		'alerts' => 'Fleet alerts (all sites)',
		'overview' => 'One site: overview for today',
		'energy' => 'One site: daily energy, last 7 days',
		'enphase_systems' => 'System list (all systems)',
		'enphase_summary' => 'One system: summary',
		'enphase_energy' => 'One system: daily energy, last 7 days',
		'enphase_refresh' => 'Refresh the access token',
	];

	public function test(): void
	{
		$this->render(null, null);
	}

	public function run(): void
	{
		[$call, $id, $error] = $this->input();
		if ($error !== null) {
			$this->render(null, $error);
			return;
		}

		$tz = new \DateTimeZone(date_default_timezone_get());
		$now = new \DateTimeImmutable('now', $tz);
		$today = $now->setTime(0, 0);
		$weekAgo = $today->modify('-6 days');
		$result = match ($call) {
			'sites' => SolarEdge::get('/sites', ['page' => 1, 'sites-in-page' => 1000]),
			'alerts' => SolarEdge::get('/alerts', ['page' => 1, 'alerts-in-page' => 100]),
			'overview' => SolarEdge::get('/sites/' . $id . '/overview', [
				'from' => $today->format('Y-m-d\TH:i:sP'),
				'to' => $now->format('Y-m-d\TH:i:sP'),
			]),
			'energy' => SolarEdge::get('/sites/' . $id . '/energy', [
				'from' => $weekAgo->format('Y-m-d\TH:i:sP'),
				'to' => $now->format('Y-m-d\TH:i:sP'),
				'resolution' => 'DAY',
			]),
			'enphase_systems' => Enphase::get('/systems', ['size' => 100]),
			'enphase_summary' => Enphase::get('/systems/' . $id . '/summary'),
			'enphase_energy' => Enphase::get('/systems/' . $id . '/energy_lifetime', [
				'start_date' => $weekAgo->format('Y-m-d'),
				'end_date' => $today->format('Y-m-d'),
			]),
			'enphase_refresh' => Enphase::refresh(),
		};
		$result['call'] = (str_starts_with($call, 'enphase') ? 'Enphase: ' : 'SolarEdge: ') . self::CALLS[$call];
		$result['at'] = $now->format('m/d/Y g:i:s A');
		$this->render($result, null);
	}

	/**
	 * Start the Enphase OAuth flow: approve on Enphase's site, which returns to the callback below.
	 * A plain link (GET), because the CSP's form-action 'self' blocks a form post that redirects off site.
	 */
	public function enphaseConnect(): void
	{
		if (!Enphase::configured()) {
			$this->render(null, 'Enphase is not set up. Add ENPHASE_API_KEY, ENPHASE_CLIENT_ID and ENPHASE_CLIENT_SECRET to the server .env file.');
			return;
		}
		$state = bin2hex(random_bytes(16));
		$_SESSION['enphase_state'] = $state;
		header('Location: ' . Enphase::authorizeUrl(self::callbackUrl(), $state), true, 303);
		exit;
	}

	/** Enphase sends the browser back here with ?code= (or ?error=) after the approval screen. */
	public function enphaseCallback(): void
	{
		$expected = (string) ($_SESSION['enphase_state'] ?? '');
		unset($_SESSION['enphase_state']);
		$state = (string) ($_GET['state'] ?? '');
		if (isset($_GET['error'])) {
			flash('error', 'Enphase did not approve access: ' . substr((string) $_GET['error'], 0, 200));
			redirect('/admin/monitor-test');
		}
		if ($expected === '' || !hash_equals($expected, $state)) {
			flash('error', 'The Enphase approval did not match this session. Click Connect again.');
			redirect('/admin/monitor-test');
		}
		$code = (string) ($_GET['code'] ?? '');
		if (!preg_match('/^[A-Za-z0-9._~-]{1,512}$/', $code)) {
			flash('error', 'Enphase did not send a usable authorization code.');
			redirect('/admin/monitor-test');
		}
		$this->finishExchange(Enphase::exchange($code, self::callbackUrl()));
	}

	/** Fallback: a code copied from Enphase's own landing page (their default redirect URI). */
	public function enphaseCode(): void
	{
		$code = trim((string) ($_POST['code'] ?? ''));
		if (!Enphase::configured()) {
			$this->render(null, 'Enphase is not set up. Add ENPHASE_API_KEY, ENPHASE_CLIENT_ID and ENPHASE_CLIENT_SECRET to the server .env file.');
			return;
		}
		if (!preg_match('/^[A-Za-z0-9._~-]{1,512}$/', $code)) {
			$this->render(null, 'Paste the code exactly as Enphase showed it.');
			return;
		}
		$this->finishExchange(Enphase::exchange($code, Enphase::DEFAULT_REDIRECT));
	}

	public function enphaseDisconnect(): void
	{
		Enphase::disconnect();
		flash('success', 'Enphase tokens removed from this server.');
		redirect('/admin/monitor-test');
	}

	private function finishExchange(array $result): void
	{
		$result['call'] = 'Enphase: connect (trade the code for tokens)';
		$result['at'] = date('m/d/Y g:i:s A');
		$this->render($result, $result['status'] === 200 ? null : 'Enphase did not issue tokens. The reply is below.');
	}

	/** @return array{0:string,1:?int,2:?string} call, site or system id, error */
	private function input(): array
	{
		$call = (string) ($_POST['call'] ?? '');
		if (!isset(self::CALLS[$call])) {
			return ['', null, 'Unknown call.'];
		}
		$enphase = str_starts_with($call, 'enphase');
		if ($enphase && !Enphase::configured()) {
			return ['', null, 'Enphase is not set up. Add ENPHASE_API_KEY, ENPHASE_CLIENT_ID and ENPHASE_CLIENT_SECRET to the server .env file.'];
		}
		if (!$enphase && !SolarEdge::configured()) {
			return ['', null, 'No SolarEdge key is set. Add SOLAREDGE_API_KEY to the server .env file.'];
		}
		$id = null;
		if (in_array($call, ['overview', 'energy', 'enphase_summary', 'enphase_energy'], true)) {
			$field = $enphase ? 'system_id' : 'site_id';
			$raw = trim((string) ($_POST[$field] ?? ''));
			if (!preg_match('/^\d{1,12}$/', $raw)) {
				return ['', null, $enphase ? 'Enter a numeric Enphase system ID for the one-system calls.' : 'Enter a numeric SolarEdge site ID for the one-site calls.'];
			}
			$id = (int) $raw;
		}
		return [$call, $id, null];
	}

	/** Where Enphase returns after approval: this server's own callback route. */
	private static function callbackUrl(): string
	{
		$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
		if (!preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host)) {
			$host = 'localhost';
		}
		return (is_https() ? 'https://' : 'http://') . $host . '/admin/monitor-test/enphase-callback';
	}

	private function render(?array $result, ?string $error): void
	{
		$rows = [];
		if ($result && ($result['status'] ?? 0) === 200 && is_array($result['json'])) {
			$j = $result['json'];
			$list = $j['sites']['site'] ?? $j['systems'] ?? null;
			if (is_array($list) && array_is_list($list)) {
				foreach ($list as $s) {
					$rows[] = [
						'id' => (string) ($s['siteId'] ?? $s['system_id'] ?? ''),
						'name' => (string) ($s['name'] ?? ''),
						'size' => (string) ($s['peakPower'] ?? $s['system_size'] ?? ''),
						'status' => (string) ($s['activationStatus'] ?? $s['status'] ?? ''),
					];
				}
			}
		}
		View::render('monitor/test', [
			'title' => 'Monitoring API test',
			'calls' => self::CALLS,
			'solaredge' => SolarEdge::configured(),
			'enphase' => Enphase::configured(),
			'enphaseStatus' => Enphase::status(),
			'callbackUrl' => self::callbackUrl(),
			'fallbackUrl' => Enphase::configured() ? Enphase::authorizeUrl(Enphase::DEFAULT_REDIRECT, 'manual') : '',
			'result' => $result,
			'error' => $error,
			'siteId' => (string) ($_POST['site_id'] ?? ''),
			'systemId' => (string) ($_POST['system_id'] ?? ''),
			'rows' => $rows,
		]);
	}
}
