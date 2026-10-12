<?php
declare(strict_types=1);

namespace App;

/**
 * The monitor:poll collector. Root's crontab on the server runs it every 15 minutes; each run
 * decides per vendor whether a pull is due, so every vendor stays inside its limits:
 *
 *  - Enphase (Enlighten Manager sign-in, App\Enlighten): one request returns every system with today,
 *    7-day, month, year and lifetime production. Every 30 minutes, 6 AM to 9 PM, every day: it costs
 *    nothing, and the day's last pull is what becomes that day's stored total (the table only has a
 *    running "today"), so it runs through the evening.
 *  - APsystems (OpenAPI Lv0, 1,000 calls a month): weekdays at 8 AM, 11 AM, 2 PM and 5 PM, daily
 *    energy for the month per system (each pull rewrites every day of the month, so Monday's first pull
 *    completes Friday evening and the weekend), plus the system list and a summary (year, lifetime)
 *    once a day. About 460 calls a month for 4 systems; stops at 950 in a calendar month.
 *  - Growatt (Shiner portal sign-in, App\Growatt): the site list (one call: today's kWh, alert count,
 *    inverters online of total, last update), every 30 minutes 6 AM to 9 PM, every day: free, it keeps the 30-day session
 *    sliding, and the day's last pull becomes that day's stored total. Needs a person to connect it
 *    (captcha) on the test page about once a month; until then it is skipped.
 *  - SolarEdge (V2 Free tier, 2,000 credits a cycle, 1 credit a call, 10 calls a minute): once each
 *    weekday from 8 AM: site list, fleet alerts, then daily energy for each active site since its last
 *    pull. Skips pending sites and sites with a site communication fault (nothing new to fetch; the
 *    next pull after the fault clears catches up). Skips when the cycle's remaining credits will not
 *    cover a full pull, and skips Fridays when they will not cover every weekday left (Monday's pull
 *    fills in Friday).
 *
 * Every vendor call is counted in monitor_runs. The page reads only the database.
 */
final class MonitorCollector
{
	public const VENDORS = ['enphase' => 'Enphase', 'apsystems' => 'APsystems', 'growatt' => 'Growatt', 'solaredge' => 'SolarEdge'];

	/** How each vendor's data is read: [short label, kind, detail]. kind 'api' = published API, 'signin' = portal sign-in. */
	public const SOURCES = [
		'enphase' => ['Sign-in', 'signin', 'Enlighten Manager, read as the installer user (not a published API)'],
		'apsystems' => ['API', 'api', 'APsystems OpenAPI, free Lv0 plan (1,000 calls a month)'],
		'solaredge' => ['API', 'api', 'SolarEdge Monitoring API V2, Free tier (2,000 credits a cycle)'],
		'growatt' => ['Sign-in', 'signin', 'Growatt Shiner portal, read as the installer account (captcha typed about once a month)'],
	];

	private const AP_HOURS = [8, 11, 14, 17];
	private const AP_MONTHLY_CAP = 950;
	private const SE_SAFETY = 20;

	private int $calls = 0;
	private ?int $runId = null;
	/** @var array<int, string> */
	private array $lines = [];

	/** @return array<int, string> log lines */
	public static function run(bool $force = false, ?string $only = null, bool $dryRun = false): array
	{
		$lock = fopen(dirname(Db::path()) . '/.monitor.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
			return [self::stamp() . ' Another monitor:poll is still running; skipped.'];
		}
		$lines = [];
		foreach (array_keys(self::VENDORS) as $vendor) {
			if ($only !== null && $only !== $vendor) {
				continue;
			}
			$c = new self();
			$lines = array_merge($lines, $c->vendor($vendor, $force, $dryRun));
		}
		flock($lock, LOCK_UN);
		return $lines;
	}

