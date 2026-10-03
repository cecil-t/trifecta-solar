<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;

/**
 * Contacts belong to an organization or a municipality. Customer contacts can be managed by
 * anyone; third-party and municipality contacts by admins only.
 */
final class ContactController
{
    private const TRACKED = ['name' => 'Name', 'role' => 'Role', 'phone' => 'Phone', 'email' => 'Email', 'notes' => 'Notes', 'is_primary' => 'Primary', 'is_active' => 'Active'];

    public function store(): void
    {
        $ownerType = $_POST['owner_type'] ?? '';
        $ownerId = (int) ($_POST['owner_id'] ?? 0);
        $back = $this->ownerUrl($ownerType, $ownerId);
        $this->authorize($ownerType, $ownerId);
        $data = $this->input();
        if ($data['name'] === '') {
            flash('error', 'Contact name is required.');
            redirect($back);
        }
        $id = Db::insert('contacts', $data + ['owner_type' => $ownerType, 'owner_id' => $ownerId]);
        Activity::event($ownerType, $ownerId, 'Added contact ' . $data['name'] . ($data['role'] ? ' (' . $data['role'] . ')' : ''));
        redirect($back . '#contact-' . $id);
    }

    public function update(int $id): void
    {
        $c = Db::one('SELECT * FROM contacts WHERE id = ?', [$id]) ?? redirect('/');
        $this->authorize($c['owner_type'], (int) $c['owner_id']);
        $data = $this->input();
        if ($data['name'] === '') {
            flash('error', 'Contact name is required.');
            redirect($this->ownerUrl($c['owner_type'], (int) $c['owner_id']));
        }
        Db::update('contacts', $id, $data + ['updated_at' => now_utc()]);
        $before = $c;
        $labels = array_map(static fn ($l) => $c['name'] . ': ' . $l, self::TRACKED);
        Activity::changes($c['owner_type'], (int) $c['owner_id'], $before, $data, $labels, [
            'is_primary' => static fn ($v) => $v ? 'Yes' : 'No', 'is_active' => static fn ($v) => $v ? 'Yes' : 'No',
        ]);
        flash('success', 'Contact saved.');
        redirect($this->ownerUrl($c['owner_type'], (int) $c['owner_id']) . '#contact-' . $id);
    }

    private function input(): array
    {
        $s = static fn (string $k) => ($v = trim((string) ($_POST[$k] ?? ''))) === '' ? null : $v;
        return [
            'name' => (string) $s('name'), 'role' => $s('role'), 'phone' => $s('phone'), 'email' => $s('email'),
            'notes' => $s('notes'), 'is_primary' => empty($_POST['is_primary']) ? 0 : 1,
            'is_active' => isset($_POST['is_active']) ? (empty($_POST['is_active']) ? 0 : 1) : 1,
        ];
    }

    private function authorize(string $ownerType, int $ownerId): void
    {
        if ($ownerType === 'organization') {
            $type = Db::value('SELECT type FROM organizations WHERE id = ?', [$ownerId]);
            if ($type && OrganizationController::canManage($type)) {
                return;
            }
        } elseif ($ownerType === 'municipality' && Auth::isAdmin() && Db::value('SELECT 1 FROM municipalities WHERE id = ?', [$ownerId])) {
            return;
        }
        flash('error', 'Only admins can change those contacts.');
        redirect($this->ownerUrl($ownerType, $ownerId));
    }

    private function ownerUrl(string $type, int $id): string
    {
        return $type === 'municipality' ? '/municipalities/' . $id : '/organizations/' . $id;
    }
}
