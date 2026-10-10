<?php
declare(strict_types=1);

namespace App\Controllers;

use App\SolarEdge;
use App\View;

/**
 * Admin test page for the inverter monitoring integration (SolarEdge V2 first).
 * Each button makes exactly one API call from the server and shows the raw response, so the
 * credit cost of each call can be read off the vendor's usage dashboard. Nothing is stored.
 */
final class MonitorController
{
	private const CALLS = [
		'sites' => 'Site list (all sites)',
		'alerts' => 'Fleet alerts (all sites)',
		'overview' => 'One site: overview for today',
		'energy' => 'One site: daily energy, last 7 days',
	];

	public function test(): void
	{
		$this->render(null, null, '');
	}

	public function run(): void
	{
		[$call, $siteId, $error] = $this->input();
		if ($error !== null) {
			$this->render(null, $error, (string) ($_POST['site_id'] ?? ''));
			return;
		}

		$tz = new \DateTimeZone(date_default_timezone_get());
		$now = new \DateTimeImmutable('now', $tz);
		$today = $now->setTime(0, 0);
		$result = match ($call) {
			'sites' => SolarEdge::get('/sites', ['page' => 1, 'sites-in-page' => 1000]),
			'alerts' => SolarEdge::get('/alerts', ['page' => 1, 'alerts-in-page' => 100]),
			'overview' => SolarEdge::get('/sites/' . $siteId . '/overview', [
				'from' => $today->format('Y-m-d\TH:i:sP'),
				'to' => $now->format('Y-m-d\TH:i:sP'),
			]),
			'energy' => SolarEdge::get('/sites/' . $siteId . '/energy', [
				'from' => $today->modify('-6 days')->format('Y-m-d\TH:i:sP'),
				'to' => $now->format('Y-m-d\TH:i:sP'),
				'resolution' => 'DAY',
			]),
		};
		$result['call'] = self::CALLS[$call];
		$result['at'] = $now->format('m/d/Y g:i:s A');
		$this->render($result, null, $siteId !== null ? (string) $siteId : '');
	}

	/** @return array{0:string,1:?int,2:?string} call, site id, error */
	private function input(): array
	{
		if (!SolarEdge::configured()) {
			return ['', null, 'No SolarEdge key is set. Add SOLAREDGE_API_KEY to the server .env file.'];
		}
		$call = (string) ($_POST['call'] ?? '');
		if (!isset(self::CALLS[$call])) {
			return ['', null, 'Unknown call.'];
		}
		$siteId = null;
		if ($call === 'overview' || $call === 'energy') {
			$raw = trim((string) ($_POST['site_id'] ?? ''));
			if (!preg_match('/^\d{1,12}$/', $raw)) {
				return ['', null, 'Enter a numeric SolarEdge site ID for the one-site calls.'];
			}
			$siteId = (int) $raw;
		}
		return [$call, $siteId, null];
	}

	private function render(?array $result, ?string $error, string $siteId): void
	{
		$sites = [];
		if ($result && ($result['status'] ?? 0) === 200 && is_array($result['json'])) {
			$list = $result['json']['sites']['site'] ?? $result['json']['sites'] ?? null;
			if (is_array($list) && array_is_list($list)) {
				$sites = $list;
			}
		}
		View::render('monitor/test', [
			'title' => 'Monitoring API test',
			'configured' => SolarEdge::configured(),
			'calls' => self::CALLS,
			'result' => $result,
			'error' => $error,
			'siteId' => $siteId,
			'sites' => $sites,
		]);
	}
}