	/** @return array<int, string> */
	private function vendor(string $vendor, bool $force, bool $dryRun): array
	{
		$label = self::VENDORS[$vendor];
		if ($vendor === 'growatt' && Growatt::configured() && Growatt::available() && Growatt::status() === null) {
			return [self::stamp() . " $label: not connected; use Connect Growatt on Lists > Monitor APIs."];
		}
		$configured = match ($vendor) {
			'growatt' => Growatt::configured() && Growatt::available(),
			'enphase' => Enlighten::configured() && Enlighten::available(),
			'apsystems' => APsystems::configured(),
			'solaredge' => SolarEdge::configured(),
		};
		if (!$configured) {
			return [self::stamp() . " $label: not set up in .env; skipped."];
		}
		[$due, $why] = match ($vendor) {
			'enphase', 'growatt' => $this->daytimeEvery30($vendor),
			'apsystems' => $this->apDue(),
			'solaredge' => $this->seDue(),
		};
		if (!$due && !$force) {
			return [self::stamp() . " $label: not due ($why)."];
		}
		if ($dryRun) {
			return [self::stamp() . " $label: would pull now" . ($due ? '' : ' (forced; ' . $why . ')') . '. Dry run: no calls made.'];
		}

		$runId = Db::insert('monitor_runs', ['vendor' => $vendor, 'started_at' => now_utc()]);
		$this->runId = $runId;
		$ok = false;
		$message = '';
		try {
			$message = match ($vendor) {
				'growatt' => $this->growatt(),
				'enphase' => $this->enphase(),
				'apsystems' => $this->apsystems(),
				'solaredge' => $this->solaredge(),
			};
			$ok = true;
		} catch (\Throwable $e) {
			$message = 'Failed: ' . $e->getMessage();
		}
		Db::update('monitor_runs', $runId, ['finished_at' => now_utc(), 'calls' => $this->calls, 'ok' => $ok ? 1 : 0, 'message' => substr($message, 0, 500)]);
		$this->lines[] = self::stamp() . " $label: $message ({$this->calls} calls)";
		return $this->lines;
	}

	// ---- one-time history backfill ------------------------------------------------------------

	/**
	 * Fill this year's history for months before daily tracking began: monthly totals always (SolarEdge
	 * 1 credit per site, APsystems 1 call per system), and with $daily the day-by-day values too
	 * (1 call per site per month). Refuses when the estimate exceeds what is left of the vendor's
	 * allowance, unless forced. Months that already have stored days are skipped.
	 *
	 * @return array<int, string>
	 */
	public static function backfill(?string $only, bool $daily, bool $dryRun, bool $force): array
	{
		$lock = fopen(dirname(Db::path()) . '/.monitor.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
			return [self::stamp() . ' monitor:poll is running; try again in a few minutes.'];
		}
		$lines = [];
		foreach (array_keys(self::VENDORS) as $vendor) {
			if ($only !== null && $only !== $vendor) {
				continue;
			}
			$c = new self();
			$lines = array_merge($lines, $c->backfillVendor($vendor, $daily, $dryRun, $force));
		}
		flock($lock, LOCK_UN);
		return $lines;
	}

