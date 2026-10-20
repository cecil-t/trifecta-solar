<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Commissions;
use App\Db;
use App\Draws;
use App\Presales;
use App\Projects;
use App\View;

/**
 * One salesperson's draw against commission: the schedule, the draws paid, the advance against their
 * pipeline, and the true-up. Admins manage; a salesperson can view their own.
 */
final class DrawController
{
	/** The person picker on the commissions page: go to that person's draw page. */
	public function pick(): void
	{
		$this->requireManage();
		$uid = (int) ($_GET['user'] ?? 0);
		redirect($uid ? '/commissions/draws/' . $uid : '/commissions');
	}

	public function show(int $uid): void
	{
		$u = $this->user($uid);
		$admin = Commissions::canManage();
		Draws::catchUp($uid);
		$jobs = Draws::jobs($uid);
		View::render('commissions/draws', [
			'title' => 'Draw: ' . $u['name'], 'u' => $u, 'admin' => $admin,
			'sum' => Draws::summary($uid), 'plan' => Draws::trueUpPlan($uid), 'jobs' => $jobs,
			'schedules' => Db::all('SELECT * FROM commission_draw_schedules WHERE user_id = ? ORDER BY start_date DESC, id DESC', [$uid]),
			'lines' => Db::all('SELECT * FROM commission_draws WHERE user_id = ? ORDER BY pay_date DESC, id DESC', [$uid]),
			'warnPct' => Draws::warnPct(), 'dueLabel' => Commissions::dueLabel(), 'frozen' => Draws::frozen($uid),
			'activity' => Activity::feed('commission_draws', $uid),
		]);
	}

	public function addSchedule(int $uid): void
	{
		$this->requireManage();
		$u = $this->user($uid);
		$amount = $this->amount('amount', 'Draw amount');
		$start = $this->date('start_date', 'First payroll date');
		$every = (int) ($_POST['every_days'] ?? 14);
		if ($every < 1 || $every > 31) {
			$this->fail($uid, 'Pay every 1 to 31 days (14 is every two weeks).');
		}
		$note = trim((string) ($_POST['note'] ?? '')) ?: null;
		$sid = Db::insert('commission_draw_schedules', ['user_id' => $uid, 'amount' => $amount, 'start_date' => $start, 'every_days' => $every,
			'note' => $note, 'created_by' => Auth::id()]);
		Draws::catchUp($uid);
		$n = (int) Db::value('SELECT COUNT(*) FROM commission_draws WHERE schedule_id = ?', [$sid]);
		Activity::event('commission_draws', $uid, 'Draw schedule set up: ' . Presales::money($amount) . ' every ' . $every . ' days from ' . fmt_date($start)
			. ($n ? ' (' . $n . ' past payroll' . ($n === 1 ? '' : 's') . ' written)' : ''));
		flash('success', 'Draw schedule set up for ' . $u['name'] . ($n ? '; ' . $n . ' draw' . ($n === 1 ? '' : 's') . ' up to today written.' : '.'));
		redirect('/commissions/draws/' . $uid);
	}

