<?php
declare(strict_types=1);

namespace App;

/**
 * Draws against commission. A salesperson on a draw is paid a set amount every payroll instead of
 * each commission as it comes due. The draws cover their commission as jobs come due; a true-up
 * applies the draw money to the jobs that are due, oldest first, and shows any commission owed
 * beyond the draws.
 *
 * Advance = draws paid - commission covered by draws. A positive advance has been paid ahead of
 * what has come due; it carries forward until their jobs cover it. Pipeline is their balance on sold
 * jobs that are not due yet. The advance is only safe while the pipeline covers it, so the summary
 * flags it when it passes the warning share of the pipeline, and again when it passes all of it.
 */
final class Draws
{
	public const DUE_AT = ['closeout' => 'Closeout (PTO)', 'final_payment' => 'Final payment received (every payment step done)'];

	/** When commission comes due: 'closeout' (the default) or 'final_payment'. */
	public static function dueAt(): string
	{
		$v = Presales::setting('commission_due_at');
		return isset(self::DUE_AT[$v]) ? $v : 'closeout';
	}

	/** The advance is flagged when it passes this share of the pipeline (percent). */
	public static function warnPct(): float
	{
		$v = Presales::setting('draw_warn_pct');
		return is_numeric($v) && (float) $v > 0 ? (float) $v : 50.0;
	}

	public static function saveSetting(string $key, string $value): void
	{
		Db::run('INSERT OR REPLACE INTO presales_settings (key, value, updated_at) VALUES (?, ?, ?)', [$key, $value, now_utc()]);
		Presales::forgetSettings();
	}

	/** Writes each schedule's draw lines for payroll dates up to today (or its end date). */
	public static function catchUp(?int $userId = null): void
	{
		$today = date('Y-m-d');
		$rows = $userId ? Db::all('SELECT * FROM commission_draw_schedules WHERE user_id = ?', [$userId])
			: Db::all('SELECT * FROM commission_draw_schedules');
		foreach ($rows as $s) {
			$every = max(1, (int) $s['every_days']);
			$last = min($today, $s['end_date'] ?: $today);
			$d = $s['generated_through'] ? self::addDays($s['generated_through'], $every) : $s['start_date'];
			if ($d > $last) {
				continue;
			}
			$through = null;
			Db::transaction(static function () use ($s, $every, $last, &$d, &$through) {
				while ($d <= $last) {
					Db::run('INSERT OR IGNORE INTO commission_draws (user_id, schedule_id, pay_date, amount, kind) VALUES (?, ?, ?, ?, ?)',
						[(int) $s['user_id'], (int) $s['id'], $d, (float) $s['amount'], 'draw']);
					$through = $d;
					$d = self::addDays($d, $every);
				}
				Db::run('UPDATE commission_draw_schedules SET generated_through = ? WHERE id = ?', [$through, (int) $s['id']]);
			});
		}
	}