	/** @return array<int, string> */
	private function backfillVendor(string $vendor, bool $daily, bool $dryRun, bool $force): array
	{
		$label = self::VENDORS[$vendor];
		if ($vendor === 'enphase') {
			return [self::stamp() . " $label: nothing to backfill; Enlighten gives this year's total directly."];
		}
		if ($vendor === 'growatt') {
			return $this->backfillGrowatt($daily, $dryRun);
		}
		$months = [];
		for ($m = 1; $m < (int) date('n'); $m++) {
			$months[] = date('Y') . '-' . sprintf('%02d', $m);
		}
		if (!$months) {
			return [self::stamp() . " $label: nothing to backfill in January."];
		}
		$sites = Db::all("SELECT * FROM monitor_sites WHERE vendor = ? AND ignored = 0 AND state != 'pending' ORDER BY id", [$vendor]);
		if (!$sites) {
			return [self::stamp() . " $label: no systems stored yet; run monitor:poll --vendor=$vendor --force first."];
		}
		$todo = [];
		foreach ($sites as $site) {
			$need = [];
			foreach ($daily ? $months : [] as $month) {
				$have = (int) Db::value('SELECT COUNT(*) FROM monitor_daily WHERE site_id = ? AND date LIKE ?', [$site['id'], $month . '-%']);
				if ($have < (int) date('t', strtotime($month . '-01')) - 1) {
					$need[] = $month;
				}
			}
			$todo[] = [$site, $need];
		}
		$estimate = count($sites) + array_sum(array_map(static fn ($t) => count($t[1]), $todo));
		$left = $vendor === 'solaredge'
			? self::seBudget()[0]
			: self::AP_MONTHLY_CAP - self::callsSince('apsystems', strtotime(date('Y-m-01 00:00:00')));
		$unit = $vendor === 'solaredge' ? 'credits' : 'calls';
		$summary = sprintf('%d systems, monthly totals %s to %s%s: about %d %s (%d left)', count($sites), $months[0], end($months),
			$daily ? ' plus daily values' : '', $estimate, $unit, $left);
		if ($dryRun) {
			return [self::stamp() . " $label: would backfill $summary. Dry run: no calls made."];
		}
		if ($estimate > $left && !$force) {
			return [self::stamp() . " $label: not enough left for $summary. Use --force to run anyway."];
		}

		$runId = Db::insert('monitor_runs', ['vendor' => $vendor, 'started_at' => now_utc()]);
		$this->runId = $runId;
		$ok = false;
		try {
			foreach ($todo as [$site, $need]) {
				$vid = (string) $site['vendor_site_id'];
				$id = (int) $site['id'];
				if ($vendor === 'solaredge') {
					$r = $this->seGet('/sites/' . $vid . '/energy', [
						'from' => date('Y-m-d\TH:i:sP', strtotime($months[0] . '-01 00:00:00')),
						'to' => date('Y-m-d\TH:i:sP', strtotime(date('Y-m-01 00:00:00')) - 1),
						'resolution' => 'MONTH',
					]);
					foreach ($r['json']['values'] ?? [] as $v) {
						if (isset($v['value']) && $v['value'] !== null) {
							self::setMonthly($id, substr((string) $v['timestamp'], 0, 7), (float) $v['value']);
						}
					}
					foreach ($need as $month) {
						$first = strtotime($month . '-01 00:00:00');
						$r = $this->seGet('/sites/' . $vid . '/energy', [
							'from' => date('Y-m-d\TH:i:sP', $first),
							'to' => date('Y-m-d\TH:i:sP', strtotime('+1 month', $first) - 1),
							'resolution' => 'DAY',
						]);
						foreach ($r['json']['values'] ?? [] as $v) {
							if (isset($v['value']) && $v['value'] !== null) {
								self::setDaily($id, substr((string) $v['timestamp'], 0, 10), (float) $v['value']);
							}
						}
					}
				} else {
					$list = self::apData(APsystems::request('GET', '/systems/energy/' . $vid, ['energy_level' => 'monthly', 'date_range' => date('Y')]));
					$this->tick();
					foreach (is_array($list) ? array_values($list) : [] as $i => $kwh) {
						$month = date('Y') . '-' . sprintf('%02d', $i + 1);
						if ($kwh !== null && $kwh !== '' && in_array($month, $months, true)) {
							self::setMonthly($id, $month, (float) $kwh * 1000);
						}
					}
					foreach ($need as $month) {
						$days = self::apData(APsystems::request('GET', '/systems/energy/' . $vid, ['energy_level' => 'daily', 'date_range' => $month]));
						$this->tick();
						foreach (is_array($days) ? array_values($days) : [] as $i => $kwh) {
							if ($kwh !== null && $kwh !== '') {
								self::setDaily($id, $month . '-' . sprintf('%02d', $i + 1), (float) $kwh * 1000);
							}
						}
					}
				}
			}
			$ok = true;
			$message = 'Backfill done: ' . $summary;
		} catch (\Throwable $e) {
			$message = 'Backfill stopped: ' . $e->getMessage();
		}
		Db::update('monitor_runs', $runId, ['finished_at' => now_utc(), 'calls' => $this->calls, 'ok' => $ok ? 1 : 0, 'message' => substr($message, 0, 500)]);
		return [self::stamp() . " $label: $message ({$this->calls} $unit used)"];
	}

