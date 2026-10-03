<?php
declare(strict_types=1);

namespace App;

/**
 * Loads an import.json produced by tools/import/build_import.py.
 * Everything runs in one transaction; --dry-run rolls it back after reporting.
 */
final class Importer
{
    private array $log = [];
    private array $counts = ['projects' => 0, 'skipped' => 0, 'replaced' => 0, 'customers' => 0, 'contacts' => 0,
        'municipalities' => 0, 'agencies' => 0, 'task_updates' => 0, 'task_misses' => 0];

    public function __construct(private bool $dryRun = false, private bool $replace = false)
    {
    }

    /** @return array{counts:array, log:string[]} */
    public function run(array $data): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($data['projects'] ?? [] as $p) {
                $this->project($p, $data['source'] ?? 'import');
            }
            $this->dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['counts' => $this->counts, 'log' => $this->log];
    }

    private function project(array $p, string $source): void
    {
        $label = $p['number'] . ' ' . $p['name'];
        $existing = Db::value('SELECT id FROM projects WHERE project_number = ?', [$p['number']]);
        if ($existing) {
            if (!$this->replace) {
                $this->counts['skipped']++;
                $this->log[] = "skip   $label (already exists; use --replace to overwrite)";
                return;
            }
            Db::run("DELETE FROM activity_log WHERE entity_type = 'project' AND entity_id = ?", [$existing]);
            Db::run('DELETE FROM projects WHERE id = ?', [$existing]);
            $this->counts['replaced']++;
        }

        $customerId = $this->customer($p);
        $muniId = $p['municipality'] ? $this->municipality($p['municipality']) : null;
        $agencyId = $p['inspection_agency'] ? $this->org('agency', $p['inspection_agency']) : null;
        if ($agencyId && $muniId && !Db::value('SELECT inspection_mode FROM municipalities WHERE id = ?', [$muniId])) {
            Db::update('municipalities', $muniId, ['inspection_mode' => 'agency', 'inspection_org_id' => $agencyId]);
        }
        $eq = $p['equipment'];
        $installType = $p['install_type'];
        if (!$installType && $eq['racking'] && preg_match('/tracker|sun ?action|mechatron/i', $eq['racking'])) {
            $installType = 'tracker';
        }
        $muni = $muniId ? Db::one('SELECT * FROM municipalities WHERE id = ?', [$muniId]) : null;

        $id = Db::insert('projects', [
            'project_number' => $p['number'],
            'name' => $p['name'],
            'customer_id' => $customerId,
            'salesperson_id' => $p['salesperson_email'] ? Db::value('SELECT id FROM users WHERE email = ?', [$p['salesperson_email']]) : null,
            'customer_type' => $p['customer_type'],
            'is_agricultural' => $p['is_agricultural'] ? 1 : 0,
            'install_type' => $installType,
            'racking' => $eq['racking'],
            'has_pv' => $p['has_pv'] ? 1 : 0,
            'has_batteries' => $p['has_batteries'] ? 1 : 0,
            'tax_exempt' => $p['tax_exempt'],
            'contract_price_cents' => $p['price'] !== null ? (int) round($p['price'] * 100) : null,
            'est_annual_kwh' => $p['annual_kwh'],
            'site_street' => $p['site']['street'] ?? null,
            'site_city' => $p['site']['city'] ?? null,
            'site_state' => $p['site']['state'] ?? ($p['municipality']['state'] ?? 'PA'),
            'site_zip' => $p['site']['zip'] ?? null,
            'utility_id' => $p['utility'] ? Db::value('SELECT id FROM utilities WHERE name = ?', [$p['utility']]) : null,
            'municipality_id' => $muniId,
            'zoning_mode' => $muni['zoning_mode'] ?? null,
            'zoning_org_id' => $muni['zoning_org_id'] ?? null,
            'plan_review_mode' => $muni['plan_review_mode'] ?? null,
            'plan_review_org_id' => $muni['plan_review_org_id'] ?? null,
            'inspection_mode' => $agencyId ? 'agency' : ($muni['inspection_mode'] ?? null),
            'inspection_org_id' => $agencyId ?: ($muni['inspection_org_id'] ?? null),
            'drive_url' => $p['drive_url'],
            'status_note' => $p['status_note'],
            'hold_state' => $p['hold_state'],
            'hold_reason' => $p['hold_reason'],
            'archived_at' => $p['archived'] ? now_utc() : null,
            'quote_number' => $p['quote_number'] ?? null,
        ]);

        foreach (['modules' => 'project_modules', 'inverters' => 'project_inverters', 'batteries' => 'project_batteries'] as $k => $table) {
            foreach ($eq[$k] as $i => $row) {
                $row = array_intersect_key($row, array_flip(['qty', 'watts', 'ac_kw', 'kwh', 'kw', 'description']));
                Db::insert($table, $row + ['project_id' => $id, 'sort_order' => $i]);
            }
        }
        foreach ($p['funding'] as $f) {
            $fid = Db::value('SELECT id FROM funding_sources WHERE name = ?', [$f]);
            if ($fid) {
                Db::insert('project_funding', ['project_id' => $id, 'funding_source_id' => $fid]);
            }
        }

        Tasks::instantiate($id, (bool) $p['has_pv'], (bool) $p['has_batteries']);
        if (!empty($p['payments'])) {
            $this->replacePayments($id, $p['payments']);
        }
        if ($p['signed']) {
            Db::run("UPDATE project_tasks SET done_date = ? WHERE project_id = ? AND gate = 'start_clock'", [$p['signed'], $id]);
        }
        foreach ($p['tasks'] as $path => $vals) {
            $this->applyTask($id, $path, $vals, $label);
        }

        $notes = array_merge($p['source_notes'] ?? [], array_map(static fn ($f) => 'Review: ' . $f, $p['flags'] ?? []));
        $body = "Imported from $source." . ($p['equipment_text'] ? "\nSpreadsheet equipment: " . $p['equipment_text'] : '')
            . ($notes ? "\n" . implode("\n", array_map(static fn ($n) => '- ' . $n, $notes)) : '');
        Activity::event('project', $id, $body, null);

        $this->counts['projects']++;
        $this->log[] = 'import ' . $label . ($p['flags'] ? '  (' . count($p['flags']) . ' review notes)' : '');
    }

    private function customer(array $p): int
    {
        $id = $this->org('customer', $p['customer'], $p['site'] ?? null);
        $c = $p['contact'] ?? null;
        if ($c && ($c['name'] || $c['email'])) {
            $dupe = Db::value("SELECT id FROM contacts WHERE owner_type = 'organization' AND owner_id = ? AND (name = ? COLLATE NOCASE OR (email IS NOT NULL AND email = ? COLLATE NOCASE))",
                [$id, $c['name'] ?? '', $c['email'] ?? '']);
            if (!$dupe) {
                $primary = (int) Db::value("SELECT COUNT(*) FROM contacts WHERE owner_type = 'organization' AND owner_id = ?", [$id]) === 0;
                Db::insert('contacts', ['owner_type' => 'organization', 'owner_id' => $id, 'name' => $c['name'] ?: $c['email'],
                    'role' => 'Contact', 'notes' => 'From signed contract', 'phone' => $c['phone'], 'email' => $c['email'], 'is_primary' => $primary ? 1 : 0]);
                $this->counts['contacts']++;
            }
        }
        return $id;
    }

    private function org(string $type, string $name, ?array $address = null): int
    {
        $id = Db::value('SELECT id FROM organizations WHERE type = ? AND name = ? COLLATE NOCASE', [$type, $name]);
        if ($id) {
            if ($address && !Db::value('SELECT street FROM organizations WHERE id = ?', [$id])) {
                Db::update('organizations', (int) $id, ['street' => $address['street'], 'city' => $address['city'], 'state' => $address['state'], 'zip' => $address['zip']]);
            }
            return (int) $id;
        }
        $id = Db::insert('organizations', ['type' => $type, 'name' => $name,
            'street' => $address['street'] ?? null, 'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null, 'zip' => $address['zip'] ?? null]);
        Activity::event('organization', $id, "Created by import", null);
        $this->counts[$type === 'customer' ? 'customers' : 'agencies']++;
        return $id;
    }

    private function municipality(array $m): ?int
    {
        $county = Db::value('SELECT id FROM counties WHERE name = ? COLLATE NOCASE AND state_code = ?', [$m['county'], $m['state']]);
        if (!$county) {
            $this->log[] = "  ! unknown county {$m['county']}, {$m['state']} for {$m['name']}";
            return null;
        }
        $r = Municipalities::findOrCreate($m['name'], (int) $county);
        if ($r['created']) {
            $this->counts['municipalities']++;
        }
        return $r['id'];
    }

    private function replacePayments(int $projectId, array $labels): void
    {
        $owner = Db::value("SELECT owner_id FROM project_tasks WHERE project_id = ? AND phase = 'payments' LIMIT 1", [$projectId]);
        Db::run("DELETE FROM project_tasks WHERE project_id = ? AND phase = 'payments'", [$projectId]);
        foreach (array_values($labels) as $i => $label) {
            Db::insert('project_tasks', ['project_id' => $projectId, 'name' => $label, 'phase' => 'payments',
                'sort_order' => ($i + 1) * 10, 'needed' => 1, 'owner_id' => $owner]);
        }
    }

    private function applyTask(int $projectId, string $path, array $vals, string $label): void
    {
        [$parent, $child] = array_pad(explode('>', $path, 2), 2, null);
        if ($child === null) {
            $row = Db::one('SELECT * FROM project_tasks WHERE project_id = ? AND parent_id IS NULL AND name = ?', [$projectId, $parent]);
            if (!$row && $parent === 'Deposit received') {
                // contract payment schedule replaced the template: use the first deposit-like line
                $row = Db::one("SELECT * FROM project_tasks WHERE project_id = ? AND phase = 'payments' ORDER BY (name LIKE '%deposit%') DESC, sort_order LIMIT 1", [$projectId]);
            }
        } else {
            $row = Db::one('SELECT t.* FROM project_tasks t JOIN project_tasks p ON p.id = t.parent_id WHERE t.project_id = ? AND p.name = ? AND t.name = ?', [$projectId, $parent, $child]);
        }
        if (!$row) {
            $this->counts['task_misses']++;
            $this->log[] = "  ! $label: no task \"$path\" (skipped " . json_encode($vals) . ')';
            return;
        }
        $upd = [];
        if (array_key_exists('needed', $vals)) {
            $upd['needed'] = $vals['needed'] === null ? null : (int) $vals['needed'];
        }
        if (!empty($vals['done'])) {
            $upd['done_date'] = $vals['done'];
        }
        if (!empty($vals['note'])) {
            $upd['note'] = $vals['note'];
            $upd['note_updated_at'] = now_utc();
        }
        if ($upd) {
            Db::update('project_tasks', (int) $row['id'], $upd);
            $this->counts['task_updates']++;
        }
    }
}
