<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Designs;
use App\Presales;
use App\Projects;
use App\View;

/**
 * Pre-Sales: opportunities (a customer and a site being quoted) and their build sheets.
 * An opportunity is Open, Sold (tied to a project) or Lost (with an optional reason).
 */
final class PresalesController
{
	private const TRACKED = [
		'name' => 'Name', 'customer_id' => 'Customer', 'salesperson_id' => 'Salesperson', 'customer_type' => 'Customer type',
		'is_agricultural' => 'Agricultural', 'site_street' => 'Street', 'site_city' => 'City', 'site_state' => 'State',
		'site_zip' => 'ZIP', 'drive_url' => 'Drive folder', 'notes' => 'Notes',
		'utility_id' => 'Utility', 'bill_name' => 'Name on utility bill', 'utility_account' => 'Utility account #',
		'utility_meter' => 'Meter #', 'utility_tariff' => 'Utility rate', 'service_address' => 'Service address',
		'annual_usage_kwh' => 'Last 12 months usage (kWh)',
	];

	public function index(): void
	{
		$status = (string) ($_GET['status'] ?? 'open');
		if (!in_array($status, ['open', 'sold', 'lost', 'all'], true)) {
			$status = 'open';
		}
		$q = trim((string) ($_GET['q'] ?? ''));
		if ($q !== '') {
			$status = 'all';
		}
		$where = [];
		$params = [];
		if ($status !== 'all') {
			$where[] = 'o.status = ?';
			$params[] = $status;
		}
		if ($q !== '') {
			$where[] = '(o.name LIKE ? OR c.name LIKE ? OR o.site_city LIKE ? OR o.site_street LIKE ?)';
			array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
		}
		$rows = Db::all(
			'SELECT o.*, c.name AS customer_name, u.name AS salesperson_name, u.initials AS salesperson_initials,
				(SELECT COUNT(*) FROM presales_designs d WHERE d.opportunity_id = o.id) AS design_count,
				(SELECT MIN(price_total) FROM presales_designs d WHERE d.opportunity_id = o.id AND price_total > 0) AS price_min,
				(SELECT MAX(price_total) FROM presales_designs d WHERE d.opportunity_id = o.id AND price_total > 0) AS price_max,
				(SELECT MAX(dc_watts) FROM presales_designs d WHERE d.opportunity_id = o.id) AS dc_max,
				p.project_number
			 FROM presales_opportunities o
			 LEFT JOIN organizations c ON c.id = o.customer_id
			 LEFT JOIN users u ON u.id = o.salesperson_id
			 LEFT JOIN projects p ON p.id = o.project_id'
			. ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.updated_at DESC',
			$params
		);
		$counts = ['all' => 0];
		foreach (Db::all('SELECT status, COUNT(*) AS n FROM presales_opportunities GROUP BY status') as $r) {
			$counts[$r['status']] = (int) $r['n'];
			$counts['all'] += (int) $r['n'];
		}
		View::render('presales/index', ['title' => 'Pre-Sales', 'rows' => $rows, 'status' => $status, 'q' => $q, 'counts' => $counts]);
	}

	public function create(): void
	{
		$o = $_SESSION['old_presales'] ?? ['salesperson_id' => Auth::id(), 'site_state' => 'PA', 'customer_type' => 'residential'];
		unset($_SESSION['old_presales']);
		$this->form($o, null);
	}

	public function edit(int $id): void
	{
		$o = $_SESSION['old_presales'] ?? $this->find($id);
		unset($_SESSION['old_presales']);
		$this->form($o, $id);
	}

	private function form(array $o, ?int $id): void
	{
		View::render('presales/opportunity_form', [
			'title' => $id ? 'Edit ' . $o['name'] : 'New opportunity', 'o' => $o, 'id' => $id,
			'customers' => Projects::orgs('customer'), 'users' => Projects::users(), 'utilities' => Projects::utilities(),
		]);
	}

	public function store(): void
	{
		try {
			$data = $this->input(null);
			$data['created_by'] = Auth::id();
			$id = Db::insert('presales_opportunities', $data);
			Activity::event('presales', $id, 'Opportunity created');
			$designId = Designs::create($id, Designs::defaultName($id));
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_presales'] = $_POST;
			flash('error', $e->getMessage());
			redirect('/presales/new');
		}
		redirect('/presales/' . $id . '/designs/' . $designId);
	}

