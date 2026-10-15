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
 *  - SMA (Sunny Portal sign-in, App\Sma): the status list (one call: every plant's state and today's
 *    yield) and the year's monthly totals (one call for all plants), every 30 minutes 6 AM to 9 PM, every
 *    day: free, and the day's last pull becomes that day's stored total. Once a day, the last 35 days
 *    (one call for all plants) replace the stored days with SMA's final values, and each plant's peak
 *    power is read (one call each).
 *  - SolarEdge (V2 Free tier, 2,000 credits a cycle, 1 credit a call, 10 calls a minute): weekdays from
 *    8 AM, as often as the cycle's remaining credits allow, spread evenly over the weekdays left
 *    (sePace: every weekday when they cover one pull per weekday, otherwise every 2, 3... weekdays,
 *    recomputed each day). A pull is the site list, fleet alerts, then daily energy for each active
 *    site since its last pull, so a skipped day is filled in by the next pull at no extra cost.
 *    Skips pending sites and sites with a site communication fault (nothing new to fetch; the next
 *    pull after the fault clears catches up).
 *
 * Every vendor call is counted in monitor_runs. The page reads only the database.
 */
final class MonitorCollector
{
	public const VENDORS = ['enphase' => 'Enphase', 'apsystems' => 'APsystems', 'growatt' => 'Growatt', 'sma' => 'SMA', 'solaredge' => 'SolarEdge'];

	/** How each vendor's data is read: [short label, kind, detail]. kind 'api' = published API, 'signin' = portal sign-in. */
	public const SOURCES = [
		'enphase' => ['Scrape', 'signin', 'Enlighten Manager, read as the installer user (not a published API)'],
		'apsystems' => ['API', 'api', 'APsystems OpenAPI, free Lv0 plan (1,000 calls a month)'],
		'solaredge' => ['API', 'api', 'SolarEdge Monitoring API V2, Free tier (2,000 credits a cycle)'],
		'growatt' => ['Scrape', 'signin', 'Growatt Shiner portal, read as the installer account (captcha typed about once a month)'],
		'sma' => ['Scrape', 'signin', 'Sunny Portal (ennexOS), read as the installer SMA ID (not a published API)'],
	];

	private const AP_HOURS = [8, 11, 14, 17];
	private const AP_MONTHLY_CAP = 950;
	private const SE_SAFETY = 20;