	/**
	 * A new amount from a payroll date on: the schedule stops the day before and a new one starts
	 * there; draws already written from that date on take the new amount unless edited by hand.
	 */
	public function changeSchedule(int $uid, int $sid): void
	{
		$this->requireManage();
		$s = $this->schedule($uid, $sid);
		$amount = $this->amount('amount', 'New draw amount');
		$from = $this->date('from_date', 'Starting payroll date');
		$this->afterFrozen($uid, $from);
		if ($from <= $s['start_date']) {
			// Change from the very start: the schedule itself takes the new amount
			Db::transaction(static function () use ($s, $sid, $amount) {
				Db::run('UPDATE commission_draw_schedules SET amount = ?, updated_at = ? WHERE id = ?', [$amount, now_utc(), $sid]);
				Db::run('UPDATE commission_draws SET amount = ? WHERE schedule_id = ? AND edited = 0', [$amount, $sid]);
			});
		} else {
			Db::transaction(static function () use ($s, $sid, $uid, $amount, $from) {
				$newId = Db::insert('commission_draw_schedules', ['user_id' => $uid, 'amount' => $amount, 'start_date' => $from,
					'every_days' => (int) $s['every_days'], 'end_date' => $s['end_date'], 'note' => $s['note'], 'created_by' => Auth::id()]);
				$moved = Db::value('SELECT MAX(pay_date) FROM commission_draws WHERE schedule_id = ? AND pay_date >= ?', [$sid, $from]);
				Db::run('UPDATE commission_draws SET schedule_id = ?, amount = CASE WHEN edited = 1 THEN amount ELSE ? END WHERE schedule_id = ? AND pay_date >= ?',
					[$newId, $amount, $sid, $from]);
				Db::run('UPDATE commission_draw_schedules SET generated_through = ? WHERE id = ?', [$moved, $newId]);
				$end = Draws::addDays($from, -1);
				Db::run('UPDATE commission_draw_schedules SET end_date = ?, generated_through = CASE WHEN generated_through > ? THEN ? ELSE generated_through END, updated_at = ? WHERE id = ?',
					[$end, $end, $end, now_utc(), $sid]);
			});
		}
		Draws::catchUp($uid);
		Activity::event('commission_draws', $uid, 'Draw changed from ' . Presales::money((float) $s['amount']) . ' to ' . Presales::money($amount) . ' from ' . fmt_date(max($from, $s['start_date'])) . ' on');
		flash('success', 'Draw changed to ' . Presales::money($amount) . '.');
		redirect('/commissions/draws/' . $uid);
	}

	/** Draws stop after a payroll date (the last one paid); later draws not edited by hand are removed. */
	public function stopSchedule(int $uid, int $sid): void
	{
		$this->requireManage();
		$s = $this->schedule($uid, $sid);
		$last = $this->date('end_date', 'Last payroll date');
		if ($last < $s['start_date']) {
			$this->fail($uid, 'The last payroll can\'t be before the first one. To remove the schedule, delete it.');
		}
		$through = Draws::frozen($uid)['through'];
		if ($through !== null && $last < $through) {
			$this->fail($uid, 'Draws through ' . fmt_date($through) . ' are already covered by commission, so the draws can\'t stop before then.');
		}
		Db::transaction(static function () use ($sid, $last) {
			Db::run('UPDATE commission_draw_schedules SET end_date = ?, updated_at = ? WHERE id = ?', [$last, now_utc(), $sid]);
			Db::run('DELETE FROM commission_draws WHERE schedule_id = ? AND pay_date > ? AND edited = 0', [$sid, $last]);
		});
		Activity::event('commission_draws', $uid, 'Draw of ' . Presales::money((float) $s['amount']) . ' stops after ' . fmt_date($last));
		flash('success', 'Draws stop after ' . fmt_date($last) . '.');
		redirect('/commissions/draws/' . $uid);
	}

	/** Remove a schedule and every draw it wrote. */
	public function deleteSchedule(int $uid, int $sid): void
	{
		$this->requireManage();
		$s = $this->schedule($uid, $sid);
		$frozen = Draws::frozen($uid)['ids'];
		foreach (Db::all('SELECT id FROM commission_draws WHERE schedule_id = ?', [$sid]) as $r) {
			if (isset($frozen[(int) $r['id']])) {
				$this->fail($uid, 'Some of this schedule\'s draws are already covered by commission. Stop the schedule instead.');
			}
		}
		$n = 0;
		Db::transaction(static function () use ($sid, &$n) {
			$n = (int) Db::value('SELECT COUNT(*) FROM commission_draws WHERE schedule_id = ?', [$sid]);
			Db::run('DELETE FROM commission_draws WHERE schedule_id = ?', [$sid]);
			Db::run('DELETE FROM commission_draw_schedules WHERE id = ?', [$sid]);
		});
		Activity::event('commission_draws', $uid, 'Draw schedule of ' . Presales::money((float) $s['amount']) . ' from ' . fmt_date($s['start_date']) . ' deleted with its ' . $n . ' draw' . ($n === 1 ? '' : 's'));
		flash('success', 'Schedule deleted.');
		redirect('/commissions/draws/' . $uid);
	}

