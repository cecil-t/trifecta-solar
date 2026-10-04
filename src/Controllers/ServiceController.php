<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Projects;
use App\Service;
use App\View;

/** Service tickets and their visits. Anyone can add and edit. */
final class ServiceController
{
	private const TRACKED = [
		'ticket_number' => 'Service #', 'opened_on' => 'Opened', 'customer_id' => 'Customer', 'project_id' => 'Original project',
		'site_street' => 'Site street', 'site_city' => 'City', 'site_state' => 'State', 'site_zip' => 'ZIP',
		'description' => 'Description', 'source' => 'Came from', 'trifecta_install' => 'Trifecta install',
		'coverage' => 'Coverage', 'owner_id' => 'Owner', 'scheduled_on' => 'Scheduled', 'completed_on' => 'Completed',
		'drive_url' => 'Drive folder', 'monitoring_url' => 'Monitoring portal', 'billing_note' => 'Billing note', 'bill_amount_cents' => 'Amount billed',
		'invoiced' => 'Invoiced', 'invoiced_on' => 'Invoice date', 'invoice_number' => 'Invoice #',
	];

	// ------------------------------------------------------------------ list

	public function index(): void
	{
		$tab = $_GET['tab'] ?? 'active';
		$q = trim((string) ($_GET['q'] ?? ''));
		if ($q !== '') {
			$tab = 'all'; // a search always looks across every ticket
		}
		$rows = Db::all(
			"SELECT t.*, c.name AS customer_name, p.project_number, u.initials AS owner_initials, u.name AS owner_name,
					(SELECT COALESCE(SUM(trips), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS trips,
					(SELECT COALESCE(SUM(man_hours), 0) FROM service_visits v WHERE v.ticket_id = t.id) AS man_hours
			 FROM service_tickets t
			 LEFT JOIN organizations c ON c.id = t.customer_id
			 LEFT JOIN projects p ON p.id = t.project_id
			 LEFT JOIN users u ON u.id = t.owner_id
			 WHERE 1 = 1" . ($q !== '' ? ' AND (t.ticket_number LIKE ? OR c.name LIKE ? OR t.description LIKE ? OR t.site_city LIKE ? OR t.site_street LIKE ?)' : '') . "
			 ORDER BY t.ticket_number DESC",
			$q !== '' ? array_fill(0, 5, '%' . $q . '%') : []
		);
		$counts = ['active' => 0, 'to_invoice' => 0, 'done' => 0, 'all' => 0];
		$searching = $q !== '';
		$out = [];
		foreach ($rows as $t) {
			$t['status'] = Service::status($t);
			$active = in_array($t['status'], ['open', 'scheduled'], true);
			$counts['all']++;
			$counts[$active ? 'active' : $t['status']]++;
			$keep = match ($tab) {
				'all' => true,
				'active' => $active,
				default => $t['status'] === $tab,
			};
			if ($keep) {
				$out[] = $t;
			}
		}
		// Remember this list (tab, search) so a ticket's back link returns to it.
		$_SESSION['service_list'] = '/service' . (($qs = (string) ($_SERVER['QUERY_STRING'] ?? '')) !== '' ? '?' . $qs : '');

		View::render('service/index', ['title' => 'Service', 'rows' => $out, 'counts' => $counts, 'tab' => $tab, 'q' => $q, 'searching' => $searching]);
	}

	// ------------------------------------------------------------------ create / edit

	public function create(): void
	{
		$t = ['ticket_number' => Service::nextNumber(), 'opened_on' => date('Y-m-d'), 'site_state' => 'PA', 'owner_id' => Auth::id(), 'invoiced' => 0];
		if ($pid = (int) ($_GET['project'] ?? 0)) {
			$p = Projects::find($pid);
			if ($p) {
				$t += ['project_id' => $pid, 'customer_id' => $p['customer_id'], 'trifecta_install' => 1,
					'site_street' => $p['site_street'], 'site_city' => $p['site_city'], 'site_zip' => $p['site_zip']];
				$t['site_state'] = $p['site_state'] ?: 'PA';
			}
		}
		$this->form($t, null);
	}

	public function edit(int $id): void
	{
		$this->form($this->find($id), $id);
	}

	private function form(array $t, ?int $id): void
	{
		if ($old = $_SESSION['old_service'] ?? null) {
			$t = array_merge($t, $old);
			unset($_SESSION['old_service']);
		}
		View::render('service/form', [
			'title' => $id ? 'Edit ' . $t['ticket_number'] : 'New service ticket',
			't' => $t, 'id' => $id,
			'users' => Projects::users(),
			'customers' => Projects::orgs('customer'),
			'projects' => Service::projects(),
		]);
	}

	public function store(): void
	{
		try {
			$id = Db::transaction(function () {
				$data = $this->input(null);
				$data['created_by'] = Auth::id();
				$id = Db::insert('service_tickets', $data);
				Activity::event('service', $id, 'Opened service ticket ' . $data['ticket_number']);
				return $id;
			});
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_service'] = $_POST;
			flash('error', $e->getMessage());
			redirect('/service/new');
		}
		flash('success', 'Service ticket opened. Add visits below as they happen.');
		redirect('/service/' . $id);
	}

	public function update(int $id): void
	{
		$before = $this->find($id);
		try {
			Db::transaction(function () use ($id, $before) {
				$data = $this->input($id);
				Db::update('service_tickets', $id, $data + ['updated_at' => now_utc()]);
				Activity::changes('service', $id, $before, $data, self::TRACKED, $this->formatters());
			});
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_service'] = $_POST;
			flash('error', $e->getMessage());
			redirect('/service/' . $id . '/edit');
		}
		flash('success', 'Saved.');
		redirect('/service/' . $id);
	}

	/** Quick billing actions from the ticket page: mark complete, mark invoiced. */
	public function quick(int $id): void
	{
		$before = $this->find($id);
		$data = [];
		if (($_POST['action'] ?? '') === 'complete') {
			$data['completed_on'] = Projects::parseDate($_POST['completed_on'] ?? '') ?? date('Y-m-d');
		}
		if (($_POST['action'] ?? '') === 'invoiced') {
			$data['invoiced'] = 1;
			$data['invoiced_on'] = Projects::parseDate($_POST['invoiced_on'] ?? '') ?? date('Y-m-d');
			$data['invoice_number'] = trim((string) ($_POST['invoice_number'] ?? '')) ?: $before['invoice_number'];
			if (($amt = Projects::parseMoney($_POST['bill_amount'] ?? '')) !== null) {
				$data['bill_amount_cents'] = $amt;
			}
		}
		if ($data) {
			Db::update('service_tickets', $id, $data + ['updated_at' => now_utc()]);
			Activity::changes('service', $id, $before, $data, self::TRACKED, $this->formatters());
			flash('success', 'Saved.');
		}
		redirect('/service/' . $id);
	}

	private function input(?int $id): array
	{
		$s = static fn (string $k) => ($v = trim((string) ($_POST[$k] ?? ''))) === '' ? null : $v;
		$enum = static fn (string $k, array $allowed) => in_array($_POST[$k] ?? '', $allowed, true) ? $_POST[$k] : null;
		$int = static fn (string $k) => ($v = (int) ($_POST[$k] ?? 0)) > 0 ? $v : null;

		$data = [
			'ticket_number' => strtoupper((string) $s('ticket_number')),
			'opened_on' => Projects::parseDate($_POST['opened_on'] ?? ''),
			'customer_id' => $int('customer_id'),
			'project_id' => $int('project_id'),
			'site_street' => $s('site_street'), 'site_city' => $s('site_city'),
			'site_state' => strtoupper((string) $s('site_state')) ?: null, 'site_zip' => $s('site_zip'),
			'description' => (string) $s('description'),
			'source' => $enum('source', array_keys(Service::SOURCES)),
			'trifecta_install' => ($_POST['trifecta_install'] ?? '') === '' ? null : (int) ((string) $_POST['trifecta_install'] === '1'),
			'coverage' => $enum('coverage', array_keys(Service::COVERAGE)),
			'owner_id' => $int('owner_id'),
			'scheduled_on' => Projects::parseDate($_POST['scheduled_on'] ?? ''),
			'completed_on' => Projects::parseDate($_POST['completed_on'] ?? ''),
			'drive_url' => $s('drive_url'),
			'monitoring_url' => $s('monitoring_url'),
			'billing_note' => $s('billing_note'),
			'bill_amount_cents' => Projects::parseMoney($_POST['bill_amount'] ?? ''),
			'invoiced' => empty($_POST['invoiced']) ? 0 : 1,
			'invoiced_on' => Projects::parseDate($_POST['invoiced_on'] ?? ''),
			'invoice_number' => $s('invoice_number'),
		];
		foreach (['opened_on', 'scheduled_on', 'completed_on', 'invoiced_on'] as $k) {
			if (trim((string) ($_POST[$k] ?? '')) !== '' && $data[$k] === null) {
				throw new \InvalidArgumentException('Use a date like ' . date('m/d/Y') . ' for ' . strtolower(self::TRACKED[$k]) . '.');
			}
		}
		if (!preg_match('/^S\d{5,}$/', $data['ticket_number'])) {
			throw new \InvalidArgumentException('Service # should look like ' . Service::nextNumber() . '.');
		}
		if (Db::value('SELECT id FROM service_tickets WHERE ticket_number = ? AND id <> ?', [$data['ticket_number'], $id ?? 0])) {
			throw new \InvalidArgumentException('Service # ' . $data['ticket_number'] . ' is already used.');
		}
		if ($data['opened_on'] === null) {
			throw new \InvalidArgumentException('Enter the date the request came in.');
		}
		if ($data['description'] === '') {
			throw new \InvalidArgumentException('Describe the problem or request.');
		}
		foreach (['drive_url' => 'The Drive folder link', 'monitoring_url' => 'The monitoring portal link'] as $k => $label) {
			if ($error = link_error($label, $data[$k])) {
				throw new \InvalidArgumentException($error);
			}
		}
		if ($data['invoiced_on'] || $data['invoice_number']) {
			$data['invoiced'] = 1;
		}

		// New customer typed inline
		if (($_POST['customer_id'] ?? '') === 'new') {
			$name = trim((string) ($_POST['new_customer_name'] ?? ''));
			if ($name === '') {
				throw new \InvalidArgumentException('Enter the new customer\'s name.');
			}
			$dupe = Db::value("SELECT id FROM organizations WHERE type = 'customer' AND name = ? COLLATE NOCASE", [$name]);
			$data['customer_id'] = $dupe ?: Db::insert('organizations', [
				'type' => 'customer', 'name' => $name,
				'phone' => trim((string) ($_POST['new_customer_phone'] ?? '')) ?: null,
				'email' => trim((string) ($_POST['new_customer_email'] ?? '')) ?: null,
				'street' => $data['site_street'], 'city' => $data['site_city'], 'state' => $data['site_state'], 'zip' => $data['site_zip'],
				'created_by' => Auth::id(),
			]);
			if (!$dupe) {
				Activity::event('organization', (int) $data['customer_id'], 'Customer added from a service ticket');
			}
		}
		if (!$data['customer_id']) {
			throw new \InvalidArgumentException('Choose the customer, or add a new one.');
		}
		// A linked project means it's our install, and fills a blank site address
		if ($data['project_id']) {
			$data['trifecta_install'] = 1;
			if (!$data['site_street'] && ($p = Projects::find($data['project_id']))) {
				foreach (['street', 'city', 'state', 'zip'] as $f) {
					$data['site_' . $f] = $p['site_' . $f];
				}
			}
		}
		return $data;
	}

	private function formatters(): array
	{
		$date = static fn ($v) => fmt_date($v);
		return [
			'customer_id' => static fn ($v) => (string) Db::value('SELECT name FROM organizations WHERE id = ?', [$v]),
			'project_id' => static fn ($v) => (string) Db::value("SELECT project_number || ' ' || name FROM projects WHERE id = ?", [$v]),
			'owner_id' => static fn ($v) => (string) Db::value('SELECT name FROM users WHERE id = ?', [$v]),
			'source' => static fn ($v) => Service::SOURCES[$v] ?? $v,
			'coverage' => static fn ($v) => Service::COVERAGE[$v] ?? $v,
			'trifecta_install' => static fn ($v) => (int) $v ? 'Yes' : 'No',
			'invoiced' => static fn ($v) => (int) $v ? 'Yes' : 'No',
			'bill_amount_cents' => static fn ($v) => Projects::money((int) $v),
			'opened_on' => $date, 'scheduled_on' => $date, 'completed_on' => $date, 'invoiced_on' => $date,
		];
	}

	// ------------------------------------------------------------------ detail

	public function show(int $id): void
	{
		$t = $this->find($id);
		$commentsOnly = ($_GET['log'] ?? '') === 'comments';
		$contacts = Db::all("SELECT * FROM contacts WHERE owner_type = 'organization' AND owner_id = ? AND is_active = 1 ORDER BY is_primary DESC, name", [$t['customer_id'] ?? 0]);
		$history = $t['customer_id'] ? Db::all(
			'SELECT id, ticket_number, opened_on, description FROM service_tickets WHERE customer_id = ? AND id <> ? ORDER BY opened_on DESC LIMIT 10',
			[$t['customer_id'], $id]
		) : [];
		View::render('service/show', [
			'title' => $t['ticket_number'] . ' ' . ($t['customer_name'] ?? ''),
			'listUrl' => ($_SESSION['service_list'] ?? '/service') . '#ticket-' . $id,
			't' => $t, 'visits' => Service::visits($id), 'todos' => \App\Todos::forService($id), 'contacts' => $contacts, 'history' => $history,
			'activity' => Activity::feed('service', $id, $commentsOnly), 'commentsOnly' => $commentsOnly,
		]);
	}

	public function comment(int $id): void
	{
		$this->find($id);
		$body = trim((string) ($_POST['body'] ?? ''));
		if ($body !== '') {
			Activity::comment('service', $id, $body);
			Db::update('service_tickets', $id, ['updated_at' => now_utc()]);
		}
		redirect('/service/' . $id . '#log');
	}

	// ------------------------------------------------------------------ visits

	public function addVisit(int $id): void
	{
		$this->find($id);
		try {
			$v = $this->visitInput();
		} catch (\InvalidArgumentException $e) {
			flash('error', $e->getMessage());
			redirect('/service/' . $id . '#visits');
		}
		Db::insert('service_visits', $v + ['ticket_id' => $id, 'created_by' => Auth::id()]);
		Db::update('service_tickets', $id, ['updated_at' => now_utc()]);
		Activity::event('service', $id, 'Added visit: ' . $this->visitSummary($v));
		redirect('/service/' . $id . '#visits');
	}

	public function updateVisit(int $id, int $visitId): void
	{
		$before = $this->visit($id, $visitId);
		try {
			$v = $this->visitInput();
		} catch (\InvalidArgumentException $e) {
			flash('error', $e->getMessage());
			redirect('/service/' . $id . '#visits');
		}
		Db::update('service_visits', $visitId, $v + ['updated_at' => now_utc()]);
		if ($this->visitSummary($before) !== $this->visitSummary($v)) {
			Activity::event('service', $id, 'Changed visit from "' . $this->visitSummary($before) . '" to "' . $this->visitSummary($v) . '"');
		}
		redirect('/service/' . $id . '#visits');
	}

	public function deleteVisit(int $id, int $visitId): void
	{
		$before = $this->visit($id, $visitId);
		Db::run('DELETE FROM service_visits WHERE id = ?', [$visitId]);
		Activity::event('service', $id, 'Removed visit: ' . $this->visitSummary($before));
		redirect('/service/' . $id . '#visits');
	}

	private function visitInput(): array
	{
		$date = Projects::parseDate($_POST['visit_date'] ?? '');
		if (trim((string) ($_POST['visit_date'] ?? '')) !== '' && !$date) {
			throw new \InvalidArgumentException('Use a date like ' . date('m/d/Y') . ' for the visit.');
		}
		$hours = Projects::parseNumber($_POST['man_hours'] ?? '');
		if ($hours !== null && ($hours < 0 || $hours > 500)) {
			throw new \InvalidArgumentException('Man-hours should be between 0 and 500.');
		}
		$trips = (string) ($_POST['trips'] ?? '') === '' ? 1 : max(0, (int) $_POST['trips']);
		return [
			'visit_date' => $date,
			'crew' => trim((string) ($_POST['crew'] ?? '')) ?: null,
			'man_hours' => $hours,
			'trips' => $trips,
			'note' => trim((string) ($_POST['note'] ?? '')) ?: null,
		];
	}

	private function visitSummary(array $v): string
	{
		$parts = [$v['visit_date'] ? fmt_date($v['visit_date']) : 'no date'];
		if ($v['man_hours'] !== null) {
			$parts[] = Service::hours((float) $v['man_hours']) . ' man-hours';
		}
		$parts[] = (int) $v['trips'] . ' trip' . ((int) $v['trips'] === 1 ? '' : 's');
		if ($v['crew']) {
			$parts[] = $v['crew'];
		}
		return implode(', ', $parts);
	}

	private function visit(int $id, int $visitId): array
	{
		$v = Db::one('SELECT * FROM service_visits WHERE id = ? AND ticket_id = ?', [$visitId, $id]);
		if (!$v) {
			redirect('/service/' . $id);
		}
		return $v;
	}

	private function find(int $id): array
	{
		$t = Service::find($id);
		if (!$t) {
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			exit;
		}
		return $t;
	}
}
