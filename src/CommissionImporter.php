<?php
declare(strict_types=1);

namespace App;

/**
 * Loads commissions.json (from tools/import/build_commissions.py): sold jobs from the salespeople's
 * commission spreadsheets, with the amounts as the sheets recorded them (no plan, so they are never
 * refigured). Add-only: a job already recorded for that salesperson, or a project that already has
 * a commission, is skipped. A job is linked to its project when the names match one project.
 *
 * $paidBefore: jobs signed before that date are recorded as paid in full (the sheets have no paid
 * column), with one payment dated that day.
 */
final class CommissionImporter
{
	private array $log = [];
	private array $counts = ['added' => 0, 'skipped' => 0, 'linked' => 0, 'marked_paid' => 0, 'no_salesperson' => 0];

	public function __construct(private bool $dryRun, private ?string $paidBefore = null)
	{
	}

	public function run(array $data): array
	{
		if (($data['kind'] ?? '') !== 'commissions') {
			throw new \InvalidArgumentException('This is not a commissions import file (expected "kind": "commissions").');
		}
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			foreach ((array) ($data['rows'] ?? []) as $r) {
				$this->row($r);
			}
			$this->dryRun ? $pdo->rollBack() : $pdo->commit();
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
		return ['log' => $this->log, 'counts' => $this->counts];
	}

	private function row(array $r): void
	{
		$job = trim((string) ($r['job_name'] ?? ''));
		if ($job === '') {
			return;
		}
		$uid = $this->user((string) ($r['salesperson'] ?? ''));
		if (!$uid) {
			$this->counts['no_salesperson']++;
			$this->log[] = "  $job: no user matches salesperson \"" . ($r['salesperson'] ?? '') . '", skipped';
			return;
		}
		if (Db::value('SELECT 1 FROM commissions WHERE job_name = ? COLLATE NOCASE AND salesperson_id = ?', [$job, $uid])) {
			$this->counts['skipped']++;
			return;
		}
		$projectId = $this->project($job);
		if ($projectId && Db::value('SELECT 1 FROM commissions WHERE project_id = ?', [$projectId])) {
			$this->counts['skipped']++;
			$this->log[] = "  $job: its project already has a commission, skipped";
			return;
		}
		$row = [
			'project_id' => $projectId, 'job_name' => $job, 'signed_on' => $r['signed_on'] ?? null, 'salesperson_id' => $uid,
			'lead_source' => null, 'contract_amount' => (float) ($r['contract_amount'] ?? 0), 'plan' => null,
			'commission' => (float) ($r['commission'] ?? 0), 'self_gen' => (float) ($r['self_gen'] ?? 0),
			'payouts' => (float) ($r['payouts'] ?? 0), 'payouts_note' => $r['payouts_note'] ?? null,
			'status' => ($r['status'] ?? '') === 'cancelled' ? 'cancelled' : 'active',
			'notes' => trim('From the commission sheet' . (!empty($r['source']) ? ' (' . $r['source'] . ')' : '') . '.' . (!empty($r['notes']) ? ' ' . $r['notes'] : '')),
		];
		$id = Db::insert('commissions', $row);
		$this->counts['added']++;
		if ($projectId) {
			$this->counts['linked']++;
		}
		$total = $row['commission'] + $row['self_gen'] + $row['payouts'];
		$this->log[] = sprintf('  + %s (%s): %s%s%s', $job, $row['signed_on'] ?? 'no date', Presales::money($total),
			$projectId ? ', linked to its project' : '', $row['status'] === 'cancelled' ? ', cancelled' : '');
		if (!empty($r['total_differs'])) {
			$this->log[] = '    note: the sheet\'s Total Amount (' . Presales::money((float) $r['sheet_total']) . ') leaves out part of this; check it';
		}
		Activity::event('commission', $id, 'Imported from the commission spreadsheet');
		if ($this->paidBefore && $row['status'] === 'active' && $row['signed_on'] && $row['signed_on'] < $this->paidBefore && $total > 0) {
			Db::insert('commission_entries', ['commission_id' => $id, 'kind' => 'payment', 'user_id' => $uid, 'amount' => round($total, 2),
				'entry_date' => $this->paidBefore, 'note' => 'Paid before the tracker (from the commission sheet)']);
			$this->counts['marked_paid']++;
		}
	}

	/** A user by full name, or by a first name that only one user has. */
	private function user(string $name): ?int
	{
		$name = trim($name);
		if ($name === '') {
			return null;
		}
		$id = Db::value('SELECT id FROM users WHERE name = ? COLLATE NOCASE', [$name]);
		if ($id) {
			return (int) $id;
		}
		$ids = Db::all('SELECT id FROM users WHERE name LIKE ? COLLATE NOCASE', [$name . ' %']);
		return count($ids) === 1 ? (int) $ids[0]['id'] : null;
	}

	/** The one project whose name, or whose customer's name, is the job name. */
	private function project(string $job): ?int
	{
		$ids = array_column(Db::all('SELECT id FROM projects WHERE name = ? COLLATE NOCASE', [$job]), 'id');
		if (!$ids) {
			$ids = array_column(Db::all("SELECT p.id FROM projects p JOIN organizations o ON o.id = p.customer_id WHERE o.name = ? COLLATE NOCASE", [$job]), 'id');
		}
		return count($ids) === 1 ? (int) $ids[0] : null;
	}
}