	public function update(int $id): void
	{
		$before = $this->find($id);
		try {
			$data = $this->input($id);
			$data['updated_at'] = now_utc();
			Db::update('presales_opportunities', $id, $data);
			Activity::changes('presales', $id, $before, $data, self::TRACKED, [
				'customer_id' => static fn ($v) => Db::value('SELECT name FROM organizations WHERE id = ?', [$v]) ?? $v,
				'salesperson_id' => static fn ($v) => Db::value('SELECT name FROM users WHERE id = ?', [$v]) ?? $v,
				'utility_id' => static fn ($v) => Db::value('SELECT name FROM utilities WHERE id = ?', [$v]) ?? $v,
				'is_agricultural' => static fn ($v) => $v ? 'Yes' : 'No',
			]);
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_presales'] = $_POST + ['id' => $id];
			flash('error', $e->getMessage());
			redirect('/presales/' . $id . '/edit');
		}
		flash('success', 'Saved.');
		redirect('/presales/' . $id);
	}

	public function show(int $id): void
	{
		$o = Db::one(
			'SELECT o.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
				u.name AS salesperson_name, p.project_number, p.name AS project_name, ut.name AS utility_name
			 FROM presales_opportunities o
			 LEFT JOIN organizations c ON c.id = o.customer_id
			 LEFT JOIN users u ON u.id = o.salesperson_id
			 LEFT JOIN projects p ON p.id = o.project_id
			 LEFT JOIN utilities ut ON ut.id = o.utility_id
			 WHERE o.id = ?', [$id]
		) ?? $this->notFound();
		$designs = Db::all('SELECT d.*, u.name AS updated_by_name FROM presales_designs d LEFT JOIN users u ON u.id = d.updated_by
			WHERE d.opportunity_id = ? ORDER BY d.sort_order, d.id', [$id]);
		$cards = [];
		foreach ($designs as $d) {
			$lines = Designs::lines((int) $d['id']);
			$calc = Designs::calc($d, $lines, Designs::arrays((int) $d['id']));
			$cards[] = ['d' => $d, 'calc' => $calc, 'flags' => Designs::priceFlags($lines, $d)];
		}
		$contacts = $o['customer_id'] ? Db::all("SELECT * FROM contacts WHERE owner_type = 'organization' AND owner_id = ? AND is_active = 1 ORDER BY is_primary DESC, name", [$o['customer_id']]) : [];
		$otherOpps = $o['customer_id'] ? Db::all('SELECT id, name, status FROM presales_opportunities WHERE customer_id = ? AND id <> ? ORDER BY updated_at DESC', [$o['customer_id'], $id]) : [];
		$customerProjects = $o['customer_id'] ? Db::all('SELECT id, project_number, name FROM projects WHERE customer_id = ? ORDER BY project_number DESC', [$o['customer_id']]) : [];
		View::render('presales/show', [
			'title' => $o['name'], 'o' => $o, 'cards' => $cards, 'contacts' => $contacts, 'otherOpps' => $otherOpps,
			'customerProjects' => $customerProjects,
			'activity' => Activity::feed('presales', $id),
		]);
	}

	/** Open / Sold / Lost. Sold names the build sheet sold; Lost takes an optional reason. */
	public function status(int $id): void
	{
		$o = $this->find($id);
		$status = (string) ($_POST['status'] ?? '');
		if (!isset(Presales::STATUSES[$status])) {
			redirect('/presales/' . $id);
		}
		$data = ['status' => $status, 'status_at' => now_utc(), 'updated_at' => now_utc()];
		$msg = 'Marked ' . Presales::STATUSES[$status];
		if ($status === 'lost') {
			$data['lost_reason'] = trim((string) ($_POST['lost_reason'] ?? '')) ?: null;
			if ($data['lost_reason']) {
				$msg .= ': ' . $data['lost_reason'];
			}
		} elseif ($status === 'sold') {
			$did = (int) ($_POST['sold_design_id'] ?? 0);
			$design = Db::one('SELECT * FROM presales_designs WHERE id = ? AND opportunity_id = ?', [$did, $id]);
			if (!$design) {
				flash('error', 'Pick the build sheet that sold.');
				redirect('/presales/' . $id);
			}
			$data['sold_design_id'] = $did;
			$data['lost_reason'] = null;
			$msg .= ' with "' . $design['name'] . '"';
		} else {
			$data['lost_reason'] = null;
			$data['sold_design_id'] = null;
		}
		Db::update('presales_opportunities', $id, $data);
		Activity::event('presales', $id, $msg);
		flash('success', $msg . '.');
		redirect('/presales/' . $id);
	}

	public function comment(int $id): void
	{
		$this->find($id);
		$body = trim((string) ($_POST['body'] ?? ''));
		if ($body !== '') {
			Activity::comment('presales', $id, $body);
			Db::update('presales_opportunities', $id, ['updated_at' => now_utc()]);
		}
		redirect('/presales/' . $id . '#log');
	}

	private function input(?int $id): array
	{
		$name = trim((string) ($_POST['name'] ?? ''));
		$customerId = null;
		if (($_POST['customer_id'] ?? '') === 'new') {
			$cname = trim((string) ($_POST['new_customer_name'] ?? ''));
			if ($cname === '') {
				throw new \InvalidArgumentException('Enter the new customer\'s name.');
			}
			$dupe = Db::value("SELECT id FROM organizations WHERE type = 'customer' AND name = ? COLLATE NOCASE", [$cname]);
			$customerId = $dupe ?: Db::insert('organizations', [
				'type' => 'customer', 'name' => $cname,
				'phone' => trim((string) ($_POST['new_customer_phone'] ?? '')) ?: null,
				'email' => trim((string) ($_POST['new_customer_email'] ?? '')) ?: null,
				'created_by' => Auth::id(),
			]);
			if (!$dupe) {
				Activity::event('organization', (int) $customerId, 'Customer added from Pre-Sales');
			}
		} elseif ((int) ($_POST['customer_id'] ?? 0)) {
			$customerId = (int) Db::value("SELECT id FROM organizations WHERE id = ? AND type = 'customer'", [(int) $_POST['customer_id']]) ?: null;
		}
		// The name defaults to the customer's name
		if ($name === '' && $customerId) {
			$name = (string) Db::value('SELECT name FROM organizations WHERE id = ?', [(int) $customerId]);
		}
		if ($name === '') {
			throw new \InvalidArgumentException('Pick the customer or type a name for the opportunity.');
		}
		$drive = trim((string) ($_POST['drive_url'] ?? ''));
		if ($err = link_error('The Drive folder link', $drive)) {
			throw new \InvalidArgumentException($err);
		}
		$usage = Projects::parseNumber($_POST['annual_usage_kwh'] ?? '');
		if ($usage === null && trim((string) ($_POST['annual_usage_kwh'] ?? '')) !== '') {
			throw new \InvalidArgumentException('Last 12 months usage must be a number of kWh.');
		}
		$usage = $usage === null ? null : (int) round($usage);
		$sales = (int) ($_POST['salesperson_id'] ?? 0);
		$type = (string) ($_POST['customer_type'] ?? '');
		return [
			'name' => $name,
			'customer_id' => $customerId ? (int) $customerId : null,
			'salesperson_id' => $sales && Db::value('SELECT 1 FROM users WHERE id = ?', [$sales]) ? $sales : null,
			'customer_type' => array_key_exists($type, Projects::CUSTOMER_TYPES) ? $type : null,
			'is_agricultural' => empty($_POST['is_agricultural']) ? 0 : 1,
			'site_street' => trim((string) ($_POST['site_street'] ?? '')) ?: null,
			'site_city' => trim((string) ($_POST['site_city'] ?? '')) ?: null,
			'site_state' => in_array($_POST['site_state'] ?? '', ['PA', 'MD', 'DE'], true) ? $_POST['site_state'] : 'PA',
			'site_zip' => trim((string) ($_POST['site_zip'] ?? '')) ?: null,
			'drive_url' => $drive ?: null,
			'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
			'utility_id' => ($u = (int) ($_POST['utility_id'] ?? 0)) && Db::value('SELECT 1 FROM utilities WHERE id = ?', [$u]) ? $u : null,
			'bill_name' => trim((string) ($_POST['bill_name'] ?? '')) ?: null,
			'utility_account' => trim((string) ($_POST['utility_account'] ?? '')) ?: null,
			'utility_meter' => trim((string) ($_POST['utility_meter'] ?? '')) ?: null,
			'utility_tariff' => trim((string) ($_POST['utility_tariff'] ?? '')) ?: null,
			'service_address' => trim((string) ($_POST['service_address'] ?? '')) ?: null,
			'annual_usage_kwh' => $usage,
		];
	}

	private function find(int $id): array
	{
		return Db::one('SELECT * FROM presales_opportunities WHERE id = ?', [$id]) ?? $this->notFound();
	}

	private function notFound(): never
	{
		View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
		exit;
	}
}