	/**
	 * Growatt: this year's months before this one as monthly totals per site, plus this month's days
	 * before tracking began (so the month tile is whole); with $daily, every day of the earlier months
	 * too. Growatt has no call limit, so there is no budget check; calls are spaced a second apart.
	 *
	 * @return array<int, string>
	 */
	private function backfillGrowatt(bool $daily, bool $dryRun): array
	{
		$label = self::VENDORS['growatt'];
		$sites = Db::all("SELECT id, vendor_site_id FROM monitor_sites WHERE vendor = 'growatt' ORDER BY id");
		if (!$sites) {
			return [self::stamp() . " $label: no sites stored yet; run monitor:poll --vendor=growatt --force first."];
		}
		$tz = new \DateTimeZone(date_default_timezone_get());
		$jan = new \DateTimeImmutable(date('Y') . '-01-01', $tz);
		$thisMonth = new \DateTimeImmutable(date('Y-m-01'), $tz);
		$yesterday = date('Y-m-d', strtotime('-1 day'));
		$plan = [];
		foreach ($sites as $site) {
			$months = [];
			$days = [];
			for ($m = $jan; $m < $thisMonth; $m = $m->modify('+1 month')) {
				$months[] = $m;
				if ($daily) {
					for ($d = $m; $d < $m->modify('+1 month'); $d = $d->modify('+1 day')) {
						$days[] = $d;
					}
				}
			}
			for ($d = $thisMonth; $d->format('Y-m-d') <= $yesterday; $d = $d->modify('+1 day')) {
				$days[] = $d;
			}
			$have = array_flip(array_column(Db::all('SELECT date FROM monitor_daily WHERE site_id = ? AND date >= ?', [$site['id'], $jan->format('Y-m-d')]), 'date'));
			$days = array_values(array_filter($days, static fn ($d) => !isset($have[$d->format('Y-m-d')])));
			$plan[] = [$site, $months, $days];
		}
		$estimate = array_sum(array_map(static fn ($p) => count($p[1]) + count($p[2]), $plan));
		$summary = count($sites) . ' sites, monthly totals for ' . count($plan[0][1]) . ' months' . ($daily ? ' and every missing day' : " and this month's missing days") . ": about $estimate calls (no limit)";
		if ($dryRun) {
			return [self::stamp() . " $label: would backfill $summary. Dry run: no calls made."];
		}
		$runId = Db::insert('monitor_runs', ['vendor' => 'growatt', 'started_at' => now_utc()]);
		$this->runId = $runId;
		$ok = false;
		try {
			foreach ($plan as [$site, $months, $days]) {
				$sid = (string) $site['vendor_site_id'];
				foreach ($months as $m) {
					$p = Growatt::production($sid, $m, $m->modify('+1 month')->modify('-1 second'));
					$this->tick();
					self::setMonthly((int) $site['id'], $m->format('Y-m'), $p['kwh'] * 1000);
					usleep(1_000_000);
				}
				foreach ($days as $d) {
					$p = Growatt::production($sid, $d, $d->setTime(23, 59, 59));
					$this->tick();
					self::setDaily((int) $site['id'], $d->format('Y-m-d'), $p['kwh'] * 1000);
					usleep(1_000_000);
				}
			}
			$ok = true;
			$message = 'Backfill done: ' . $summary;
		} catch (\Throwable $e) {
			$message = 'Backfill stopped: ' . $e->getMessage();
		}
		Db::update('monitor_runs', $runId, ['finished_at' => now_utc(), 'calls' => $this->calls, 'ok' => $ok ? 1 : 0, 'message' => substr($message, 0, 500)]);
		return [self::stamp() . " $label: $message ({$this->calls} calls)"];
	}

	private static function setMonthly(int $siteId, string $month, float $wh): void
	{
		Db::run('INSERT INTO monitor_monthly (site_id, month, wh) VALUES (?, ?, ?) ON CONFLICT (site_id, month) DO UPDATE SET wh = excluded.wh', [$siteId, $month, $wh]);
	}

	// ---- schedules ---------------------------------------------------------------------------

	private function daytimeEvery30(string $vendor): array
	{
		$h = (int) date('G');
		if ($h < 6 || $h > 21) {
			return [false, 'outside 6 AM to 9 PM'];
		}
		$last = self::lastOk($vendor);
		return $last !== null && $last > time() - 25 * 60 ? [false, 'pulled within 30 minutes'] : [true, ''];
	}

	private function apDue(): array
	{
		if ((int) date('N') >= 6) {
			return [false, 'weekends are filled in on Monday'];
		}
		$h = (int) date('G');
		$slots = array_filter(self::AP_HOURS, static fn (int $s) => $s <= $h);
		if (!$slots) {
			return [false, 'first pull at 8 AM'];
		}
		$slotStart = strtotime(date('Y-m-d') . ' ' . max($slots) . ':00:00');
		$last = self::lastOk('apsystems');
		if ($last !== null && $last >= $slotStart) {
			return [false, 'already pulled this slot'];
		}
		$used = self::callsSince('apsystems', strtotime(date('Y-m-01 00:00:00')));
		return $used >= self::AP_MONTHLY_CAP ? [false, "$used calls used this month"] : [true, ''];
	}

