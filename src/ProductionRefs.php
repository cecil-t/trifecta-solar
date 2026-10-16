<?php
declare(strict_types=1);

namespace App;

/**
 * Reference jobs (table production_refs): built, monitored systems with their signed proposal's
 * estimate. A run downloads (or reuses) the site's NSRDB weather, runs SAM's detailed model and
 * PVWatts v8 on the job's equipment and arrays with the proposal's stated losses (bin/sam.py,
 * action "system"), and keeps the reply. The page compares proposal, both models and monitored
 * production by calendar month.
 */
final class ProductionRefs
{
	public const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

	public static function all(): array
	{
		return array_map([self::class, 'decode'], Db::all('SELECT r.*, p.project_number, p.name AS project_name, s.name AS site_name, s.vendor
			FROM production_refs r LEFT JOIN projects p ON p.id = r.project_id LEFT JOIN monitor_sites s ON s.id = r.monitor_site_id
			ORDER BY r.name'));
	}

	public static function find(int $id): ?array
	{
		$r = Db::one('SELECT * FROM production_refs WHERE id = ?', [$id]);
		return $r ? self::decode($r) : null;
	}

	private static function decode(array $r): array
	{
		$r['arrays'] = json_decode((string) $r['arrays'], true) ?: [];
		$r['proposal_monthly'] = json_decode((string) ($r['proposal_monthly'] ?? ''), true) ?: null;
		$r['last_result'] = json_decode((string) ($r['last_result'] ?? ''), true) ?: null;
		return $r;
	}

	/** Run both models for one job and keep the reply. Returns the reply (ok false with error on failure). */
	public static function run(array $r): array
	{
		$file = SolarResource::forPoint((float) $r['lat'], (float) $r['lon']);
		if (!$file['ok']) {
			$reply = ['ok' => false, 'error' => 'Weather: ' . $file['error']];
		} else {
			$reply = Sam::call([
				'action' => 'system',
				'weather_file' => $file['path'],
				'module' => $r['module'],
				'inverter' => $r['inverter'],
				'inverter_count' => (int) $r['inverter_count'],
				'optimizers' => (bool) $r['optimizers'],
				'arrays' => $r['arrays'],
				'losses' => ['total' => (float) $r['loss_total'], 'inverter' => (float) $r['loss_inverter'],
					'optimizer' => (float) $r['loss_optimizer'], 'shading' => (float) $r['loss_shading']],
			], 120);
			$reply['weather'] = ['dataset' => SolarResource::DATASET, 'location_id' => $file['row']['location_id'] ?? null,
				'lat' => $file['row']['lat'] ?? null, 'lon' => $file['row']['lon'] ?? null];
		}
		unset($reply['weather_columns'], $reply['trace']);
		$reply['at'] = now_utc();
		Db::update('production_refs', (int) $r['id'], ['last_result' => json_encode($reply), 'last_run_at' => now_utc(), 'updated_at' => now_utc()]);
		Activity::event('production_ref', (int) $r['id'], $reply['ok']
			? 'Ran: SAM detailed ' . number_format((float) $reply['detailed']['annual_kwh']) . ' kWh, PVWatts v8 ' . number_format((float) $reply['pvwatts']['annual_kwh']) . ' kWh'
			: 'Run failed: ' . $reply['error']);
		return $reply;
	}

	/**
	 * Monitored production for the last 12 complete calendar months: [YYYY-MM => kWh] for months
	 * that are whole (a month total, or a stored day for every day) and after the system was in
	 * service. Months that do not qualify are left out.
	 */
	public static function actual(array $r, ?string $today = null): array
	{
		if (!$r['monitor_site_id']) {
			return [];
		}
		$first = new \DateTimeImmutable(($today ?? date('Y-m-d')) . ' 00:00');
		$first = $first->modify('first day of this month');
		$out = [];
		for ($i = 12; $i >= 1; $i--) {
			$m = $first->modify("-$i month");
			$key = $m->format('Y-m');
			$start = (string) $r['in_service'];
			if ($start !== '' && ($key < substr($start, 0, 7) || ($key === substr($start, 0, 7) && substr($start, 8, 2) !== '01'))) {
				continue;   // before the system ran, or its partial first month
			}
			$days = (int) $m->format('t');
			$month = Db::value('SELECT wh FROM monitor_monthly WHERE site_id = ? AND month = ?', [(int) $r['monitor_site_id'], $key]);
			$daily = Db::one("SELECT COUNT(*) AS n, SUM(wh) AS wh FROM monitor_daily WHERE site_id = ? AND date LIKE ?", [(int) $r['monitor_site_id'], $key . '-%']);
			if ($month !== null && $month !== false) {
				$out[$key] = (float) $month / 1000;
			} elseif ((int) $daily['n'] >= $days) {
				$out[$key] = (float) $daily['wh'] / 1000;
			}
		}
		return $out;
	}

	/**
	 * Comparison rows for the actual months: proposal, SAM detailed, PVWatts and actual kWh, plus
	 * totals over all compared months and over the same months without December to February.
	 */
	public static function compare(array $r, array $actual): array
	{
		$res = $r['last_result'] && !empty($r['last_result']['ok']) ? $r['last_result'] : null;
		$rows = [];
		$tot = ['all' => [0.0, 0.0, 0.0, 0.0, 0], 'nowinter' => [0.0, 0.0, 0.0, 0.0, 0]];
		foreach ($actual as $ym => $kwh) {
			$i = (int) substr($ym, 5, 2) - 1;
			$row = [
				'month' => $ym,
				'label' => self::MONTHS[$i] . ' ' . substr($ym, 0, 4),
				'proposal' => $r['proposal_monthly'][$i] ?? null,
				'detailed' => $res ? (float) $res['detailed']['monthly_kwh'][$i] : null,
				'pvwatts' => $res ? (float) $res['pvwatts']['monthly_kwh'][$i] : null,
				'actual' => $kwh,
			];
			$rows[] = $row;
			$winter = in_array($i, [11, 0, 1], true);
			foreach (['all', 'nowinter'] as $k) {
				if ($k === 'nowinter' && $winter) {
					continue;
				}
				$tot[$k][0] += (float) $row['proposal'];
				$tot[$k][1] += (float) $row['detailed'];
				$tot[$k][2] += (float) $row['pvwatts'];
				$tot[$k][3] += $kwh;
				$tot[$k][4]++;
			}
		}
		return ['rows' => $rows, 'totals' => $tot];
	}

	/** Actual as a percent of an estimate, or null. */
	public static function pct(?float $actual, ?float $estimate): ?float
	{
		return $actual !== null && $estimate ? $actual / $estimate * 100 : null;
	}
}