	public static function addDays(string $date, int $days): string
	{
		return (new \DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
	}

	/** The next payroll date a schedule pays on, after today; null once it has ended. */
	public static function nextDate(array $s): ?string
	{
		$every = max(1, (int) $s['every_days']);
		$today = date('Y-m-d');
		$d = $s['start_date'];
		if ($d <= $today) {
			$n = intdiv((int) ((strtotime($today) - strtotime($d)) / 86400), $every) + 1;
			$d = self::addDays($d, $n * $every);
		}
		return $s['end_date'] && $d > $s['end_date'] ? null : $d;
	}

	/** People with a draw schedule or draw lines. */
	public static function people(): array
	{
		return Db::all('SELECT id, name FROM users WHERE id IN (SELECT user_id FROM commission_draw_schedules UNION SELECT user_id FROM commission_draws) ORDER BY name');
	}

	public static function onDraw(int $userId): bool
	{
		return (bool) Db::value('SELECT 1 FROM commission_draw_schedules WHERE user_id = ? UNION SELECT 1 FROM commission_draws WHERE user_id = ? LIMIT 1', [$userId, $userId]);
	}

	public static function drawn(int $userId): float
	{
		return round((float) Db::value('SELECT COALESCE(SUM(amount), 0) FROM commission_draws WHERE user_id = ?', [$userId]), 2);
	}

	public static function covered(int $userId): float
	{
		return round((float) Db::value("SELECT COALESCE(SUM(amount), 0) FROM commission_entries WHERE user_id = ? AND kind = 'payment' AND source = 'draw'", [$userId]), 2);
	}

	public static function advance(int $userId): float
	{
		return round(self::drawn($userId) - self::covered($userId), 2);
	}

	/**
	 * One person's active jobs with a balance: the record, their balance, whether it is due, and its state.
	 * @return list<array{c:array, balance:float, due:bool, state:string}>
	 */
	public static function jobs(int $userId): array
	{
		$records = Db::all("SELECT c.*, p.project_number, p.name AS project_name FROM commissions c LEFT JOIN projects p ON p.id = c.project_id
			WHERE c.status = 'active' AND ? IN (c.salesperson_id, c.handoff_user_id)
			ORDER BY c.signed_on IS NULL, c.signed_on, c.id", [$userId]);
		$states = Commissions::dueStates($records);
		$out = [];
		foreach ($records as $c) {
			$p = Commissions::payees($c)[$userId] ?? null;
			if (!$p || $p['balance'] <= 0.005) {
				continue;
			}
			$st = $states[(int) $c['id']];
			$out[] = ['c' => $c, 'balance' => $p['balance'], 'due' => $st['due'],
				'state' => Commissions::status($c, $p['due'], $p['paid'], $st['due'], $st['waiting'])];
		}
		return $out;
	}

	/**
	 * Draws, advance, what is owed now beyond the draws, pipeline and how much of it the advance uses.
	 * flag: none (no pipeline and no advance), ok, warn (past the warning share), over (past the pipeline).
	 */
	public static function summary(int $userId): array
	{
		$jobs = self::jobs($userId);
		$owed = $pipeline = 0.0;
		foreach ($jobs as $j) {
			$j['due'] ? $owed += $j['balance'] : $pipeline += $j['balance'];
		}
		$advance = self::advance($userId);
		// Money already advanced pays what is due first; what is left of it rides on the pipeline
		$ahead = max(0.0, $advance - $owed);
		$coverage = $pipeline > 0.005 ? $ahead / $pipeline : null;
		$flag = match (true) {
			$ahead <= 0.005 => 'ok',
			$coverage === null || $coverage > 1 => 'over',
			$coverage * 100 > self::warnPct() => 'warn',
			default => 'ok',
		};
		$schedule = Db::one("SELECT * FROM commission_draw_schedules WHERE user_id = ? AND (end_date IS NULL OR end_date >= ?) ORDER BY start_date DESC, id DESC LIMIT 1", [$userId, date('Y-m-d')]);
		return [
			'drawn' => self::drawn($userId), 'covered' => self::covered($userId), 'advance' => $advance,
			'owed' => round($owed, 2), 'pipeline' => round($pipeline, 2), 'ahead' => round($ahead, 2),
			// Commission due beyond what the draws can cover: the catch-up to pay at a true-up
			// (a negative advance, more covered than drawn, is owed to them too)
			'behind' => round(max(0.0, $owed - $advance), 2),
			// Due commission the advance can cover: what the next true-up marks paid
			'ready' => round(min($owed, max(0.0, $advance)), 2),
			'coverage' => $coverage, 'flag' => $flag, 'schedule' => $schedule,
			'next' => $schedule ? self::nextDate($schedule) : null,
			'last' => Db::value('SELECT MAX(pay_date) FROM commission_draws WHERE user_id = ?', [$userId]),
		];
	}

	/**
	 * The one-line standing for a summary: [chip class, text].
	 */
	public static function chip(array $s): array
	{
		$pct = $s['coverage'] === null ? '' : ' (' . round($s['coverage'] * 100) . '% of pipeline)';
		return match (true) {
			$s['behind'] > 0.005 => ['chip-orange', 'Behind ' . Presales::money($s['behind'])],
			$s['ahead'] <= 0.005 => ['chip-green', 'Even'],
			$s['flag'] === 'over' => ['chip-red', 'Ahead ' . Presales::money($s['ahead']) . ($s['coverage'] === null ? ', no pipeline' : ', past pipeline' . $pct)],
			$s['flag'] === 'warn' => ['chip-orange', 'Ahead ' . Presales::money($s['ahead']) . $pct],
			default => ['chip-green', 'Ahead ' . Presales::money($s['ahead']) . $pct],
		};
	}

	/**
	 * What a true-up would do: the advance applied to the due jobs, oldest first, and what is still
	 * owed after it (the catch-up).
	 * @return array{apply:list<array{c:array, amount:float}>, cash:list<array{c:array, amount:float}>, rows:list<array{c:array, draw:float, cash:float}>, left:float}
	 */
	public static function trueUpPlan(int $userId): array
	{
		$avail = max(0.0, self::advance($userId));
		$apply = $cash = [];
		foreach (self::jobs($userId) as $j) {
			if (!$j['due']) {
				continue;
			}
			$from = round(min($j['balance'], $avail), 2);
			if ($from > 0.005) {
				$apply[] = ['c' => $j['c'], 'amount' => $from];
				$avail -= $from;
			}
			$rest = round($j['balance'] - $from, 2);
			if ($rest > 0.005) {
				$cash[] = ['c' => $j['c'], 'amount' => $rest];
			}
		}
		$rows = [];
		foreach ($apply as $r) {
			$rows[(int) $r['c']['id']] = ['c' => $r['c'], 'draw' => $r['amount'], 'cash' => 0.0];
		}
		foreach ($cash as $r) {
			$rows[(int) $r['c']['id']] ??= ['c' => $r['c'], 'draw' => 0.0, 'cash' => 0.0];
			$rows[(int) $r['c']['id']]['cash'] = $r['amount'];
		}
		return ['apply' => $apply, 'cash' => $cash, 'rows' => array_values($rows), 'left' => round($avail, 2)];
	}

	/**
	 * Apply the advance to the due jobs; with $payCash, also record what is still owed as paid in
	 * cash on $date (the catch-up). Jobs paid off close their project's pay-commission step.
	 * @return array{applied:float, cash:float, jobs:int}
	 */
	public static function trueUp(int $userId, string $date, bool $payCash): array
	{
		self::catchUp($userId);
		$plan = self::trueUpPlan($userId);
		$name = (string) Db::value('SELECT name FROM users WHERE id = ?', [$userId]);
		$done = ['applied' => 0.0, 'cash' => 0.0, 'jobs' => 0];
		Db::transaction(static function () use ($plan, $userId, $date, $payCash, $name, &$done) {
			$touched = [];
			$rows = array_map(static fn ($r) => $r + ['source' => 'draw'], $plan['apply']);
			if ($payCash) {
				$rows = array_merge($rows, array_map(static fn ($r) => $r + ['source' => null], $plan['cash']));
			}
			foreach ($rows as $r) {
				$cid = (int) $r['c']['id'];
				Db::insert('commission_entries', ['commission_id' => $cid, 'kind' => 'payment', 'user_id' => $userId, 'amount' => $r['amount'],
					'entry_date' => $date, 'source' => $r['source'], 'note' => $r['source'] ? 'Covered by draws (true-up)' : 'Catch-up beyond draws (true-up)',
					'created_by' => Auth::id()]);
				Activity::event('commission', $cid, ($r['source'] ? 'Covered by draws: ' : 'Catch-up paid: ') . Presales::money($r['amount']) . ' for ' . $name . ' on ' . fmt_date($date) . ' (true-up)');
				$done[$r['source'] ? 'applied' : 'cash'] += $r['amount'];
				$touched[$cid] = $r['c'];
			}
			foreach ($touched as $cid => $c) {
				self::closePayStepIfPaid($cid, $date);
			}
			$done['jobs'] = count($touched);
			if ($touched) {
				$parts = array_filter([
					$done['applied'] > 0.005 ? Presales::money($done['applied']) . ' covered by draws' : null,
					$done['cash'] > 0.005 ? Presales::money($done['cash']) . ' catch-up paid' : null,
				]);
				Activity::event('commission_draws', $userId, 'True-up ' . fmt_date($date) . ': ' . implode(', ', $parts) . ' on ' . $done['jobs'] . ' job' . ($done['jobs'] === 1 ? '' : 's'));
			}
		});
		return $done;
	}

	/**
	 * Pay every balance on a job as of $date: for a person on a draw, from their advance first and the
	 * rest in cash; for anyone else, in cash. Returns "Name $amount" strings for the log.
	 */
	public static function payBalances(array $c, string $date, string $note, ?int $taskId = null): array
	{
		$paid = [];
		foreach (Commissions::payees($c) as $p) {
			if ($p['balance'] <= 0.005) {
				continue;
			}
			$uid = (int) $p['user_id'];
			$from = self::onDraw($uid) ? round(min($p['balance'], max(0.0, self::advance($uid))), 2) : 0.0;
			$cash = round($p['balance'] - $from, 2);
			foreach ([['draw', $from], [null, $cash]] as [$source, $amount]) {
				if ($amount > 0.005) {
					Db::insert('commission_entries', ['commission_id' => (int) $c['id'], 'kind' => 'payment', 'user_id' => $uid, 'amount' => $amount,
						'entry_date' => $date, 'note' => $note . ($source ? ' (covered by draws)' : ''), 'source' => $source, 'task_id' => $taskId,
						'created_by' => Auth::id()]);
				}
			}
			$paid[] = $p['name'] . ' ' . Presales::money($p['balance']) . ($from > 0.005 ? ' (' . Presales::money($from) . ' from draws)' : '');
		}
		return $paid;
	}

	/** Close the project's pay-commission step when nothing is owed on the job any more. */
	private static function closePayStepIfPaid(int $cid, string $date): void
	{
		$c = Commissions::find($cid);
		if (!$c || !$c['project_id']) {
			return;
		}
		foreach (Commissions::payees($c) as $p) {
			if ($p['balance'] > 0.005) {
				return;
			}
		}
		$step = Db::one("SELECT * FROM project_tasks WHERE project_id = ? AND action = 'pay_commission' AND done_date IS NULL ORDER BY id LIMIT 1", [(int) $c['project_id']]);
		if ($step) {
			Db::update('project_tasks', (int) $step['id'], ['done_date' => $date, 'needed' => 1, 'updated_at' => now_utc()]);
			Activity::event('project', (int) $c['project_id'], '"' . $step['name'] . '" done ' . fmt_date($date) . ' (commission paid at a true-up)');
		}
	}
}