	private function seDue(): array
	{
		if ((int) date('N') >= 6) {
			return [false, 'weekends are fetched on Monday'];
		}
		if ((int) date('G') < 8) {
			return [false, 'pulls from 8 AM'];
		}
		$last = self::lastOk('solaredge');
		if ($last !== null && date('Y-m-d', $last) === date('Y-m-d')) {
			return [false, 'already pulled today'];
		}
		[$remaining, $weekdaysLeft] = self::seBudget();
		$needed = self::seNeeded();
		if ($remaining < $needed) {
			return [false, "$remaining credits left this cycle, a pull needs about $needed"];
		}
		if ((int) date('N') === 5 && $remaining < $needed * $weekdaysLeft) {
			return [false, "Friday skipped to save credits ($remaining left for $weekdaysLeft weekdays); Monday catches up"];
		}
		return [true, ''];
	}

	/** @return array{0:int,1:int} credits left this cycle, weekdays left in the cycle including today */
	public static function seBudget(): array
	{
		$day = max(1, min(28, (int) (Config::get('SOLAREDGE_CYCLE_DAY') ?? 1)));
		$credits = (int) (Config::get('SOLAREDGE_CREDITS') ?? 2000);
		$start = mktime(0, 0, 0, (int) date('n'), $day, (int) date('Y'));
		if ($start > time()) {
			$start = strtotime('-1 month', $start);
		}
		$end = strtotime('+1 month', $start);
		$weekdays = 0;
		for ($t = strtotime(date('Y-m-d')); $t < $end; $t += 86400) {
			if ((int) date('N', $t) < 6) {
				$weekdays++;
			}
		}
		return [$credits - self::SE_SAFETY - self::callsSince('solaredge', $start), max(1, $weekdays)];
	}

	private static function seNeeded(): int
	{
		$n = (int) Db::value("SELECT COUNT(*) FROM monitor_sites WHERE vendor = 'solaredge' AND ignored = 0 AND state NOT IN ('pending', 'offline')");
		return 2 + ($n ?: 100);
	}

	/** Last successful scheduled pull (backfill runs do not count, so they never delay one). */
	private static function lastOk(string $vendor): ?int
	{
		$at = Db::value("SELECT MAX(started_at) FROM monitor_runs WHERE vendor = ? AND ok = 1 AND COALESCE(message, '') NOT LIKE 'Backfill%'", [$vendor]);
		return $at ? (strtotime((string) $at) ?: null) : null;
	}

	private static function callsSince(string $vendor, int $since): int
	{
		return (int) Db::value('SELECT COALESCE(SUM(calls), 0) FROM monitor_runs WHERE vendor = ? AND started_at >= ?', [$vendor, gmdate('Y-m-d\TH:i:s\Z', $since)]);
	}

	// ---- Enphase -----------------------------------------------------------------------------

