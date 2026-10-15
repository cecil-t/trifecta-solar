<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Commission;
use App\Commissions;
use App\Db;
use App\Presales;
use App\Projects;
use App\View;

/**
 * Sales commissions: the list of sold jobs and what each salesperson is owed and has been paid,
 * one record per job, and the payments and adjustments against it. Admins manage; salespeople
 * see the jobs they are paid on.
 */
final class CommissionController
{
	private const TRACKED = [
		'project_id' => 'Project', 'job_name' => 'Job', 'signed_on' => 'Contract signed', 'salesperson_id' => 'Salesperson',
		'handoff_user_id' => 'Handed off by', 'handoff_share' => 'Handoff share', 'lead_source' => 'Lead source',
		'referrer_name' => 'Referred by', 'contract_amount' => 'Commissionable amount', 'commission' => 'Commission',
		'self_gen' => 'Self-generated bonus', 'payouts' => 'Other payouts', 'payouts_note' => 'Other payouts note',
		'referral_bonus' => 'Referral bonus', 'referral_paid_on' => 'Referral bonus paid', 'referral_w9' => 'Referrer W-9 on file',
		'status' => 'Status', 'notes' => 'Notes',
	];

	public function index(): void
	{
		if (!Commissions::canUse()) {
			$this->deny();
		}
		$admin = Commissions::canManage();
		// Remember the list as filtered, so a job's back link returns to it
		$_SESSION['commissions_list'] = local_path((string) ($_SERVER['REQUEST_URI'] ?? '/commissions'), '/commissions');
		$person = $admin ? (int) ($_GET['sales'] ?? 0) : (int) Auth::id();
		$year = preg_match('/^\d{4}$/', (string) ($_GET['year'] ?? '')) ? (string) $_GET['year'] : '';
		$tab = (string) ($_GET['tab'] ?? 'all');
		if (!in_array($tab, ['owed', 'notdue', 'paid', 'all', 'cancelled'], true)) {
			$tab = 'all';
		}
		$where = ['1 = 1'];
		$params = [];
		if ($person) {
			$where[] = '? IN (c.salesperson_id, c.handoff_user_id)';
			$params[] = $person;
		}
		if ($year !== '') {
			$where[] = "substr(c.signed_on, 1, 4) = ?";
			$params[] = $year;
		}
		$records = Db::all('SELECT c.*, u.name AS salesperson_name, h.name AS handoff_name, p.project_number, p.name AS project_name
			FROM commissions c LEFT JOIN users u ON u.id = c.salesperson_id LEFT JOIN users h ON h.id = c.handoff_user_id
			LEFT JOIN projects p ON p.id = c.project_id WHERE ' . implode(' AND ', $where) . '
			ORDER BY c.signed_on IS NULL, c.signed_on, c.id', $params);
		$phases = Commissions::phases($records);
		$entries = [];
		if ($records) {
			$ids = array_column($records, 'id');
			foreach (Db::all('SELECT * FROM commission_entries WHERE commission_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $e) {
				$entries[(int) $e['commission_id']][] = $e;
			}
		}
		$rows = [];
		$counts = ['owed' => 0, 'notdue' => 0, 'paid' => 0, 'all' => 0, 'cancelled' => 0];
		$total = ['contract' => 0.0, 'commission' => 0.0, 'self_gen' => 0.0, 'payouts' => 0.0, 'adjust' => 0.0, 'due' => 0.0, 'paid' => 0.0, 'balance' => 0.0,
			'due_now' => 0.0, 'not_due' => 0.0];
		foreach ($records as $c) {
			$payees = Commissions::payees($c, $entries[(int) $c['id']] ?? []);
			if ($person) {
				$mine = $payees[$person] ?? ['adjust' => 0.0, 'due' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'share' => 0.0];
				$due = $mine['due'];
				$paid = $mine['paid'];
				$adjust = $mine['adjust'];
			} else {
				$due = array_sum(array_column($payees, 'due'));
				$paid = array_sum(array_column($payees, 'paid'));
				$adjust = array_sum(array_column($payees, 'adjust'));
			}
			$phase = $phases[(int) $c['id']] ?? null;
			$status = Commissions::status($c, $due, $paid, Commissions::isDue($phase));
			$bucket = match ($status) {
				'Cancelled' => 'cancelled',
				'Paid', 'Nothing owed' => 'paid',
				'Not due yet' => 'notdue',
				default => 'owed',
			};
			$counts[$bucket]++;
			if ($bucket !== 'cancelled') {
				$counts['all']++;
			}
			if (!($tab === 'all' ? $bucket !== 'cancelled' : $tab === $bucket)) {
				continue;
			}
			$row = $c + ['due' => $due, 'paid' => $paid, 'adjust' => $adjust, 'balance' => round($due - $paid, 2), 'state' => $status,
				'phase' => $phase];
			$rows[] = $row;
			foreach (['commission', 'self_gen', 'payouts'] as $k) {
				$total[$k] += (float) $c[$k];
			}
			$total['contract'] += (float) $c['contract_amount'];
			$total['adjust'] += $adjust;
			$total['due'] += $due;
			$total['paid'] += $paid;
			$total['balance'] += $row['balance'];
			if ($row['balance'] > 0.005) {
				$total[$bucket === 'notdue' ? 'not_due' : 'due_now'] += $row['balance'];
			}
		}
		$referrals = $admin ? Db::all("SELECT c.id, c.job_name, c.referrer_name, c.referral_bonus, c.referral_w9, p.project_number, p.name AS project_name
			FROM commissions c LEFT JOIN projects p ON p.id = c.project_id
			WHERE c.referral_bonus > 0 AND c.referral_paid_on IS NULL AND c.status = 'active' ORDER BY c.signed_on") : [];
		View::render('commissions/index', [
			'title' => 'Sales commissions',
			'rows' => $rows, 'total' => $total, 'counts' => $counts, 'tab' => $tab, 'year' => $year, 'person' => $person,
			'admin' => $admin, 'referrals' => $referrals,
			'years' => array_column(Db::all("SELECT DISTINCT substr(signed_on, 1, 4) AS y FROM commissions WHERE signed_on IS NOT NULL ORDER BY y DESC"), 'y'),
			'people' => $admin ? $this->people() : [],
			'personName' => $person ? (string) Db::value('SELECT name FROM users WHERE id = ?', [$person]) : '',
		]);
	}

	public function show(int $id): void
	{
		$c = $this->find($id);
		$entries = Commissions::entries($id);
		$o = $c['opportunity_id'] ? Db::one('SELECT id, name FROM presales_opportunities WHERE id = ?', [(int) $c['opportunity_id']]) : null;
		$phase = Commissions::phases([$c])[$id] ?? null;
		View::render('commissions/show', [
			'phase' => $phase,
			'listUrl' => local_path((string) ($_SESSION['commissions_list'] ?? '/commissions'), '/commissions') . '#c-' . $id,
			'title' => 'Commission: ' . $c['job_name'], 'c' => $c, 'entries' => $entries,
			'payees' => Commissions::payees($c, $entries), 'o' => $o, 'admin' => Commissions::canManage(),
			'people' => $this->people(), 'activity' => Activity::feed('commission', $id),
			'payStep' => $c['project_id'] ? Db::one("SELECT * FROM project_tasks WHERE project_id = ? AND action = 'pay_commission' ORDER BY id LIMIT 1", [(int) $c['project_id']]) : null,
		]);
	}

	/** New commission record for a project, filled from its sold opportunity when one is picked. */
	public function create(int $id): void
	{
		$this->requireManage();
		$p = $this->project($id);
		if ($existing = Commissions::forProject($id)) {
			redirect('/commissions/' . (int) $existing['id']);
		}
		$opps = Db::all("SELECT o.id, o.name, o.customer_id, o.project_id, u.name AS salesperson_name FROM presales_opportunities o
			LEFT JOIN users u ON u.id = o.salesperson_id
			WHERE o.status = 'sold' AND (o.project_id IS NULL OR o.project_id = ?)
			  AND o.id NOT IN (SELECT opportunity_id FROM commissions WHERE opportunity_id IS NOT NULL)
			ORDER BY (o.customer_id = ?) DESC, o.status_at DESC", [$id, (int) $p['customer_id']]);
		$oppId = (int) ($_GET['opportunity'] ?? 0);
		if (!isset($_GET['opportunity'])) {
			foreach ($opps as $op) {
				if ((int) $op['project_id'] === $id || ($p['customer_id'] && (int) $op['customer_id'] === (int) $p['customer_id'])) {
					$oppId = (int) $op['id'];
					break;
				}
			}
		}
		$o = $oppId ? Db::one("SELECT * FROM presales_opportunities WHERE id = ? AND status = 'sold'", [$oppId]) : null;
		$c = $_SESSION['old_commission'] ?? Commissions::draft($p, $o);
		unset($_SESSION['old_commission']);
		View::render('commissions/form', [
			'title' => 'Set up commission: ' . $p['name'], 'c' => $c, 'p' => $p, 'opps' => $opps, 'oppId' => $o ? (int) $o['id'] : 0,
			'people' => $this->people(), 'isNew' => true,
		]);
	}

	public function store(int $id): void
	{
		$this->requireManage();
		$p = $this->project($id);
		if (Commissions::forProject($id)) {
			redirect('/projects/' . $id);
		}
		try {
			$data = $this->input(null);
			$data['project_id'] = $id;
			$data['job_name'] = $data['job_name'] ?: trim($p['project_number'] . ' ' . $p['name']);
			$data['created_by'] = Auth::id();
			$cid = Db::transaction(static function () use ($data, $id) {
				$cid = Db::insert('commissions', $data);
				if ($data['opportunity_id']) {
					Db::run('UPDATE presales_opportunities SET project_id = ? WHERE id = ? AND project_id IS NULL', [$id, $data['opportunity_id']]);
				}
				return $cid;
			});
			Activity::event('commission', $cid, 'Commission set up: ' . Presales::money(Commissions::total($data)) . ' to pay');
			Activity::event('project', $id, 'Sales commission set up (' . Presales::money(Commissions::total($data)) . ')');
			if ($data['opportunity_id']) {
				Activity::event('presales', (int) $data['opportunity_id'], 'Linked to project ' . $p['project_number'] . ' ' . $p['name'] . ' for its commission');
			}
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_commission'] = $_POST;
			flash('error', $e->getMessage());
			redirect('/projects/' . $id . '/commission/new' . (isset($_POST['opportunity_id']) ? '?opportunity=' . (int) $_POST['opportunity_id'] : ''));
		}
		flash('success', 'Commission set up.');
		redirect('/commissions/' . $cid);
	}

	public function edit(int $id): void
	{
		$this->requireManage();
		$c = $_SESSION['old_commission'] ?? $this->find($id);
		unset($_SESSION['old_commission']);
		$c['id'] = $id;
		$p = !empty($c['project_id']) ? $this->project((int) $c['project_id']) : null;
		View::render('commissions/form', [
			'title' => 'Edit commission: ' . ($c['job_name'] ?? ''), 'c' => $c + ['plan' => $this->find($id)['plan']], 'p' => $p, 'opps' => [], 'oppId' => 0,
			'people' => $this->people(), 'isNew' => false,
		]);
	}

	public function update(int $id): void
	{
		$this->requireManage();
		$before = $this->find($id);
		try {
			$data = $this->input($before);
			$data['updated_at'] = now_utc();
			Db::update('commissions', $id, $data);
			Activity::changes('commission', $id, $before, $data, self::TRACKED, $this->formatters());
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_commission'] = $_POST + ['id' => $id];
			flash('error', $e->getMessage());
			redirect('/commissions/' . $id . '/edit');
		}
		flash('success', 'Saved.');
		redirect('/commissions/' . $id);
	}

	/** Project search for linking a commission (projects without one): JSON for the picker. */
	public function projectSearch(): void
	{
		$this->requireManage();
		header('Content-Type: application/json');
		$q = trim((string) ($_GET['q'] ?? ''));
		if (mb_strlen($q) < 2) {
			echo '[]';
			return;
		}
		$like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
		$rows = Db::all("SELECT p.id, p.project_number, p.name, p.site_city FROM projects p
			WHERE (p.name LIKE ? ESCAPE '\\' OR p.project_number LIKE ? ESCAPE '\\')
			  AND p.id NOT IN (SELECT project_id FROM commissions WHERE project_id IS NOT NULL)
			ORDER BY (p.name LIKE ?) DESC, p.project_number DESC LIMIT 12", [$like, $like, $q . '%']);
		echo json_encode(array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => trim($r['project_number'] . ' ' . $r['name']),
			'kind' => 'Project', 'city' => (string) $r['site_city']], $rows));
	}

	/** A payment or an adjustment for one person. */
	public function addEntry(int $id): void
	{
		$this->requireManage();
		$c = $this->find($id);
		$kind = ($_POST['kind'] ?? '') === 'adjustment' ? 'adjustment' : 'payment';
		$user = (int) ($_POST['user_id'] ?? 0);
		$amount = Projects::parseNumber((string) ($_POST['amount'] ?? ''));
		$date = Projects::parseDate((string) ($_POST['entry_date'] ?? '')) ?? date('Y-m-d');
		$note = trim((string) ($_POST['note'] ?? '')) ?: null;
		$error = match (true) {
			!$user || !Db::value('SELECT 1 FROM users WHERE id = ?', [$user]) => 'Pick who this is for.',
			$amount === null || abs($amount) < 0.005 => 'Enter an amount.',
			$kind === 'payment' && $amount < 0 => 'A payment is a positive amount. Use an adjustment to take money back.',
			$kind === 'adjustment' && $note === null => 'Say why for an adjustment.',
			default => null,
		};
		if ($error) {
			flash('error', $error);
			redirect('/commissions/' . $id);
		}
		Db::insert('commission_entries', ['commission_id' => $id, 'kind' => $kind, 'user_id' => $user, 'amount' => round($amount, 2),
			'entry_date' => $date, 'note' => $note, 'created_by' => Auth::id()]);
		$name = (string) Db::value('SELECT name FROM users WHERE id = ?', [$user]);
		Activity::event('commission', $id, ($kind === 'payment' ? 'Payment ' : 'Adjustment ') . Presales::money($amount) . ' for ' . $name . ' on ' . fmt_date($date) . ($note ? ': ' . $note : ''));
		flash('success', $kind === 'payment' ? 'Payment recorded.' : 'Adjustment recorded.');
		redirect('/commissions/' . $id);
	}

	public function deleteEntry(int $id, int $entryId): void
	{
		$this->requireManage();
		$this->find($id);
		$e = Db::one('SELECT e.*, u.name AS user_name FROM commission_entries e LEFT JOIN users u ON u.id = e.user_id WHERE e.id = ? AND e.commission_id = ?', [$entryId, $id]);
		if ($e) {
			Db::run('DELETE FROM commission_entries WHERE id = ?', [$entryId]);
			Activity::event('commission', $id, 'Removed ' . $e['kind'] . ' ' . Presales::money((float) $e['amount']) . ' for ' . $e['user_name'] . ' (' . fmt_date($e['entry_date']) . ')');
		}
		flash('success', 'Removed.');
		redirect('/commissions/' . $id);
	}

	/** Pay every balance today, and close the project's pay-commission step if it is open. */
	public function markPaid(int $id): void
	{
		$this->requireManage();
		$c = $this->find($id);
		$date = Projects::parseDate((string) ($_POST['entry_date'] ?? '')) ?? date('Y-m-d');
		$paid = [];
		Db::transaction(static function () use ($c, $id, $date, &$paid) {
			foreach (Commissions::payees($c) as $p) {
				if ($p['balance'] > 0.005) {
					Db::insert('commission_entries', ['commission_id' => $id, 'kind' => 'payment', 'user_id' => $p['user_id'],
						'amount' => $p['balance'], 'entry_date' => $date, 'note' => 'Marked paid', 'created_by' => Auth::id()]);
					$paid[] = $p['name'] . ' ' . Presales::money($p['balance']);
				}
			}
			if ($c['project_id']) {
				$step = Db::one("SELECT * FROM project_tasks WHERE project_id = ? AND action = 'pay_commission' AND done_date IS NULL ORDER BY id LIMIT 1", [(int) $c['project_id']]);
				if ($step) {
					Db::update('project_tasks', (int) $step['id'], ['done_date' => $date, 'needed' => 1, 'updated_at' => now_utc()]);
					Activity::event('project', (int) $c['project_id'], '"' . $step['name'] . '" done ' . fmt_date($date) . ' (commission marked paid)');
				}
			}
		});
		if ($paid) {
			Activity::event('commission', $id, 'Marked paid ' . fmt_date($date) . ': ' . implode(', ', $paid));
		}
		flash('success', $paid ? 'Marked paid: ' . implode(', ', $paid) . '.' : 'Nothing was owed.');
		redirect('/commissions/' . $id);
	}

	public function delete(int $id): void
	{
		$this->requireManage();
		$c = $this->find($id);
		Db::run('DELETE FROM commissions WHERE id = ?', [$id]);
		if ($c['project_id']) {
			Activity::event('project', (int) $c['project_id'], 'Sales commission record deleted');
		}
		flash('success', 'Commission record for ' . $c['job_name'] . ' deleted.');
		redirect($c['project_id'] ? '/projects/' . (int) $c['project_id'] : '/commissions');
	}

	private function input(?array $before): array
	{
		$num = static function (string $k, string $label, bool $blankNull = false): ?float {
			$raw = trim((string) ($_POST[$k] ?? ''));
			if ($raw === '') {
				return $blankNull ? null : 0.0;
			}
			$v = Projects::parseNumber($raw);
			if ($v === null || !is_numeric(preg_replace('/[^0-9.\-]/', '', $raw))) {
				throw new \InvalidArgumentException($label . ' must be a number.');
			}
			return $v;
		};
		$user = static function (string $k): ?int {
			$v = (int) ($_POST[$k] ?? 0);
			return $v && Db::value('SELECT 1 FROM users WHERE id = ?', [$v]) ? $v : null;
		};
		$lead = (string) ($_POST['lead_source'] ?? '');
		$signed = trim((string) ($_POST['signed_on'] ?? ''));
		$data = [
			'job_name' => trim((string) ($_POST['job_name'] ?? '')) ?: ($before['job_name'] ?? ''),
			'signed_on' => $signed === '' ? null : (Projects::parseDate($signed) ?? throw new \InvalidArgumentException('Use a date like 10/03/2026 for the contract signed date.')),
			'salesperson_id' => $user('salesperson_id'),
			'handoff_user_id' => $user('handoff_user_id'),
			'lead_source' => array_key_exists($lead, Presales::LEAD_SOURCES) ? $lead : null,
			'referrer_name' => trim((string) ($_POST['referrer_name'] ?? '')) ?: null,
			'contract_amount' => $num('contract_amount', 'Commissionable amount'),
			'payouts' => $num('payouts', 'Other payouts'),
			'payouts_note' => trim((string) ($_POST['payouts_note'] ?? '')) ?: null,
			'referral_paid_on' => Projects::parseDate((string) ($_POST['referral_paid_on'] ?? '')),
			'referral_w9' => empty($_POST['referral_w9']) ? 0 : 1,
			'status' => ($_POST['status'] ?? '') === 'cancelled' ? 'cancelled' : 'active',
			'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
		];
		if ($before !== null) {
			// Linking (or relinking) the job to its project in the tracker
			$pid = (int) ($_POST['project_id'] ?? 0);
			if ($pid && !Db::value('SELECT 1 FROM projects WHERE id = ?', [$pid])) {
				$pid = 0;
			}
			if ($pid && Db::value('SELECT 1 FROM commissions WHERE project_id = ? AND id <> ?', [$pid, (int) $before['id']])) {
				throw new \InvalidArgumentException('That project already has a commission record.');
			}
			$data['project_id'] = $pid ?: null;
		}
		if ($data['handoff_user_id'] && $data['handoff_user_id'] === $data['salesperson_id']) {
			throw new \InvalidArgumentException('The salesperson can\'t also be the one who handed the lead off.');
		}
		if ($before === null) {
			$data['opportunity_id'] = (int) ($_POST['opportunity_id'] ?? 0) ?: null;
			$data['design_id'] = (int) ($_POST['design_id'] ?? 0) ?: null;
			$data['plan'] = Commission::plan((string) ($_POST['plan'] ?? '')) !== null ? (string) $_POST['plan'] : json_encode(Commission::currentPlan());
		}
		$plan = $before === null ? $data['plan'] : $before['plan'];
		$computed = Commissions::compute($data + ['plan' => $plan]);
		if ($computed) {
			$data += $computed;
		} else {
			// Jobs from before the tracker: amounts as typed
			$data['commission'] = $num('commission', 'Commission');
			$data['self_gen'] = $num('self_gen', 'Self-generated bonus');
			$data['handoff_share'] = ($num('handoff_share', 'Handoff share') ?? 0) / 100;
		}
		$ref = $num('referral_bonus', 'Referral bonus', true);
		if ($ref !== null) {
			$data['referral_bonus'] = $ref;
		}
		$data['referral_bonus'] ??= 0.0;
		return $data;
	}

	private function formatters(): array
	{
		$name = static fn ($v) => (string) Db::value('SELECT name FROM users WHERE id = ?', [$v]);
		$project = static fn ($v) => (string) Db::value("SELECT project_number || ' ' || name FROM projects WHERE id = ?", [$v]);
		$money = static fn ($v) => $v === null || $v === '' ? '' : Presales::money((float) $v);
		return [
			'salesperson_id' => $name, 'handoff_user_id' => $name, 'project_id' => $project,
			'lead_source' => static fn ($v) => Presales::LEAD_SOURCES[$v] ?? (string) $v,
			'handoff_share' => static fn ($v) => Presales::pct((float) $v, 0),
			'contract_amount' => $money, 'commission' => $money, 'self_gen' => $money, 'payouts' => $money, 'referral_bonus' => $money,
			'signed_on' => static fn ($v) => fmt_date($v), 'referral_paid_on' => static fn ($v) => fmt_date($v),
			'referral_w9' => static fn ($v) => $v ? 'Yes' : 'No',
			'status' => static fn ($v) => $v === 'cancelled' ? 'Cancelled' : 'Active',
		];
	}

	private function people(): array
	{
		return Db::all('SELECT id, name FROM users WHERE is_active = 1 AND (is_sales = 1 OR is_admin = 1)
			OR id IN (SELECT salesperson_id FROM commissions UNION SELECT handoff_user_id FROM commissions) ORDER BY name');
	}

	private function find(int $id): array
	{
		$c = Commissions::find($id);
		if (!$c) {
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			exit;
		}
		if (!Commissions::canSee($c)) {
			$this->deny();
		}
		return $c;
	}

	private function project(int $id): array
	{
		$p = Db::one('SELECT * FROM projects WHERE id = ?', [$id]);
		if (!$p) {
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			exit;
		}
		return $p;
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