	private int $calls = 0;
	private ?int $runId = null;
	/** Pulled with --force: also redo the once-a-day parts (APsystems list and summaries). */
	private bool $forced = false;
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
			'sma' => Sma::configured() && Sma::available(),
			'enphase' => Enlighten::configured() && Enlighten::available(),
			'apsystems' => APsystems::configured(),
			'solaredge' => SolarEdge::configured(),
		};
		if (!$configured) {
			return [self::stamp() . " $label: not set up in .env; skipped."];
		}
		[$due, $why] = match ($vendor) {
			'enphase', 'growatt', 'sma' => $this->daytimeEvery30($vendor),
			'apsystems' => $this->apDue(),
			'solaredge' => $this->seDue(),
		};
		if (!$due && !$force) {
			return [self::stamp() . " $label: not due ($why)."];
		}
		if ($dryRun) {
			return [self::stamp() . " $label: would pull now" . ($due ? '' : ' (forced; ' . $why . ')') . '. Dry run: no calls made.'];
		}

		$this->forced = $force;
		$runId = Db::insert('monitor_runs', ['vendor' => $vendor, 'started_at' => now_utc()]);
		$this->runId = $runId;
		$ok = false;
		$message = '';
		try {
			$message = match ($vendor) {
				'growatt' => $this->growatt(),
				'sma' => $this->sma(),
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
	public static function backfill(?string $only, bool $daily, bool $dryRun, bool $force, bool $months = false): array
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
			$lines = array_merge($lines, $months ? $c->backfillMonths($vendor, $dryRun, $force) : $c->backfillVendor($vendor, $daily, $dryRun, $force));
		}
		flock($lock, LOCK_UN);
		return $lines;
	}

	/** First month of the 13-month chart: the same month last year (the current month is the 13th). */
	public static function monthsStart(): string
	{
		return date('Y-m', strtotime(date('Y-m-01') . ' -12 months'));
	}

	/**
	 * monitor:backfill --months: whole-month totals for the 12 months before this one wherever a site has
	 * neither a month total nor (nearly) every day of that month stored, for the 13-month chart.
	 * SolarEdge 1 credit per site, APsystems 1 call per system per year, SMA 1 request in all, Growatt
	 * and Enphase 1 request per site per month (free).
	 *
	 * @return array<int, string>
	 */
	private function backfillMonths(string $vendor, bool $dryRun, bool $force): array
	{
		$label = self::VENDORS[$vendor];
		$window = [];
		for ($t = strtotime(self::monthsStart() . '-01'); $t < strtotime(date('Y-m-01')); $t = strtotime('+1 month', $t)) {
			$window[] = date('Y-m', $t);
		}
		$sites = Db::all("SELECT id, vendor_site_id FROM monitor_sites WHERE vendor = ? AND ignored = 0 AND state != 'pending' ORDER BY id", [$vendor]);
		if (!$sites) {
			return [self::stamp() . " $label: no systems stored yet; run monitor:poll --vendor=$vendor --force first."];
		}
		$todo = [];
		foreach ($sites as $site) {
			$need = [];
			foreach ($window as $month) {
				$hasMonth = Db::value('SELECT 1 FROM monitor_monthly WHERE site_id = ? AND month = ?', [$site['id'], $month]);
				$days = (int) Db::value('SELECT COUNT(*) FROM monitor_daily WHERE site_id = ? AND date LIKE ?', [$site['id'], $month . '-%']);
				if (!$hasMonth && $days < (int) date('t', strtotime($month . '-01')) - 1) {
					$need[] = $month;
				}
			}
			if ($need) {
				$todo[] = [$site, $need];
			}
		}
		if (!$todo) {
			return [self::stamp() . " $label: every month from " . $window[0] . ' is already stored.'];
		}
		$estimate = match ($vendor) {
			'solaredge' => count($todo),
			'apsystems' => array_sum(array_map(static fn ($t) => count(array_unique(array_map(static fn ($m) => substr($m, 0, 4), $t[1]))), $todo)),
			'sma' => 1,
			default => array_sum(array_map(static fn ($t) => count($t[1]), $todo)),
		};
		$unit = $vendor === 'solaredge' ? 'credits' : 'calls';
		$summary = count($todo) . " systems missing months from {$window[0]}: about $estimate $unit";
		if ($vendor === 'solaredge') {
			$summary .= ' (' . self::seBudget()[0] . ' left)';
		}
		if ($dryRun) {
			return [self::stamp() . " $label: would fill $summary. Dry run: no calls made."];
		}
		if ($vendor === 'solaredge' && $estimate > self::seBudget()[0] && !$force) {
			return [self::stamp() . " $label: not enough credits left for $summary. Use --force to run anyway."];
		}

		$runId = Db::insert('monitor_runs', ['vendor' => $vendor, 'started_at' => now_utc()]);
		$this->runId = $runId;
		$ok = false;
		$tz = new \DateTimeZone(date_default_timezone_get());
		$stored = 0;
		try {
			if ($vendor === 'sma') {
				$ids = [];
				$from = $window[count($window) - 1];
				foreach ($todo as [$site, $need]) {
					$ids[(string) $site['vendor_site_id']] = [(int) $site['id'], $need];
					$from = min($from, $need[0]);
				}
				$e = Sma::energy(array_map('strval', array_keys($ids)), 'OneMonth', new \DateTimeImmutable($from . '-01', $tz), new \DateTimeImmutable(date('Y-m-01'), $tz));
				$this->tick();
				if ($e['result']['status'] !== 200) {
					throw new \RuntimeException('Monthly energy: ' . ($e['result']['error'] ?? 'HTTP ' . $e['result']['status']));
				}
				foreach ($ids as $pid => [$siteId, $need]) {
					foreach ($need as $month) {
						// A month SMA reports nothing for is stored as zero: the plant was not producing yet.
						self::setMonthly($siteId, $month, (float) ($e['values'][$pid][$month] ?? 0));
						$stored++;
					}
				}
			}
			foreach ($vendor === 'sma' ? [] : $todo as [$site, $need]) {
				$vid = (string) $site['vendor_site_id'];
				$id = (int) $site['id'];
				if ($vendor === 'solaredge') {
					$r = $this->seGet('/sites/' . $vid . '/energy', [
						'from' => date('Y-m-d\TH:i:sP', strtotime($need[0] . '-01 00:00:00')),
						'to' => date('Y-m-d\TH:i:sP', strtotime('+1 month', strtotime(end($need) . '-01 00:00:00')) - 1),
						'resolution' => 'MONTH',
					]);
					$got = [];
					foreach ($r['json']['values'] ?? [] as $v) {
						$month = substr((string) ($v['timestamp'] ?? ''), 0, 7);
						if (isset($v['value']) && $v['value'] !== null && in_array($month, $need, true)) {
							$got[$month] = (float) $v['value'];
						}
					}
					// Months the reply has no value for are stored as zero: the site was not producing yet.
					foreach ($need as $month) {
						self::setMonthly($id, $month, $got[$month] ?? 0.0);
						$stored++;
					}
				} elseif ($vendor === 'apsystems') {
					foreach (array_unique(array_map(static fn ($m) => substr($m, 0, 4), $need)) as $year) {
						$list = self::apData(APsystems::request('GET', '/systems/energy/' . $vid, ['energy_level' => 'monthly', 'date_range' => $year]));
						$this->tick();
						$got = [];
						foreach (is_array($list) ? array_values($list) : [] as $i => $kwh) {
							if ($kwh !== null && $kwh !== '') {
								$got[$year . '-' . sprintf('%02d', $i + 1)] = (float) $kwh * 1000;
							}
						}
						// Months with no value ("no data" for the year included) are stored as zero.
						foreach ($need as $month) {
							if (str_starts_with($month, $year . '-')) {
								self::setMonthly($id, $month, $got[$month] ?? 0.0);
								$stored++;
							}
						}
					}
				} else {
					foreach ($need as $month) {
						$first = new \DateTimeImmutable($month . '-01', $tz);
						$last = $first->modify('last day of this month');
						if ($vendor === 'growatt') {
							$p = Growatt::production($vid, $first, $last->setTime(23, 59, 59));
							$wh = $p['kwh'] * 1000;
							$got = true;
						} else {
							$p = Enlighten::energy($vid, $first->format('Y-m-d'), $last->format('Y-m-d'));
							$wh = $p['wh'];
							$got = $p['micros'] > 0;
						}
						$this->tick();
						self::setMonthly($id, $month, $got ? $wh : 0.0);
						$stored++;
						usleep(2_000_000);
					}
				}
			}
			$ok = true;
			$message = "Backfill done: $summary; $stored month totals stored";
		} catch (\Throwable $e) {
			$message = "Backfill stopped: " . $e->getMessage() . " ($stored month totals stored)";
		}
		Db::update('monitor_runs', $runId, ['finished_at' => now_utc(), 'calls' => $this->calls, 'ok' => $ok ? 1 : 0, 'message' => substr($message, 0, 500)]);
		return [self::stamp() . " $label: $message ({$this->calls} $unit used)"];
	}

	/** @return array<int, string> */
	private function backfillVendor(string $vendor, bool $daily, bool $dryRun, bool $force): array
	{
		$label = self::VENDORS[$vendor];
		if ($vendor === 'enphase') {
			return $this->backfillEnphase($daily, $dryRun);
		}
		if ($vendor === 'growatt') {
			return $this->backfillGrowatt($daily, $dryRun);
		}
		if ($vendor === 'sma') {
			return $this->backfillSma($dryRun);
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
	 * Growatt: this year's months before this one as monthly totals per site, plus the days of last
	 * month and this one before tracking began (so the rolling 30 days is whole); with $daily, every day
	 * of the earlier months too. Growatt has no call limit, so there is no budget check; calls are spaced a second apart.
	 *
	 * @return array<int, string>
	 */
	private function backfillGrowatt(bool $daily, bool $dryRun): array
	{
		$label = self::VENDORS['growatt'];
		$sites = Db::all("SELECT id, vendor_site_id, name FROM monitor_sites WHERE vendor = 'growatt' ORDER BY id");
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
			$haveMonths = array_flip(array_column(Db::all('SELECT month FROM monitor_monthly WHERE site_id = ? AND wh > 0', [$site['id']]), 'month'));
			for ($m = $jan; $m < $thisMonth; $m = $m->modify('+1 month')) {
				// Month totals already stored are not fetched again.
				if (!isset($haveMonths[$m->format('Y-m')])) {
					$months[] = $m;
				}
				if ($daily) {
					for ($d = $m; $d < $m->modify('+1 month'); $d = $d->modify('+1 day')) {
						$days[] = $d;
					}
				}
			}
			// Last month's and this month's days always, so the rolling 30 days is whole.
			for ($d = $daily ? $thisMonth : $thisMonth->modify('-1 month'); $d->format('Y-m-d') <= $yesterday; $d = $d->modify('+1 day')) {
				if (!in_array($d, $days, false)) {
					$days[] = $d;
				}
			}
			// Days stored as zero are fetched again: when Growatt is busy it can answer a day with zeros.
			$have = array_flip(array_column(Db::all('SELECT date FROM monitor_daily WHERE site_id = ? AND date >= ? AND wh > 0', [$site['id'], $jan->format('Y-m-d')]), 'date'));
			$days = array_values(array_filter($days, static fn ($d) => !isset($have[$d->format('Y-m-d')])));
			$plan[] = [$site, $months, $days];
		}
		$estimate = array_sum(array_map(static fn ($p) => count($p[1]) + count($p[2]), $plan));
		$summary = count($sites) . ' sites, missing monthly totals and ' . ($daily ? 'every missing or zero day' : 'the missing or zero days of last month and this one') . ": about $estimate calls (no limit)";
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
					usleep(2_000_000);
				}
			}
			$ok = true;
			$message = 'Backfill done: ' . $summary;
			// Check: last month's days against Growatt's own total for that month, per site.
			$last = $thisMonth->modify('-1 month')->format('Y-m');
			$short = [];
			foreach ($plan as [$site]) {
				$month = (float) Db::value('SELECT wh FROM monitor_monthly WHERE site_id = ? AND month = ?', [$site['id'], $last]);
				$days = (float) Db::value('SELECT COALESCE(SUM(wh), 0) FROM monitor_daily WHERE site_id = ? AND date LIKE ?', [$site['id'], $last . '-%']);
				if ($month > 0 && $days < $month * 0.9) {
					$short[] = ($site['name'] ?: $site['vendor_site_id']) . ' ' . round($days / 1000) . ' of ' . round($month / 1000) . ' kWh';
				}
			}
			if ($short) {
				$message .= ". $last days short of the month total (run it again): " . implode(', ', $short);
			}
		} catch (\Throwable $e) {
			$message = 'Backfill stopped: ' . $e->getMessage();
		}
		Db::update('monitor_runs', $runId, ['finished_at' => now_utc(), 'calls' => $this->calls, 'ok' => $ok ? 1 : 0, 'message' => substr($message, 0, 500)]);
		return [self::stamp() . " $label: $message ({$this->calls} calls)"];
	}

	/**
	 * Enphase: exact daily totals per system for last month and this month (so the rolling 30 days is
	 * whole), or with $daily every day this year. One request per system per day, spaced a second apart.
	 * No monthly totals are needed because Enlighten gives each system's year total directly.
	 *
	 * @return array<int, string>
	 */
	private function backfillEnphase(bool $daily, bool $dryRun): array
	{
		$label = self::VENDORS['enphase'];
		$sites = Db::all("SELECT id, vendor_site_id, first_seen_at FROM monitor_sites WHERE vendor = 'enphase' ORDER BY id");
		if (!$sites) {
			return [self::stamp() . " $label: no systems stored yet; run monitor:poll --vendor=enphase --force first."];
		}
		$from = $daily ? date('Y-01-01') : date('Y-m-01', strtotime('first day of last month'));
		$yesterday = date('Y-m-d', strtotime('-1 day'));
		$plan = [];
		foreach ($sites as $site) {
			// Days the poll stored since the system was first seen hold its last running "today" reading,
			// so they are fetched again. Earlier days can only have come from a backfill, so they are kept
			// (a stopped backfill picks up where it left off).
			$seen = $site['first_seen_at'] ? date('Y-m-d', strtotime((string) $site['first_seen_at'])) : $yesterday;
			$have = array_flip(array_column(Db::all('SELECT date FROM monitor_daily WHERE site_id = ? AND date >= ? AND date < ?', [$site['id'], $from, $seen]), 'date'));
			$days = [];
			for ($t = strtotime($from); date('Y-m-d', $t) <= $yesterday; $t = strtotime('+1 day', $t)) {
				if (!isset($have[date('Y-m-d', $t)])) {
					$days[] = date('Y-m-d', $t);
				}
			}
			$plan[] = [$site, $days];
		}
		$estimate = array_sum(array_map(static fn ($p) => count($p[1]), $plan));
		$summary = count($sites) . " systems, days from $from: $estimate requests";
		if ($dryRun) {
			return [self::stamp() . " $label: would backfill $summary. Dry run: no requests made."];
		}
		$runId = Db::insert('monitor_runs', ['vendor' => 'enphase', 'started_at' => now_utc()]);
		$this->runId = $runId;
		$ok = false;
		try {
			foreach ($plan as [$site, $days]) {
				foreach ($days as $day) {
					$e = Enlighten::energy((string) $site['vendor_site_id'], $day, $day);
					$this->tick();
					if ($e['micros'] > 0) {
						self::setDaily((int) $site['id'], $day, $e['wh']);
					}
					usleep(1_000_000);
				}
			}
			$ok = true;
			$message = 'Backfill done: ' . $summary;
		} catch (\Throwable $e) {
			$message = 'Backfill stopped: ' . $e->getMessage();
		}
		Db::update('monitor_runs', $runId, ['finished_at' => now_utc(), 'calls' => $this->calls, 'ok' => $ok ? 1 : 0, 'message' => substr($message, 0, 500)]);
		return [self::stamp() . " $label: $message ({$this->calls} requests)"];
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
		$pace = self::sePace();
		if ($pace['pulls'] < 1) {
			return [false, $pace['remaining'] . ' credits left this cycle, a pull needs about ' . $pace['needed']];
		}
		$since = $last === null ? PHP_INT_MAX : self::weekdaysBetween($last, time());
		if ($since < $pace['gap']) {
			return [false, sprintf('pacing credits: %d left for %d weekdays, about %d pulls, so every %d weekdays (last pull %d weekdays ago)',
				$pace['remaining'], $pace['weekdays'], $pace['pulls'], (int) ceil($pace['gap']), $since)];
		}
		return [true, ''];
	}

	/**
	 * How often SolarEdge can be pulled for the rest of the cycle: remaining credits, cost of one pull,
	 * pulls they cover, weekdays left (today included) and the gap in weekdays between pulls (1 = every
	 * weekday). Recomputed on each check, so a backfill or a skipped day adjusts the pace.
	 *
	 * @return array{remaining:int, needed:int, pulls:int, weekdays:int, gap:float}
	 */
	public static function sePace(): array
	{
		[$remaining, $weekdays] = self::seBudget();
		$needed = self::seNeeded();
		$pulls = max(0, intdiv(max(0, $remaining), $needed));
		$gap = $pulls >= $weekdays ? 1.0 : ($pulls > 0 ? $weekdays / $pulls : INF);
		return ['remaining' => $remaining, 'needed' => $needed, 'pulls' => $pulls, 'weekdays' => $weekdays, 'gap' => $gap];
	}

	/** Weekdays after $from's date up to and including $to's date (Friday to Monday is 1). */
	private static function weekdaysBetween(int $from, int $to): int
	{
		$n = 0;
		for ($t = strtotime('+1 day', strtotime(date('Y-m-d', $from))); $t <= strtotime(date('Y-m-d', $to)); $t = strtotime('+1 day', $t)) {
			if ((int) date('N', $t) < 6) {
				$n++;
			}
		}
		return $n;
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
		// Once a day: yesterday's exact total per system (the systems table only has a running "today").
		$finals = 0;
		if (!self::lastOkOn('enphase', $today)) {
			$yesterday = date('Y-m-d', strtotime('-1 day'));
			foreach (Db::all("SELECT id, vendor_site_id FROM monitor_sites WHERE vendor = 'enphase'") as $row) {
				$e = Enlighten::energy((string) $row['vendor_site_id'], $yesterday, $yesterday);
				$this->tick();
				if ($e['micros'] > 0) {
					self::setDaily((int) $row['id'], $yesterday, $e['wh']);
					$finals++;
				}
			}
		}
		return count($rows) . ' systems' . ($finals ? ", yesterday's totals for $finals" : '');
	}

	// ---- SMA ---------------------------------------------------------------------------------

	/** SMA plant states (the portal's status codes) as stored: [state, text]. */
	private const SMA_STATES = [307 => ['ok', 'OK'], 455 => ['alert', 'Warning'], 35 => ['alert', 'Error'], 303 => ['offline', 'Off']];

	private function sma(): string
	{
		$r = Sma::plants();
		$this->tick();
		$list = $r['json']['items'] ?? null;
		if ($r['status'] !== 200 || !is_array($list)) {
			throw new \RuntimeException(trim(($r['note'] ?? '') . ' Status list: ' . ($r['error'] ?? 'HTTP ' . $r['status'])));
		}
		$today = date('Y-m-d');
		$daily = $this->forced || !self::lastOkOn('sma', $today);
		$ids = [];
		$alerts = 0;
		foreach ($list as $p) {
			$pid = (string) ($p['plantId'] ?? '');
			if (!preg_match('/^\d{1,12}$/', $pid)) {
				continue;
			}
			$code = (int) ($p['state'] ?? 0);
			[$state, $text] = self::SMA_STATES[$code] ?? ['unknown', 'State ' . $code];
			$fields = [
				'name' => (string) ($p['name'] ?? ''),
				'state' => $state,
				'status_text' => $text,
				'alert_count' => $state === 'alert' ? 1 : 0,
				'alert_impact' => $state === 'alert' ? ($code === 35 ? 2 : 1) : null,
				'today_date' => $today,
				'today_wh' => self::num($p['yieldToday'] ?? null),
			];
			if (is_string($p['city'] ?? null) && trim($p['city']) !== '') {
				$fields['site_city'] = trim($p['city']); // the status list gives the plant's city only
			}
			if ($daily) {
				// Peak power (kWp) as entered for the plant; else the inverters' maximum DC input.
				$detail = Sma::plant($pid);
				$this->tick();
				$peak = self::num($detail['json']['peakPower'] ?? null) ?: self::num($p['dcPowerInputMax'] ?? null);
				if ($peak) {
					$fields['capacity_kw'] = $peak / 1000;
				}
			}
			$siteId = self::upsertSite('sma', $pid, $fields);
			$ids[$pid] = $siteId;
			$alerts += $state === 'alert' ? 1 : 0;
			if ($fields['today_wh'] !== null) {
				self::setDaily($siteId, $today, $fields['today_wh']);
			}
		}
		if (!$ids) {
			return '0 plants';
		}
		$tz = new \DateTimeZone(date_default_timezone_get());
		$midnight = new \DateTimeImmutable('today', $tz);
		// This year's total from SMA's monthly values (the current month runs through now).
		$months = Sma::energy(array_keys($ids), 'OneMonth', $midnight->setDate((int) $midnight->format('Y'), 1, 1), $midnight->modify('first day of next month'));
		$this->tick();
		if ($months['result']['status'] !== 200) {
			throw new \RuntimeException('Monthly energy: ' . ($months['result']['error'] ?? 'HTTP ' . $months['result']['status']));
		}
		$year = date('Y');
		foreach ($ids as $pid => $siteId) {
			$sum = 0.0;
			foreach ($months['values'][$pid] ?? [] as $month => $wh) {
				if (str_starts_with($month, $year . '-')) {
					$sum += $wh;
				}
			}
			Db::run('UPDATE monitor_sites SET year_wh = ? WHERE id = ?', [$sum, $siteId]);
		}
		$finals = 0;
		if ($daily) {
			$finals = $this->smaDays($ids, $midnight->modify('-35 days'), $midnight);
		}
		return count($ids) . " plants, $alerts alerts" . ($finals ? ", final daily values for $finals days" : '');
	}

	/**
	 * Store SMA's daily values for whole days from $from up to $to (exclusive) for the given plants
	 * (plant id => site id), one request. @return int days stored
	 */
	private function smaDays(array $ids, \DateTimeImmutable $from, \DateTimeImmutable $to): int
	{
		$days = Sma::energy(array_map('strval', array_keys($ids)), 'OneDay', $from, $to);
		$this->tick();
		if ($days['result']['status'] !== 200) {
			throw new \RuntimeException('Daily energy: ' . ($days['result']['error'] ?? 'HTTP ' . $days['result']['status']));
		}
		$n = 0;
		$today = date('Y-m-d');
		foreach ($ids as $pid => $siteId) {
			foreach ($days['values'][(string) $pid] ?? [] as $date => $wh) {
				if ($date < $today) {
					self::setDaily((int) $siteId, $date, $wh);
					$n++;
				}
			}
		}
		return $n;
	}

	/**
	 * SMA: every day this year for every plant in one request (SMA's own year total is read on each
	 * pull, so no monthly values are needed).
	 *
	 * @return array<int, string>
	 */
	private function backfillSma(bool $dryRun): array
	{
		$label = self::VENDORS['sma'];
		$ids = [];
		foreach (Db::all("SELECT id, vendor_site_id FROM monitor_sites WHERE vendor = 'sma' ORDER BY id") as $row) {
			$ids[(string) $row['vendor_site_id']] = (int) $row['id'];
		}
		if (!$ids) {
			return [self::stamp() . " $label: no plants stored yet; run monitor:poll --vendor=sma --force first."];
		}
		if ($dryRun) {
			return [self::stamp() . " $label: would fetch every day this year for " . count($ids) . ' plants (1 request). Dry run: no requests made.'];
		}
		$runId = Db::insert('monitor_runs', ['vendor' => 'sma', 'started_at' => now_utc()]);
		$this->runId = $runId;
		$ok = false;
		try {
			$tz = new \DateTimeZone(date_default_timezone_get());
			$midnight = new \DateTimeImmutable('today', $tz);
			$n = $this->smaDays($ids, $midnight->setDate((int) $midnight->format('Y'), 1, 1), $midnight);
			$ok = true;
			$message = 'Backfill done: ' . count($ids) . " plants, $n days";
		} catch (\Throwable $e) {
			$message = 'Backfill stopped: ' . $e->getMessage();
		}
		Db::update('monitor_runs', $runId, ['finished_at' => now_utc(), 'calls' => $this->calls, 'ok' => $ok ? 1 : 0, 'message' => substr($message, 0, 500)]);
		return [self::stamp() . " $label: $message ({$this->calls} requests)"];
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
		$firstToday = $this->forced || self::lastOkOn('apsystems', date('Y-m-d')) === false;
		$known = Db::all("SELECT id, vendor_site_id FROM monitor_sites WHERE vendor = 'apsystems'");
		if ($firstToday || !$known) {
			$r = APsystems::request('POST', '/systems', [], ['page' => 1, 'size' => 50]);
			$this->tick();
			$list = self::apData($r)['systems'] ?? [];
			foreach ($list as $s) {
				$light = (int) ($s['light'] ?? 0);
				self::upsertSite('apsystems', (string) $s['sid'], [
					'capacity_kw' => self::num($s['capacity'] ?? null),
					// Yellow is common and usually nothing (Greg, 2026-10-11): only red or grey count. A yellow
					// system that stops producing is still caught by the page's no-production check.
					'state' => match ($light) { 1, 2 => 'ok', 3 => 'alert', 4 => 'offline', default => 'unknown' },
					'status_text' => match ($light) { 1 => 'Green', 2 => 'Yellow', 3 => 'Red', 4 => 'Grey (offline)', default => 'Unknown' },
					'alert_count' => $light === 3 ? 1 : 0,
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
			] + self::seLocation($s['location'] ?? null));
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

	/**
	 * The site address from a SolarEdge site's location, as monitor_sites columns. Only the keys found
	 * are returned, so a reply without a location never blanks an address already stored. Accepts the
	 * V1 key names (address, address2, city, state or stateCode, zip) and common alternatives, since the
	 * V2 reference does not spell the object out.
	 *
	 * @return array<string,string>
	 */
	private static function seLocation(mixed $loc): array
	{
		if (!is_array($loc)) {
			return [];
		}
		$pick = static function (array $keys) use ($loc): ?string {
			foreach ($keys as $k) {
				if (isset($loc[$k]) && is_scalar($loc[$k]) && ($v = trim((string) $loc[$k])) !== '') {
					return $v;
				}
			}
			return null;
		};
		$out = [];
		$street = $pick(['address', 'address1', 'addressLine1', 'street', 'streetAddress']);
		if ($street !== null) {
			$two = $pick(['address2', 'addressLine2']);
			$out['site_street'] = $two !== null ? $street . ', ' . $two : $street;
		}
		if (($city = $pick(['city'])) !== null) {
			$out['site_city'] = $city;
		}
		// A two-letter code when there is one; full state names stay as given.
		$state = $pick(['stateCode', 'state']);
		if ($state !== null) {
			$out['site_state'] = strlen($state) === 2 ? strtoupper($state) : (self::US_STATES[strtolower($state)] ?? $state);
		}
		if (($zip = $pick(['zip', 'zipCode', 'postalCode', 'postCode'])) !== null) {
			$out['site_zip'] = $zip;
		}
		return $out;
	}

	/** State names the vendors spell out, for the ones Trifecta works in. */
	private const US_STATES = ['pennsylvania' => 'PA', 'maryland' => 'MD', 'delaware' => 'DE', 'new jersey' => 'NJ', 'new york' => 'NY', 'virginia' => 'VA', 'ohio' => 'OH', 'west virginia' => 'WV'];

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