	private function enphase(): string
	{
		$r = Enlighten::systems();
		$this->calls += str_contains((string) ($r['note'] ?? ''), 'signed in') || str_contains((string) ($r['note'] ?? ''), 'Signed in') ? 3 : 1;
		$rows = $r['json']['data'] ?? null;
		if ($r['status'] !== 200 || !is_array($rows)) {
			throw new \RuntimeException(trim(($r['note'] ?? '') . ' ' . ($r['error'] ?? 'HTTP ' . $r['status'])));
		}
		$cell = static fn (mixed $v): string => is_array($v) ? (string) ($v['text'] ?? '') : (string) ($v ?? '');
		$today = date('Y-m-d');
		foreach ($rows as $s) {
			$id = $cell($s['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$status = $cell($s['status'] ?? '');
			$issues = (int) $cell($s['issue_count'] ?? '0');
			$kw = self::num($s['capacity'] ?? null);
			if ($kw !== null && $kw > 1000) {
				$kw /= 1000; // reported in W
			}
			$siteId = self::upsertSite('enphase', $id, [
				'name' => $cell($s['name'] ?? ''),
				'capacity_kw' => $kw,
				'state' => strcasecmp($status, 'Normal') === 0 && $issues === 0 ? 'ok' : 'alert',
				'status_text' => $status . ($issues ? ", $issues " . ($issues === 1 ? 'issue' : 'issues') : ''),
				'alert_count' => $issues,
				'alert_impact' => null,
				'last_report_at' => $cell($s['last_report_date'] ?? '') ?: null,
				'today_date' => $today,
				'today_wh' => self::num($s['today_production'] ?? null),
				'week_wh' => self::num($s['last_7_days_production'] ?? null),
				'month_wh' => self::num($s['month_to_date_production'] ?? null),
				'year_wh' => self::num($s['yearly_production'] ?? null),
				'lifetime_wh' => self::num($s['lifetime_production'] ?? null),
			]);
			$wh = self::num($s['today_production'] ?? null);
			if ($wh !== null) {
				self::setDaily($siteId, $today, $wh);
			}
		}
		return count($rows) . ' systems';
	}

	// ---- Growatt -----------------------------------------------------------------------------

	private function growatt(): string
	{
		$sites = [];
		for ($page = 1; $page <= 10; $page++) {
			$r = Growatt::get('/web/v1/sites/' . $page . '/10?name=&filter=');
			$this->tick();
			$list = $r['json']['data']['list'] ?? null;
			if ($r['status'] !== 200 || !is_array($list)) {
				throw new \RuntimeException(trim(($r['note'] ?? '') . ' Site list: HTTP ' . $r['status'] . ($r['error'] ? ' ' . $r['error'] : '')));
			}
			$sites = array_merge($sites, $list);
			if (empty($r['json']['data']['page']['nextPage'])) {
				break;
			}
		}

		$today = date('Y-m-d');
		$alertTotal = 0;
		foreach ($sites as $s) {
			$sid = (string) ($s['id'] ?? '');
			if ($sid === '') {
				continue;
			}
			$total = (int) ($s['total'] ?? 0);
			$online = (int) ($s['online'] ?? $total);
			$alerts = (int) ($s['alerts'] ?? 0);
			$alertTotal += $alerts;
			$offline = max(0, $total - $online);
			$parts = [];
			if ($offline) {
				$parts[] = "$offline of $total inverters offline";
			}
			if ($alerts) {
				$parts[] = $alerts . ' ' . ($alerts === 1 ? 'alert' : 'alerts');
			}
			$todayWh = isset($s['energy_today']) && is_numeric($s['energy_today']) ? (float) $s['energy_today'] * 1000 : null;
			// "capacity" is whatever was typed into Shiner (often blank or 1), so it is not used.
			$id = self::upsertSite('growatt', $sid, [
				'name' => (string) (($s['alias'] ?? '') ?: ($s['name'] ?? '')),
				'state' => $total > 0 && $online === 0 ? 'offline' : ($parts ? 'alert' : 'ok'),
				'status_text' => $parts ? implode(', ', $parts) : "Online ($online of $total inverters)",
				'alert_count' => $alerts + $offline,
				'alert_impact' => null,
				'last_report_at' => ($s['update_time'] ?? '') ?: null,
				'today_date' => $today,
				'today_wh' => $todayWh,
			]);
			if ($todayWh !== null) {
				self::setDaily($id, $today, $todayWh);
			}
		}
		// Once a day: yesterday's exact total per site (rather than the last evening reading) and each
		// site's inverter AC rating, from the portal's production-by-window reply.
		$finals = 0;
		if (!self::lastOkOn('growatt', $today)) {
			$tz = new \DateTimeZone(date_default_timezone_get());
			$y0 = new \DateTimeImmutable('yesterday', $tz);
			$y1 = $y0->setTime(23, 59, 59);
			foreach (Db::all("SELECT id, vendor_site_id FROM monitor_sites WHERE vendor = 'growatt'") as $row) {
				$p = Growatt::production((string) $row['vendor_site_id'], $y0, $y1);
				$this->tick();
				self::setDaily((int) $row['id'], $y0->format('Y-m-d'), $p['kwh'] * 1000);
				if ($p['pmax_w'] !== null) {
					Db::run('UPDATE monitor_sites SET capacity_ac_kw = ? WHERE id = ?', [$p['pmax_w'] / 1000, $row['id']]);
				}
				$finals++;
			}
		}
		return count($sites) . " sites, $alertTotal alerts" . ($finals ? ", yesterday's totals for $finals" : '');
	}

	// ---- APsystems ---------------------------------------------------------------------------

	private function apsystems(): string
	{
		$firstToday = self::lastOkOn('apsystems', date('Y-m-d')) === false;
		$known = Db::all("SELECT id, vendor_site_id FROM monitor_sites WHERE vendor = 'apsystems'");
		if ($firstToday || !$known) {
			$r = APsystems::request('POST', '/systems', [], ['page' => 1, 'size' => 50]);
			$this->tick();
			$list = self::apData($r)['systems'] ?? [];
			foreach ($list as $s) {
				$light = (int) ($s['light'] ?? 0);
				self::upsertSite('apsystems', (string) $s['sid'], [
					'capacity_kw' => self::num($s['capacity'] ?? null),
					'state' => match ($light) { 1 => 'ok', 2, 3 => 'alert', 4 => 'offline', default => 'unknown' },
					'status_text' => match ($light) { 1 => 'Green', 2 => 'Yellow', 3 => 'Red', 4 => 'Grey (offline)', default => 'Unknown' },
					'alert_count' => in_array($light, [2, 3], true) ? 1 : 0,
					'alert_impact' => $light ?: null,
				], ['name' => 'APsystems ' . (string) $s['sid']]);
			}
			$known = Db::all("SELECT id, vendor_site_id FROM monitor_sites WHERE vendor = 'apsystems'");
		}
		$month = date('Y-m');
		$today = date('Y-m-d');
		foreach ($known as $site) {
			$sid = (string) $site['vendor_site_id'];
			$r = APsystems::request('GET', '/systems/energy/' . $sid, ['energy_level' => 'daily', 'date_range' => $month]);
			$this->tick();
			$days = self::apData($r);
			$monthWh = 0.0;
			$todayWh = null;
			foreach (is_array($days) ? array_values($days) : [] as $i => $kwh) {
				if ($kwh === null || $kwh === '') {
					continue;
				}
				$date = $month . '-' . sprintf('%02d', $i + 1);
				$wh = (float) $kwh * 1000;
				self::setDaily((int) $site['id'], $date, $wh);
				$monthWh += $wh;
				if ($date === $today) {
					$todayWh = $wh;
				}
			}
			$fields = ['today_date' => $today, 'today_wh' => $todayWh, 'month_wh' => $monthWh];
			if ($firstToday) {
				$sum = self::apData(APsystems::request('GET', '/systems/summary/' . $sid));
				$this->tick();
				$fields['year_wh'] = isset($sum['year']) ? (float) $sum['year'] * 1000 : null;
				$fields['lifetime_wh'] = isset($sum['lifetime']) ? (float) $sum['lifetime'] * 1000 : null;
			}
			self::upsertSite('apsystems', $sid, $fields);
		}
		return count($known) . ' systems';
	}

	private static function apData(array $r): mixed
	{
		$code = $r['json']['code'] ?? null;
		if ($r['status'] === 200 && $code === 1001) {
			return null; // "No data": e.g. a month before the system was producing
		}
		if ($r['status'] !== 200 || $code !== 0) {
			throw new \RuntimeException('APsystems ' . ($r['error'] ?? 'HTTP ' . $r['status']) . ($code !== null ? ", code $code" : ''));
		}
		return $r['json']['data'] ?? null;
	}

	// ---- SolarEdge ---------------------------------------------------------------------------

	private function solaredge(): string
	{
		$r = $this->seGet('/sites', ['page' => 1, 'sites-in-page' => 1000]);
		$sites = $r['json']['sites']['site'] ?? null;
		if (!is_array($sites)) {
			throw new \RuntimeException('Site list: HTTP ' . $r['status']);
		}
		$alerts = [];
		for ($page = 1; $page <= 5; $page++) {
			$a = $this->seGet('/alerts', ['page' => $page, 'alerts-in-page' => 100]);
			$list = is_array($a['json']) && array_is_list($a['json']) ? $a['json'] : [];
			foreach ($list as $al) {
				$alerts[(string) ($al['siteId'] ?? '')][] = $al;
			}
			if (count($list) < 100) {
				break;
			}
		}

		$pull = [];
		foreach ($sites as $s) {
			$vid = (string) ($s['siteId'] ?? '');
			if ($vid === '') {
				continue;
			}
			$mine = $alerts[$vid] ?? [];
			$types = array_values(array_unique(array_map(static fn ($al) => self::humanType((string) ($al['type'] ?? '')), $mine)));
			$commFault = in_array('SITE_COMMUNICATION_FAULT', array_column($mine, 'type'), true);
			$pending = strtoupper((string) ($s['activationStatus'] ?? '')) === 'PENDING';
			$lastTrigger = $mine ? max(array_map(static fn ($al) => (string) ($al['lastTrigger'] ?? ''), $mine)) : null;
			$id = self::upsertSite('solaredge', $vid, [
				'name' => (string) ($s['name'] ?? ''),
				'capacity_kw' => self::num($s['peakPower'] ?? null),
				'state' => $pending ? 'pending' : ($commFault ? 'offline' : ($mine ? 'alert' : 'ok')),
				'status_text' => $pending ? 'Pending activation' : ($mine ? implode(', ', $types) : 'Normal'),
				'alert_count' => count($mine),
				'alert_impact' => $mine ? max(array_map(static fn ($al) => (int) ($al['impact'] ?? 0), $mine)) : null,
				'last_report_at' => $commFault ? $lastTrigger : null,
			]);
			if (!$pending && !$commFault) {
				$pull[] = $id;
			}
		}

		$floor = date('Y-m-d', strtotime('-27 days'));
		$fetched = 0;
		foreach ($pull as $id) {
			$site = Db::one('SELECT * FROM monitor_sites WHERE id = ?', [$id]);
			if (!$site || $site['ignored']) {
				continue;
			}
			$from = $site['energy_through'] ?: date('Y-m-01');
			if ($from < $floor) {
				$from = $floor; // DAY resolution covers at most one month per call
			}
			$e = $this->seGet('/sites/' . $site['vendor_site_id'] . '/energy', [
				'from' => date('Y-m-d\TH:i:sP', strtotime($from . ' 00:00:00')),
				'to' => date('Y-m-d\TH:i:sP'),
				'resolution' => 'DAY',
			]);
			$latest = null;
			foreach ($e['json']['values'] ?? [] as $v) {
				if (!isset($v['value']) || $v['value'] === null) {
					continue;
				}
				$date = substr((string) $v['timestamp'], 0, 10);
				self::setDaily($id, $date, (float) $v['value']);
				$latest = [$date, (float) $v['value']];
			}
			if ($latest) {
				// No today_* for SolarEdge: one morning pull a day, so "today" would read near zero.
				self::upsertSite('solaredge', (string) $site['vendor_site_id'], ['energy_through' => $latest[0]]);
				$fetched++;
			}
		}
		return count($sites) . ' sites, ' . array_sum(array_map('count', $alerts)) . " open alerts, energy for $fetched";
	}

	/** One SolarEdge call: counted, paced under 10 a minute, and stopped cleanly at the credit limit. */
	private function seGet(string $path, array $query): array
	{
		if ($this->calls > 0) {
			usleep(6_200_000);
		}
		$r = SolarEdge::get($path, $query);
		$this->tick();
		if ($r['status'] === 429) {
			throw new \RuntimeException('SolarEdge 429: ' . substr((string) ($r['json']['detail'] ?? $r['json']['message'] ?? 'limit reached'), 0, 120));
		}
		if ($r['status'] !== 200) {
			throw new \RuntimeException("SolarEdge $path: HTTP " . $r['status'] . ($r['error'] ? ' ' . $r['error'] : ''));
		}
		return $r;
	}

	private static function humanType(string $type): string
	{
		return ucfirst(strtolower(str_replace('_', ' ', $type)));
	}

	/** Count one vendor call and save the count right away, so a run that is stopped midway still counts. */
	private function tick(): void
	{
		$this->calls++;
		if ($this->runId !== null) {
			Db::run('UPDATE monitor_runs SET calls = ? WHERE id = ?', [$this->calls, $this->runId]);
		}
	}

	// ---- storage -----------------------------------------------------------------------------

	/** Insert or update a site; $insertOnly fields are set only when the site is new. Returns its id. */
	private static function upsertSite(string $vendor, string $vid, array $fields, array $insertOnly = []): int
	{
		$row = Db::one('SELECT id FROM monitor_sites WHERE vendor = ? AND vendor_site_id = ?', [$vendor, $vid]);
		$fields['updated_at'] = now_utc();
		if ($row) {
			Db::update('monitor_sites', (int) $row['id'], $fields);
			return (int) $row['id'];
		}
		return Db::insert('monitor_sites', ['vendor' => $vendor, 'vendor_site_id' => $vid, 'first_seen_at' => now_utc()] + $fields + $insertOnly);
	}

	private static function setDaily(int $siteId, string $date, float $wh): void
	{
		Db::run('INSERT INTO monitor_daily (site_id, date, wh) VALUES (?, ?, ?) ON CONFLICT (site_id, date) DO UPDATE SET wh = excluded.wh', [$siteId, $date, $wh]);
	}

	private static function lastOkOn(string $vendor, string $localDate): bool
	{
		$last = self::lastOk($vendor);
		return $last !== null && date('Y-m-d', $last) === $localDate;
	}

	private static function num(mixed $v): ?float
	{
		if (is_array($v)) {
			$v = $v['text'] ?? null;
		}
		if ($v === null || $v === '') {
			return null;
		}
		$clean = preg_replace('/[^0-9.\-]/', '', (string) $v);
		return is_numeric($clean) ? (float) $clean : null;
	}

	private static function stamp(): string
	{
		return date('Y-m-d H:i:s');
	}
}
