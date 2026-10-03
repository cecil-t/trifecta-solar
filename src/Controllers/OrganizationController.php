<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\View;

/**
 * Customers (anyone can add/edit) and third parties: agencies, designers, contractors
 * (admins only). Both share the organizations table and contacts.
 */
final class OrganizationController
{
    public const TYPES = [
        'customer' => 'Customer', 'agency' => 'Code / inspection agency', 'designer' => 'Designer',
        'contractor' => 'Contractor', 'other' => 'Other',
    ];
    private const TRACKED = ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'street' => 'Street',
        'city' => 'City', 'state' => 'State', 'zip' => 'ZIP', 'notes' => 'Notes', 'is_active' => 'Active', 'type' => 'Type'];

    public static function canManage(string $type): bool
    {
        return $type === 'customer' || Auth::isAdmin();
    }

    public function customers(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $rows = Db::all(
            "SELECT o.*, (SELECT COUNT(*) FROM projects p WHERE p.customer_id = o.id) AS project_count,
                    (SELECT COUNT(*) FROM contacts c WHERE c.owner_type = 'organization' AND c.owner_id = o.id AND c.is_active = 1) AS contact_count
             FROM organizations o WHERE o.type = 'customer'" . ($q !== '' ? ' AND o.name LIKE ?' : '') . ' ORDER BY o.name',
            $q !== '' ? ['%' . $q . '%'] : []
        );
        View::render('organizations/customers', ['title' => 'Customers', 'rows' => $rows, 'q' => $q]);
    }

    public function directory(): void
    {
        $rows = Db::all(
            "SELECT o.*, (SELECT COUNT(*) FROM contacts c WHERE c.owner_type = 'organization' AND c.owner_id = o.id AND c.is_active = 1) AS contact_count
             FROM organizations o WHERE o.type <> 'customer' ORDER BY o.type, o.name"
        );
        View::render('organizations/directory', ['title' => 'Directory', 'rows' => $rows]);
    }

    public function create(): void
    {
        $type = $_GET['type'] ?? 'customer';
        if (!isset(self::TYPES[$type]) || !self::canManage($type)) {
            redirect('/customers');
        }
        View::render('organizations/form', ['title' => 'Add ' . strtolower(self::TYPES[$type]), 'o' => ['type' => $type, 'state' => 'PA', 'is_active' => 1], 'contacts' => [], 'projects' => [], 'activity' => []]);
    }

    public function store(): void
    {
        $data = $this->input();
        if (!self::canManage($data['type'])) {
            redirect('/');
        }
        if ($err = $this->validate($data)) {
            flash('error', $err);
            redirect('/organizations/new?type=' . urlencode($data['type']));
        }
        $id = Db::insert('organizations', $data + ['created_by' => Auth::id()]);
        Activity::event('organization', $id, 'Added ' . self::TYPES[$data['type']] . ' ' . $data['name']);
        flash('success', $data['name'] . ' added.');
        redirect('/organizations/' . $id);
    }

    public function show(int $id): void
    {
        $o = $this->find($id);
        $contacts = Db::all("SELECT * FROM contacts WHERE owner_type = 'organization' AND owner_id = ? ORDER BY is_active DESC, is_primary DESC, name", [$id]);
        $projects = $o['type'] === 'customer'
            ? Db::all('SELECT id, project_number, name FROM projects WHERE customer_id = ? ORDER BY project_number', [$id])
            : Db::all('SELECT DISTINCT id, project_number, name FROM projects WHERE ? IN (zoning_org_id, plan_review_org_id, inspection_org_id, designer_org_id, installer_org_id) ORDER BY project_number', [$id]);
        View::render('organizations/form', [
            'title' => $o['name'], 'o' => $o, 'contacts' => $contacts, 'projects' => $projects,
            'activity' => Activity::feed('organization', $id), 'canManage' => self::canManage($o['type']),
        ]);
    }

    public function update(int $id): void
    {
        $before = $this->find($id);
        if (!self::canManage($before['type'])) {
            redirect('/organizations/' . $id);
        }
        $data = $this->input();
        if ($before['type'] === 'customer' || !Auth::isAdmin()) {
            $data['type'] = $before['type']; // customers stay customers
        }
        if ($err = $this->validate($data, $id)) {
            flash('error', $err);
            redirect('/organizations/' . $id);
        }
        Db::update('organizations', $id, $data + ['updated_at' => now_utc()]);
        Activity::changes('organization', $id, $before, $data, self::TRACKED, [
            'is_active' => static fn ($v) => $v ? 'Yes' : 'No', 'type' => static fn ($v) => self::TYPES[$v] ?? $v,
        ]);
        flash('success', 'Saved.');
        redirect('/organizations/' . $id);
    }

    private function input(): array
    {
        $s = static fn (string $k) => ($v = trim((string) ($_POST[$k] ?? ''))) === '' ? null : $v;
        $type = $_POST['type'] ?? 'customer';
        return [
            'type' => isset(self::TYPES[$type]) ? $type : 'customer',
            'name' => (string) $s('name'), 'phone' => $s('phone'), 'email' => $s('email'),
            'street' => $s('street'), 'city' => $s('city'), 'state' => strtoupper((string) $s('state')) ?: null, 'zip' => $s('zip'),
            'notes' => $s('notes'), 'is_active' => empty($_POST['is_active']) ? 0 : 1,
        ];
    }

    private function validate(array $d, int $id = 0): ?string
    {
        if ($d['name'] === '') {
            return 'Name is required.';
        }
        if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            return 'That email address does not look right.';
        }
        if (Db::value('SELECT id FROM organizations WHERE type = ? AND name = ? COLLATE NOCASE AND id <> ?', [$d['type'], $d['name'], $id])) {
            return 'A ' . strtolower(self::TYPES[$d['type']]) . ' named "' . $d['name'] . '" already exists.';
        }
        return null;
    }

    private function find(int $id): array
    {
        $o = Db::one('SELECT * FROM organizations WHERE id = ?', [$id]);
        if (!$o) {
            View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
            exit;
        }
        return $o;
    }
}
