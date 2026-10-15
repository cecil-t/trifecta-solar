<?php
declare(strict_types=1);

namespace App;

/**
 * Sales commission records: one per sold job, with payments and adjustments per person paid.
 *
 * What is owed: commission + self-generated bonus + pays-the-salesperson items (site visit, REAP
 * paperwork). With an in-house handoff, the one who handed the lead off gets the handoff share of
 * that whole amount and the closing salesperson the rest. Adjustments (with a reason) change one
 * person's amount; payments pay it down. The referral bonus is owed to the outside referrer and
 * tracked on its own.
 */
final class Commissions
{
	public const LEAD_SOURCES = Presales::LEAD_SOURCES;

	public static function find(int $id): ?array
	{
		return Db::one('SELECT c.*, u.name AS salesperson_name, h.name AS handoff_name, p.project_number, p.name AS project_name
			FROM commissions c LEFT JOIN users u ON u.id = c.salesperson_id LEFT JOIN users h ON h.id = c.handoff_user_id
			LEFT JOIN projects p ON p.id = c.project_id WHERE c.id = ?', [$id]);
	}

	public static function forProject(int $projectId): ?array
	{
		$id = (int) Db::value('SELECT id FROM commissions WHERE project_id = ?', [$projectId]);
		return $id ? self::find($id) : null;
	}

	public static function entries(int $id): array
	{
		return Db::all('SELECT e.*, u.name AS user_name, b.name AS by_name FROM commission_entries e
			LEFT JOIN users u ON u.id = e.user_id LEFT JOIN users b ON b.id = e.created_by
			WHERE e.commission_id = ? ORDER BY e.entry_date, e.id', [$id]);
	}

	/** Admins manage commissions; salespeople see the jobs they are paid on. */
	public static function canManage(): bool
	{
		return Auth::isAdmin();
	}

	public static function canUse(): bool
	{
		$u = Auth::user();
		return $u && (Auth::isAdmin() || !empty($u['is_sales'])
			|| Db::value('SELECT 1 FROM commissions WHERE ? IN (salesperson_id, handoff_user_id)', [(int) $u['id']]));
	}

	public static function canSee(array $c): bool
	{
		return Auth::isAdmin() || in_array((int) Auth::id(), [(int) $c['salesperson_id'], (int) $c['handoff_user_id']], true);
	}

	/** The whole payout before the split: commission, bonus and pays-the-salesperson items. */
	public static function total(array $c): float
	{
		return (float) $c['commission'] + (float) $c['self_gen'] + (float) $c['payouts'];
	}

	/**
	 * Per person paid: due (their share plus their adjustments), paid, balance.
	 * @return array<int, array{user_id:int, name:string, role:string, share:float, adjust:float, due:float, paid:float, balance:float}>
	 */
	public static function payees(array $c, ?array $entries = null): array
	{
		$entries ??= self::entries((int) $c['id']);
		$total = self::total($c);
		$share = $c['handoff_user_id'] ? (float) $c['handoff_share'] : 0.0;
		$out = [];
		$add = static function (int $uid, string $role, float $amount) use (&$out): void {
			if (!isset($out[$uid])) {
				$out[$uid] = ['user_id' => $uid, 'name' => (string) Db::value('SELECT name FROM users WHERE id = ?', [$uid]), 'role' => $role,
					'share' => 0.0, 'adjust' => 0.0, 'due' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
			}
			$out[$uid]['share'] += $amount;
		};
		if ($c['salesperson_id']) {
			$add((int) $c['salesperson_id'], 'Salesperson', $total * (1 - $share));
		}
		if ($c['handoff_user_id']) {
			$add((int) $c['handoff_user_id'], 'Handed off', $total * $share);
		}
		foreach ($entries as $e) {
			$uid = (int) $e['user_id'];
			if (!$uid) {
				continue;
			}
			$add($uid, 'Other', 0.0);
			$out[$uid][$e['kind'] === 'payment' ? 'paid' : 'adjust'] += (float) $e['amount'];
		}
		foreach ($out as &$p) {
			$p['share'] = round($p['share'], 2);
			$p['due'] = round($p['share'] + $p['adjust'], 2);
			$p['balance'] = round($p['due'] - $p['paid'], 2);
		}
		unset($p);
		return $out;
	}

	/** Owed | Part paid | Paid | Nothing owed | Cancelled, for a job or for one person on it. */
	public static function status(array $c, float $due, float $paid): string
	{
		if ($c['status'] === 'cancelled') {
			return 'Cancelled';
		}
		if ($due <= 0.005 && $paid <= 0.005) {
			return 'Nothing owed';
		}
		if ($due - $paid <= 0.005) {
			return 'Paid';
		}
		return $paid > 0.005 ? 'Part paid' : 'Owed';
	}

	/** Amounts from the plan the job sold under, for the commissionable amount and lead source. */
	public static function compute(array $c): array
	{
		$plan = Commission::plan($c['plan'] ?? null);
		if ($plan === null) {
			return [];
		}
		$selfGenAllowed = $c['lead_source'] !== 'repeat';
		$a = Commission::amounts($plan, (float) $c['contract_amount'], $c['lead_source'], $selfGenAllowed);
		return ['commission' => $a['commission'], 'self_gen' => $a['self_gen'], 'referral_bonus' => $a['referral_bonus'],
			'handoff_share' => $c['handoff_user_id'] ? $plan['handoff_share'] : 0.0];
	}

	/**
	 * A new record's starting values: from the opportunity's sold build sheet when there is one,
	 * otherwise from the project (contract price, salesperson, signed date).
	 */
	public static function draft(array $p, ?array $o): array
	{
		$signed = Db::value("SELECT done_date FROM project_tasks WHERE project_id = ? AND gate = 'start_clock' AND done_date IS NOT NULL ORDER BY done_date LIMIT 1", [(int) $p['id']]);
		$c = [
			'project_id' => (int) $p['id'], 'opportunity_id' => null, 'design_id' => null,
			'job_name' => trim($p['project_number'] . ' ' . $p['name']), 'signed_on' => $signed ?: null,
			'salesperson_id' => $p['salesperson_id'], 'handoff_user_id' => null, 'lead_source' => null, 'referrer_name' => null,
			'contract_amount' => $p['contract_price_cents'] !== null ? round((int) $p['contract_price_cents'] / 100, 2) : 0.0,
			'plan' => json_encode(Commission::currentPlan()), 'payouts' => 0.0, 'payouts_note' => null, 'notes' => null,
		];
		if ($o) {
			$c['opportunity_id'] = (int) $o['id'];
			$c['salesperson_id'] = $o['salesperson_id'] ?: $c['salesperson_id'];
			$c['handoff_user_id'] = $o['handoff_user_id'];
			$c['lead_source'] = $o['lead_source'] === 'self_gen' && Presales::isRepeatCustomer($o) ? 'repeat' : $o['lead_source'];
			$c['referrer_name'] = $o['lead_source'] === 'referral' ? (Presales::leadLabel($o) ? preg_replace('/^Referral: /', '', Presales::leadLabel($o)) : null) : null;
			$d = $o['sold_design_id'] ? Designs::find((int) $o['sold_design_id']) : null;
			if ($d) {
				$calc = Designs::calc($d, Designs::lines((int) $d['id']), Designs::arrays((int) $d['id']));
				$c['design_id'] = (int) $d['id'];
				$c['contract_amount'] = round($calc['commissionable'], 2);
				$c['payouts'] = round($calc['cost_payout'] + $calc['pass_payout'] + $calc['site_visit'], 2);
				$names = [];
				foreach ($calc['lines'] as $l) {
					if (!empty($l['pays_sales']) && $l['cost'] > 0) {
						$names[] = $l['name'] . ' ' . Presales::money($l['cost']);
					}
				}
				if ($calc['site_visit'] > 0) {
					$names[] = 'Site visit ' . Presales::money($calc['site_visit']);
				}
				$c['payouts_note'] = $names ? implode(', ', $names) : null;
				if (!empty($d['commission_plan'])) {
					$c['plan'] = $d['commission_plan'];
				}
			}
		}
		return $c;
	}

	/**
	 * Closing the project's "pay commission" step pays every balance as of the step's date; opening
	 * it again takes those payments back; a new date moves them.
	 */
	public static function onPayStep(int $projectId, array $task, ?string $doneDate): void
	{
		$c = self::forProject($projectId);
		$taskId = (int) $task['id'];
		$had = (int) Db::value('SELECT COUNT(*) FROM commission_entries WHERE task_id = ?', [$taskId]);
		if ($doneDate === null) {
			if ($had) {
				Db::run('DELETE FROM commission_entries WHERE task_id = ?', [$taskId]);
				Activity::event('project', $projectId, 'Commission payments from the "' . $task['name'] . '" step taken back (step reopened)');
				if ($c) {
					Activity::event('commission', (int) $c['id'], 'Payments from the project\'s "' . $task['name'] . '" step taken back (step reopened)');
				}
			}
			return;
		}
		if ($had) {
			Db::run('UPDATE commission_entries SET entry_date = ? WHERE task_id = ?', [$doneDate, $taskId]);
			return;
		}
		if (!$c) {
			Activity::event('project', $projectId, 'No sales commission set up for this project yet, so nothing was marked paid');
			return;
		}
		if ($c['status'] === 'cancelled') {
			return;
		}
		$paid = [];
		foreach (self::payees($c) as $p) {
			if ($p['balance'] > 0.005) {
				Db::insert('commission_entries', ['commission_id' => (int) $c['id'], 'kind' => 'payment', 'user_id' => $p['user_id'],
					'amount' => $p['balance'], 'entry_date' => $doneDate, 'note' => 'Project step "' . $task['name'] . '" closed',
					'task_id' => $taskId, 'created_by' => Auth::id()]);
				$paid[] = $p['name'] . ' ' . Presales::money($p['balance']);
			}
		}
		if ($paid) {
			Activity::event('commission', (int) $c['id'], 'Marked paid from the project step: ' . implode(', ', $paid));
			Activity::event('project', $projectId, 'Sales commission marked paid: ' . implode(', ', $paid));
		}
	}
}