	/** A draw outside the schedule, or the opening balance. */
	public function addLine(int $uid): void
	{
		$this->requireManage();
		$this->user($uid);
		$kind = ($_POST['kind'] ?? '') === 'opening' ? 'opening' : 'draw';
		$amount = $this->amount('amount', $kind === 'opening' ? 'Opening balance' : 'Draw amount', $kind === 'opening');
		$date = $this->date('pay_date', 'Date');
		$note = trim((string) ($_POST['note'] ?? '')) ?: null;
		$this->afterFrozen($uid, $date);
		if ($kind === 'opening' && Db::value("SELECT 1 FROM commission_draws WHERE user_id = ? AND kind = 'opening'", [$uid])) {
			$this->fail($uid, 'There is already an opening balance. Change that one instead.');
		}
		Db::insert('commission_draws', ['user_id' => $uid, 'pay_date' => $date, 'amount' => $amount, 'kind' => $kind, 'edited' => 1,
			'note' => $note, 'created_by' => Auth::id()]);
		Activity::event('commission_draws', $uid, ($kind === 'opening' ? 'Opening balance ' : 'Draw ') . Presales::money($amount) . ' as of ' . fmt_date($date) . ($note ? ': ' . $note : ''));
		flash('success', $kind === 'opening' ? 'Opening balance set.' : 'Draw added.');
		redirect('/commissions/draws/' . $uid);
	}

	public function updateLine(int $uid, int $lid): void
	{
		$this->requireManage();
		$l = $this->line($uid, $lid);
		$this->notFrozen($uid, $l);
		$amount = $this->amount('amount', 'Amount', $l['kind'] === 'opening');
		$date = $this->date('pay_date', 'Date');
		$note = trim((string) ($_POST['note'] ?? '')) ?: null;
		$this->afterFrozen($uid, $date);
		if ($l['schedule_id'] && $date !== $l['pay_date'] && Db::value('SELECT 1 FROM commission_draws WHERE schedule_id = ? AND pay_date = ? AND id <> ?', [(int) $l['schedule_id'], $date, $lid])) {
			$this->fail($uid, 'That schedule already has a draw on ' . fmt_date($date) . '.');
		}
		Db::run('UPDATE commission_draws SET amount = ?, pay_date = ?, note = ?, edited = 1 WHERE id = ?', [$amount, $date, $note, $lid]);
		$what = $l['kind'] === 'opening' ? 'Opening balance' : 'Draw';
		$changes = [];
		if (abs((float) $l['amount'] - $amount) > 0.005) {
			$changes[] = Presales::money((float) $l['amount']) . ' to ' . Presales::money($amount);
		}
		if ($date !== $l['pay_date']) {
			$changes[] = 'date ' . fmt_date($l['pay_date']) . ' to ' . fmt_date($date);
		}
		if ((string) $l['note'] !== (string) $note) {
			$changes[] = 'note: ' . ($note ?? '(none)');
		}
		if ($changes) {
			Activity::event('commission_draws', $uid, $what . ' of ' . fmt_date($l['pay_date']) . ' changed: ' . implode(', ', $changes));
		}
		flash('success', $changes ? 'Saved.' : 'Nothing changed.');
		redirect('/commissions/draws/' . $uid);
	}

	public function deleteLine(int $uid, int $lid): void
	{
		$this->requireManage();
		$l = $this->line($uid, $lid);
		$this->notFrozen($uid, $l);
		Db::run('DELETE FROM commission_draws WHERE id = ?', [$lid]);
		Activity::event('commission_draws', $uid, ($l['kind'] === 'opening' ? 'Opening balance ' : 'Draw ') . Presales::money((float) $l['amount']) . ' of ' . fmt_date($l['pay_date']) . ' removed');
		flash('success', 'Removed.');
		redirect('/commissions/draws/' . $uid);
	}

	public function trueUp(int $uid): void
	{
		$this->requireManage();
		$u = $this->user($uid);
		$date = $this->date('entry_date', 'True-up date');
		$done = Draws::trueUp($uid, $date, !empty($_POST['pay_cash']));
		if (!$done['jobs']) {
			flash('success', 'Nothing to true up: no due commission for ' . $u['name'] . '.');
		} else {
			$parts = array_filter([
				$done['applied'] > 0.005 ? Presales::money($done['applied']) . ' covered by draws' : null,
				$done['cash'] > 0.005 ? Presales::money($done['cash']) . ' catch-up recorded as paid' : null,
			]);
			flash('success', 'Trued up ' . $done['jobs'] . ' job' . ($done['jobs'] === 1 ? '' : 's') . ': ' . implode(', ', $parts) . '.');
		}
		redirect('/commissions/draws/' . $uid);
	}

	/** A draw already covered by commission is frozen: no edit, no removal. */
	private function notFrozen(int $uid, array $l): void
	{
		if (isset(Draws::frozen($uid)['ids'][(int) $l['id']])) {
			$this->fail($uid, 'The draw of ' . fmt_date($l['pay_date']) . ' is already covered by commission, so it can\'t be changed.');
		}
	}

	/** New or changed draws must come after the last draw covered by commission (draws are used oldest first). */
	private function afterFrozen(int $uid, string $date): void
	{
		$through = Draws::frozen($uid)['through'];
		if ($through !== null && $date <= $through) {
			$this->fail($uid, 'Draws through ' . fmt_date($through) . ' are already covered by commission. Use a date after that.');
		}
	}

	private function amount(string $key, string $label, bool $allowZero = false): float
	{
		$raw = trim((string) ($_POST[$key] ?? ''));
		$v = $raw === '' ? null : Projects::parseNumber($raw);
		if ($v === null || !is_numeric(preg_replace('/[^0-9.\-]/', '', $raw)) || $v < 0 || (!$allowZero && $v < 0.005)) {
			$this->fail((int) ($this->uidFromPath()), $label . ' must be a dollar amount' . ($allowZero ? ' (0 or more).' : ' above zero.'));
		}
		return round($v, 2);
	}

	private function date(string $key, string $label): string
	{
		$v = Projects::parseDate((string) ($_POST[$key] ?? ''));
		if ($v === null) {
			$this->fail($this->uidFromPath(), $label . ': use a date like 10/23/2026.');
		}
		return $v;
	}

	private function uidFromPath(): int
	{
		return preg_match('#^/commissions/draws/(\d+)#', (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), $m) ? (int) $m[1] : 0;
	}

	private function fail(int $uid, string $message): never
	{
		flash('error', $message);
		redirect($uid ? '/commissions/draws/' . $uid : '/commissions');
	}

	private function user(int $uid): array
	{
		$u = Db::one('SELECT id, name, is_sales FROM users WHERE id = ?', [$uid]);
		if (!$u) {
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			exit;
		}
		if (!Commissions::canManage() && ((int) Auth::id() !== $uid || !Draws::onDraw($uid))) {
			$this->deny();
		}
		return $u;
	}

	private function schedule(int $uid, int $sid): array
	{
		$s = Db::one('SELECT * FROM commission_draw_schedules WHERE id = ? AND user_id = ?', [$sid, $uid]);
		if (!$s) {
			$this->fail($uid, 'That schedule is gone.');
		}
		return $s;
	}

	private function line(int $uid, int $lid): array
	{
		$l = Db::one('SELECT * FROM commission_draws WHERE id = ? AND user_id = ?', [$lid, $uid]);
		if (!$l) {
			$this->fail($uid, 'That draw is gone.');
		}
		return $l;
	}

	private function requireManage(): void
	{
		if (!Commissions::canManage()) {
			$this->deny();
		}
	}

	private function deny(): never
	{
		View::render('errors/403', ['title' => 'Not allowed'], 'layout', 403);
		exit;
	}
}
