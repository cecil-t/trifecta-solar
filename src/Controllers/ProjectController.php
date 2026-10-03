<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Auth;
use App\Db;
use App\Municipalities;
use App\Projects;
use App\Tasks;
use App\View;

final class ProjectController
{
    /** Fields tracked in the project log, with labels. */
    private const TRACKED = [
        'project_number' => 'Project #', 'name' => 'Project name', 'customer_id' => 'Customer',
        'salesperson_id' => 'Salesperson', 'customer_type' => 'Customer type', 'is_agricultural' => 'Agricultural',
        'install_type' => 'Install type', 'racking' => 'Racking / tracker', 'has_pv' => 'Solar PV',
        'has_batteries' => 'Batteries', 'tax_exempt' => 'Tax exempt', 'contract_price_cents' => 'Contract price',
        'est_annual_kwh' => 'Est. annual kWh', 'funding_note' => 'Funding note',
        'site_street' => 'Site street', 'site_city' => 'Site city', 'site_state' => 'Site state', 'site_zip' => 'Site ZIP',
        'utility_id' => 'Utility', 'municipality_id' => 'Municipality',
        'designer_org_id' => 'Designer', 'installer_org_id' => 'Installer', 'drive_url' => 'Drive folder',
        'status_note' => 'Status note', 'hold_state' => 'Hold / cancel', 'hold_reason' => 'Hold reason',
        'archived_at' => 'Archived', 'quote_number' => 'Quote #',
    ];

    // ------------------------------------------------------------------ list

    public function index(): void
    {
        $filter = [
            'phase' => $_GET['phase'] ?? 'active',
            'sales' => (int) ($_GET['sales'] ?? 0),
            'q'     => trim((string) ($_GET['q'] ?? '')),
        ];
        $sql = 'SELECT p.*, u.initials AS sales_initials, u.name AS sales_name, m.name AS municipality_name,
                       co.name AS county_name, c.name AS customer_name
                FROM projects p
                LEFT JOIN users u ON u.id = p.salesperson_id
                LEFT JOIN municipalities m ON m.id = p.municipality_id
                LEFT JOIN counties co ON co.id = m.county_id
                LEFT JOIN organizations c ON c.id = p.customer_id
                WHERE 1 = 1';
        $params = [];
        if ($filter['sales']) {
            $sql .= ' AND p.salesperson_id = ?';
            $params[] = $filter['sales'];
        }
        if ($filter['q'] !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.project_number LIKE ? OR c.name LIKE ? OR m.name LIKE ?)';
            array_push($params, ...array_fill(0, 4, '%' . $filter['q'] . '%'));
        }
        $sql .= ' ORDER BY p.project_number';
        $projects = Db::all($sql, $params);

        $tasks = Tasks::forProjects(array_column($projects, 'id'));
        $kw = Projects::dcKwMap();
        $counts = ['active' => 0, 'pre_install' => 0, 'installation' => 0, 'closeout' => 0, 'complete' => 0, 'archived' => 0, 'cancelled' => 0, 'on_hold' => 0, 'clear' => 0, 'all' => 0];
        $rows = [];
        foreach ($projects as $p) {
            $st = Tasks::status($p, $tasks[(int) $p['id']] ?? []);
            unset($st['tree']);
            $p['status'] = $st;
            $p['dc_kw'] = $kw[(int) $p['id']] ?? null;
            $onHold = $p['hold_state'] === 'on_hold';
            $isActive = !in_array($st['phase'], ['complete', 'archived', 'cancelled'], true);
            $clearToStart = $st['phase'] === 'pre_install' && $st['clear_to_install'] && !$onHold;

            $counts['all']++;
            $counts[$st['phase']]++;
            if ($isActive) { $counts['active']++; }
            if ($onHold && $isActive) { $counts['on_hold']++; }
            if ($clearToStart) { $counts['clear']++; }

            $keep = match ($filter['phase']) {
                'all'     => true,
                'active'  => $isActive,
                'on_hold' => $onHold && $isActive,
                'clear'   => $clearToStart,
                default   => $st['phase'] === $filter['phase'],
            };
            if ($keep) {
                $rows[] = $p;
            }
        }

        View::render('projects/index', [
            'title' => 'Projects', 'projects' => $rows, 'counts' => $counts, 'filter' => $filter,
            'users' => Projects::users(),
        ]);
    }

    // ------------------------------------------------------------------ create / edit

    public function create(): void
    {
        $p = [
            'project_number' => Projects::nextNumber(), 'site_state' => 'PA', 'has_pv' => 1, 'has_batteries' => 0,
            'salesperson_id' => null, 'customer_type' => null, 'tax_exempt' => null,
        ];
        $this->form($p, null);
    }

    public function edit(int $id): void
    {
        $p = $this->find($id);
        $p['funding_ids'] = array_map('intval', array_column(Db::all('SELECT funding_source_id FROM project_funding WHERE project_id = ?', [$id]), 'funding_source_id'));
        $this->form($p + Projects::equipment($id), $id);
    }

    private function form(array $p, ?int $id): void
    {
        if ($old = $_SESSION['old_project'] ?? null) {
            $p = array_merge($p, $old);
            unset($_SESSION['old_project']);
        }
        View::render('projects/form', [
            'title' => $id ? 'Edit ' . $p['name'] : 'New project',
            'p' => $p, 'id' => $id,
            'users' => Projects::users(),
            'customers' => Projects::orgs('customer'),
            'agencies' => Projects::orgs('agency'),
            'designers' => Projects::orgs('designer'),
            'contractors' => Projects::orgs('contractor'),
            'utilities' => Projects::utilities(),
            'fundingSources' => Projects::fundingSources(),
            'municipalities' => Projects::municipalities(),
            'counties' => Projects::counties(),
            'racking' => array_column(Projects::rackingSuggestions(), 'racking'),
        ]);
    }

    public function store(): void
    {
        try {
            $id = Db::transaction(function () {
                [$data, $equipment, $funding] = $this->input(null);
                $data['created_by'] = Auth::id();
                if ($data['municipality_id']) {
                    $data = $this->applyMunicipalityDefaults($data);
                }
                $id = Db::insert('projects', $data);
                $this->saveEquipment($id, $equipment);
                $this->saveFunding($id, $funding);
                Tasks::instantiate($id, (bool) $data['has_pv'], (bool) $data['has_batteries']);

                $signed = \App\Projects::parseDate($_POST['contract_signed'] ?? '');
                if ($signed) {
                    Db::run("UPDATE project_tasks SET done_date = ? WHERE project_id = ? AND gate = 'start_clock'", [$signed, $id]);
                }
                Activity::event('project', $id, 'Created project ' . $data['project_number'] . ' ' . $data['name']);
                return $id;
            });
        } catch (\InvalidArgumentException $e) {
            $_SESSION['old_project'] = $_POST;
            flash('error', $e->getMessage());
            redirect('/projects/new');
        }
        flash('success', 'Project created with the standard task list. Adjust tasks below as needed.');
        redirect('/projects/' . $id);
    }

    public function update(int $id): void
    {
        $before = $this->find($id);
        $beforeEq = Projects::equipment($id);
        $beforeFunding = $this->fundingNames($id);
        try {
            Db::transaction(function () use ($id, $before, $beforeEq, $beforeFunding) {
                [$data, $equipment, $funding] = $this->input($id);
                if ($data['municipality_id'] && (int) $data['municipality_id'] !== (int) $before['municipality_id']) {
                    $data = $this->applyMunicipalityDefaults($data, true);
                }
                Db::update('projects', $id, $data + ['updated_at' => now_utc()]);
                Activity::changes('project', $id, $before, $data, self::TRACKED + Municipalities::providerLabels(), $this->formatters());

                $this->saveEquipment($id, $equipment);
                $afterEq = Projects::equipment($id);
                foreach (['modules' => 'Modules', 'inverters' => 'Inverters', 'batteries' => 'Batteries'] as $k => $label) {
                    Activity::changes('project', $id, [$k => $this->eqSummary($k, $beforeEq[$k])], [$k => $this->eqSummary($k, $afterEq[$k])], [$k => $label]);
                }
                $this->saveFunding($id, $funding);
                Activity::changes('project', $id, ['f' => $beforeFunding], ['f' => $this->fundingNames($id)], ['f' => 'Funding']);

                foreach (['has_pv' => 'pv', 'has_batteries' => 'storage'] as $flag => $scope) {
                    if ($data[$flag] && !(int) $before[$flag]) {
                        $added = Tasks::addScope($id, $scope);
                        if ($added) {
                            Activity::event('project', $id, 'Added ' . ($scope === 'pv' ? 'Solar PV' : 'battery') . ' tasks: ' . implode(', ', $added));
                        }
                    }
                }
            });
        } catch (\InvalidArgumentException $e) {
            $_SESSION['old_project'] = $_POST;
            flash('error', $e->getMessage());
            redirect('/projects/' . $id . '/edit');
        }
        flash('success', 'Project saved.');
        redirect('/projects/' . $id);
    }

    /** @return array{0:array,1:array,2:int[]} */
    private function input(?int $id): array
    {
        $s = static fn (string $k) => ($v = trim((string) ($_POST[$k] ?? ''))) === '' ? null : $v;
        $int = static fn (string $k) => ($v = (int) ($_POST[$k] ?? 0)) > 0 ? $v : null;
        $tri = static fn (string $k) => match ($_POST[$k] ?? '') { '1' => 1, '0' => 0, default => null };
        $enum = static fn (string $k, array $allowed) => in_array($_POST[$k] ?? '', $allowed, true) ? $_POST[$k] : null;

        $data = [
            'project_number' => $s('project_number'),
            'name' => $s('name'),
            'customer_id' => $int('customer_id'),
            'salesperson_id' => $int('salesperson_id'),
            'customer_type' => $enum('customer_type', array_keys(Projects::CUSTOMER_TYPES)),
            'is_agricultural' => empty($_POST['is_agricultural']) ? 0 : 1,
            'install_type' => $enum('install_type', array_keys(Projects::INSTALL_TYPES)),
            'racking' => $s('racking'),
            'has_pv' => empty($_POST['has_pv']) ? 0 : 1,
            'has_batteries' => empty($_POST['has_batteries']) ? 0 : 1,
            'tax_exempt' => $tri('tax_exempt'),
            'contract_price_cents' => Projects::parseMoney($_POST['contract_price'] ?? ''),
            'est_annual_kwh' => ($k = Projects::parseNumber($_POST['est_annual_kwh'] ?? '')) === null ? null : (int) round($k),
            'funding_note' => $s('funding_note'),
            'site_street' => $s('site_street'), 'site_city' => $s('site_city'),
            'site_state' => strtoupper((string) $s('site_state')) ?: null, 'site_zip' => $s('site_zip'),
            'utility_id' => $int('utility_id'),
            'municipality_id' => $int('municipality_id'),
            'designer_org_id' => $int('designer_org_id'),
            'installer_org_id' => $int('installer_org_id'),
            'drive_url' => $s('drive_url'),
            'status_note' => $s('status_note'),
            'hold_state' => $enum('hold_state', ['on_hold', 'cancelled']),
            'hold_reason' => $s('hold_reason'),
            'quote_number' => $s('quote_number'),
        ];
        // Keep the original archive timestamp when it stays checked
        $wasArchived = $id ? Db::value('SELECT archived_at FROM projects WHERE id = ?', [$id]) : null;
        $data['archived_at'] = empty($_POST['archived']) ? null : ($wasArchived ?: now_utc());
        if ($id !== null) {
            $data += Municipalities::providerInput();
        }

        if (!$data['project_number'] || !preg_match('/^[0-9A-Za-z\-]{3,12}$/', $data['project_number'])) {
            throw new \InvalidArgumentException('Project # is required (letters/numbers, e.g. 26036).');
        }
        if (!$data['name']) {
            throw new \InvalidArgumentException('Project name is required.');
        }
        if (Db::value('SELECT id FROM projects WHERE project_number = ? AND id <> ?', [$data['project_number'], $id ?? 0])) {
            throw new \InvalidArgumentException('Project # ' . $data['project_number'] . ' is already used.');
        }
        if (Db::value('SELECT id FROM projects WHERE name = ? COLLATE NOCASE AND id <> ?', [$data['name'], $id ?? 0])) {
            throw new \InvalidArgumentException('Another project is already named "' . $data['name'] . '". Project names must be unique.');
        }
        if ($data['drive_url'] && !preg_match('#^https?://#i', $data['drive_url'])) {
            throw new \InvalidArgumentException('The Drive folder link should start with https://');
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
                'created_by' => Auth::id(),
            ]);
            if (!$dupe) {
                Activity::event('organization', (int) $data['customer_id'], 'Customer added from project form');
            }
        }

        // New municipality typed inline
        if (($_POST['municipality_id'] ?? '') === 'new') {
            $m = Municipalities::findOrCreate((string) ($_POST['new_muni_name'] ?? ''), (int) ($_POST['new_muni_county_id'] ?? 0));
            $data['municipality_id'] = $m['id'];
            if (!$m['created']) {
                flash('info', $m['name'] . ' already existed in that county, so the existing entry was used.');
            }
        }

        $equipment = [
            'modules' => $this->rows('modules', ['qty', 'watts', 'description']),
            'inverters' => $this->rows('inverters', ['qty', 'ac_kw', 'description']),
            'batteries' => $this->rows('batteries', ['qty', 'kwh', 'kw', 'description']),
        ];
        $funding = array_values(array_filter(array_map('intval', (array) ($_POST['funding'] ?? []))));
        return [$data, $equipment, $funding];
    }

    private function rows(string $key, array $cols): array
    {
        $out = [];
        foreach ((array) ($_POST[$key] ?? []) as $r) {
            $qty = (int) ($r['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $row = ['qty' => $qty];
            foreach ($cols as $c) {
                if ($c === 'qty') { continue; }
                $row[$c] = $c === 'description' ? (trim((string) ($r[$c] ?? '')) ?: null) : Projects::parseNumber($r[$c] ?? '');
            }
            if ($key === 'modules' && !$row['watts']) {
                throw new \InvalidArgumentException('Each module line needs a wattage.');
            }
            if ($key === 'inverters' && !$row['ac_kw']) {
                throw new \InvalidArgumentException('Each inverter line needs its AC kW rating.');
            }
            $out[] = $row;
        }
        return $out;
    }

    private function saveEquipment(int $id, array $eq): void
    {
        foreach (['modules' => 'project_modules', 'inverters' => 'project_inverters', 'batteries' => 'project_batteries'] as $k => $table) {
            Db::run("DELETE FROM $table WHERE project_id = ?", [$id]);
            foreach ($eq[$k] as $i => $row) {
                Db::insert($table, $row + ['project_id' => $id, 'sort_order' => $i]);
            }
        }
    }

    private function saveFunding(int $id, array $ids): void
    {
        Db::run('DELETE FROM project_funding WHERE project_id = ?', [$id]);
        foreach (array_unique($ids) as $fid) {
            Db::insert('project_funding', ['project_id' => $id, 'funding_source_id' => $fid]);
        }
    }

    private function fundingNames(int $id): string
    {
        $names = array_column(Db::all('SELECT f.name FROM project_funding pf JOIN funding_sources f ON f.id = pf.funding_source_id WHERE pf.project_id = ? ORDER BY f.sort_order', [$id]), 'name');
        return implode(', ', $names);
    }

    private function eqSummary(string $type, array $rows): string
    {
        $parts = [];
        foreach ($rows as $r) {
            $parts[] = match ($type) {
                'modules'   => $r['qty'] . ' x ' . rtrim(rtrim(number_format((float) $r['watts'], 1), '0'), '.') . 'W',
                'inverters' => $r['qty'] . ' x ' . rtrim(rtrim(number_format((float) $r['ac_kw'], 2), '0'), '.') . ' kW',
                default     => $r['qty'] . ' x ' . ($r['kwh'] ? rtrim(rtrim(number_format((float) $r['kwh'], 1), '0'), '.') . ' kWh' : 'battery'),
            } . ($r['description'] ? ' ' . $r['description'] : '');
        }
        return implode('; ', $parts);
    }

    /** Copy the municipality's usual review providers onto the project. */
    private function applyMunicipalityDefaults(array $data, bool $overwrite = false): array
    {
        $m = Db::one('SELECT * FROM municipalities WHERE id = ?', [$data['municipality_id']]);
        if (!$m) {
            return $data;
        }
        foreach (Municipalities::SLOTS as $slot) {
            // On create, fill blanks. On a municipality change, take the new municipality's
            // setup only where it has one, so the user's own choices aren't wiped.
            if ($overwrite ? !empty($m[$slot['by']]) : empty($data[$slot['by']])) {
                $data[$slot['by']] = $m[$slot['by']];
                $data[$slot['org']] = $m[$slot['org']];
            }
        }
        return $data;
    }

    private function formatters(): array
    {
        $lookup = static fn (string $sql) => static fn ($v) => $v === null || $v === '' ? '' : (string) (Db::value($sql, [$v]) ?? "#$v");
        $org = $lookup('SELECT name FROM organizations WHERE id = ?');
        $yn = static fn ($v) => $v === null || $v === '' ? '' : ((int) $v ? 'Yes' : 'No');
        return Municipalities::providerFormatters() + [
            'customer_id' => $org, 'designer_org_id' => $org, 'installer_org_id' => $org,
            'salesperson_id' => $lookup('SELECT name FROM users WHERE id = ?'),
            'utility_id' => $lookup('SELECT name FROM utilities WHERE id = ?'),
            'municipality_id' => $lookup("SELECT m.name || ' (' || c.name || ' Co.)' FROM municipalities m JOIN counties c ON c.id = m.county_id WHERE m.id = ?"),
            'customer_type' => static fn ($v) => Projects::CUSTOMER_TYPES[$v] ?? (string) $v,
            'install_type' => static fn ($v) => Projects::INSTALL_TYPES[$v] ?? (string) $v,
            'is_agricultural' => $yn, 'has_pv' => $yn, 'has_batteries' => $yn, 'tax_exempt' => $yn,
            'contract_price_cents' => static fn ($v) => Projects::money($v === null ? null : (int) $v),
            'hold_state' => static fn ($v) => ['on_hold' => 'On hold', 'cancelled' => 'Cancelled'][$v] ?? 'Active',
            'archived_at' => static fn ($v) => $v ? 'Yes' : 'No',
        ];
    }

    // ------------------------------------------------------------------ detail

    public function show(int $id): void
    {
        $p = $this->find($id);
        $rows = Tasks::forProjects([$id])[$id] ?? [];
        $status = Tasks::status($p, $rows);
        $eq = Projects::equipment($id);
        $commentsOnly = ($_GET['log'] ?? '') === 'comments';
        $contacts = Db::all("SELECT * FROM contacts WHERE owner_type = 'organization' AND owner_id = ? AND is_active = 1 ORDER BY is_primary DESC, name", [$p['customer_id'] ?? 0]);
        $templateTasks = Db::all('SELECT id, name, phase FROM task_templates WHERE parent_id IS NULL AND is_active = 1 ORDER BY phase, sort_order');

        View::render('projects/show', [
            'title' => $p['project_number'] . ' ' . $p['name'],
            'p' => $p, 'status' => $status, 'tree' => $status['tree'], 'eq' => $eq,
            'totals' => Projects::totals($eq, $p['contract_price_cents'] !== null ? (int) $p['contract_price_cents'] : null),
            'funding' => $this->fundingNames($id),
            'contacts' => $contacts,
            'users' => Projects::users(),
            'templateTasks' => $templateTasks,
            'activity' => Activity::feed('project', $id, $commentsOnly),
            'commentsOnly' => $commentsOnly,
        ]);
    }

    // ------------------------------------------------------------------ tasks (JSON + forms)

    /** Inline save of one task field. Responds with JSON. */
    public function updateTask(int $id, int $taskId): void
    {
        header('Content-Type: application/json');
        $p = $this->find($id);
        $t = Db::one('SELECT * FROM project_tasks WHERE id = ? AND project_id = ?', [$taskId, $id]);
        $field = (string) ($_POST['field'] ?? '');
        $raw = $_POST['value'] ?? '';
        if (!$t || !in_array($field, ['needed', 'target_date', 'done_date', 'reference', 'owner_id', 'note', 'name'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid request']);
            return;
        }

        $value = match ($field) {
            'needed' => match ((string) $raw) { '1' => 1, '0' => 0, default => null },
            'target_date', 'done_date' => Projects::parseDate((string) $raw),
            'owner_id' => ((int) $raw) ?: null,
            default => ($v = trim((string) $raw)) === '' ? null : $v,
        };
        if (in_array($field, ['target_date', 'done_date'], true) && trim((string) $raw) !== '' && $value === null) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Use a date like 10/03/2026']);
            return;
        }
        if ($field === 'name' && $value === null) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Name cannot be blank']);
            return;
        }

        $update = [$field => $value, 'updated_at' => now_utc()];
        if ($field === 'note') {
            $update += ['note_updated_by' => Auth::id(), 'note_updated_at' => now_utc()];
        }
        Db::update('project_tasks', $taskId, $update);

        $label = $this->taskLabel($t);
        $fieldLabels = ['needed' => 'needed', 'target_date' => 'target date', 'done_date' => 'done date',
            'reference' => $t['ref_label'] ?: 'reference', 'owner_id' => 'owner', 'note' => 'note', 'name' => 'name'];
        Activity::changes('project', $id, [$field => $t[$field]], [$field => $value], [$field => $label . ': ' . $fieldLabels[$field]], [
            'needed' => static fn ($v) => $v === null ? '' : ((int) $v ? 'Yes' : 'No'),
            'target_date' => static fn ($v) => fmt_date($v), 'done_date' => static fn ($v) => fmt_date($v),
            'owner_id' => static fn ($v) => (string) Db::value('SELECT name FROM users WHERE id = ?', [$v]),
        ]);
        Db::update('projects', $id, ['updated_at' => now_utc()]);

        $rows = Tasks::forProjects([$id])[$id] ?? [];
        $status = Tasks::status($p, $rows);
        $resolved = [];
        foreach ($status['tree'] as $tasks) {
            foreach ($tasks as $task) {
                $resolved[$task['id']] = $task['resolved'];
                foreach ($task['subs'] as $s) {
                    $resolved[$s['id']] = $s['resolved'];
                }
            }
        }
        echo json_encode([
            'ok' => true,
            'value' => $value,
            'display' => in_array($field, ['target_date', 'done_date'], true) ? fmt_date($value) : $value,
            'note_meta' => $field === 'note' && $value !== null ? 'edited by ' . Auth::user()['name'] . ' just now' : null,
            'resolved' => $resolved,
            'phase' => $status['phase'],
            'phase_label' => Tasks::PHASE_LABELS[$status['phase']],
            'clear_to_install' => $status['clear_to_install'],
            'days' => $status['days'],
        ]);
    }

    public function addTask(int $id): void
    {
        $this->find($id);
        $name = trim((string) ($_POST['name'] ?? ''));
        $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
        $phase = $_POST['phase'] ?? '';
        if ($name === '') {
            flash('error', 'Give the task a name.');
            redirect('/projects/' . $id . '#tasks');
        }
        if ($parentId) {
            $parent = Db::one('SELECT * FROM project_tasks WHERE id = ? AND project_id = ? AND parent_id IS NULL', [$parentId, $id]);
            if (!$parent) {
                redirect('/projects/' . $id);
            }
            $phase = $parent['phase'];
            $sort = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM project_tasks WHERE parent_id = ?', [$parentId]);
            $owner = $parent['owner_id'];
            $needed = 1;
        } else {
            if (!isset(Tasks::PHASES[$phase])) {
                redirect('/projects/' . $id);
            }
            $sort = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM project_tasks WHERE project_id = ? AND parent_id IS NULL AND phase = ?', [$id, $phase]);
            $owner = Auth::id();
            $needed = 1;
        }
        $tid = Db::insert('project_tasks', [
            'project_id' => $id, 'parent_id' => $parentId, 'name' => $name, 'phase' => $phase,
            'sort_order' => $sort, 'needed' => $needed, 'owner_id' => $owner,
        ]);
        Activity::event('project', $id, 'Added ' . ($parentId ? 'sub-task "' . $name . '" under ' . $parent['name'] : 'task "' . $name . '" to ' . Tasks::PHASES[$phase]));
        redirect('/projects/' . $id . '#task-' . $tid);
    }

    public function addFromTemplate(int $id): void
    {
        $this->find($id);
        $tid = Tasks::addFromTemplate($id, (int) ($_POST['template_id'] ?? 0));
        if ($tid) {
            $name = Db::value('SELECT name FROM project_tasks WHERE id = ?', [$tid]);
            Activity::event('project', $id, 'Added "' . $name . '" from the template');
        }
        redirect('/projects/' . $id . ($tid ? '#task-' . $tid : ''));
    }

    public function duplicateTask(int $id, int $taskId): void
    {
        $t = Db::one('SELECT * FROM project_tasks WHERE id = ? AND project_id = ?', [$taskId, $id]);
        $new = $t ? Tasks::duplicate($taskId) : null;
        if ($new) {
            Activity::event('project', $id, 'Duplicated "' . $t['name'] . '" as "' . Db::value('SELECT name FROM project_tasks WHERE id = ?', [$new]) . '"');
        }
        redirect('/projects/' . $id . ($new ? '#task-' . $new : ''));
    }

    public function deleteTask(int $id, int $taskId): void
    {
        $t = Db::one('SELECT * FROM project_tasks WHERE id = ? AND project_id = ?', [$taskId, $id]);
        if ($t) {
            Db::run('DELETE FROM project_tasks WHERE id = ?', [$taskId]);
            Activity::event('project', $id, 'Removed ' . ($t['parent_id'] ? 'sub-task' : 'task') . ' "' . $this->taskLabel($t) . '"'
                . ($t['done_date'] ? ' (done ' . fmt_date($t['done_date']) . ')' : ''));
            flash('success', 'Removed "' . $t['name'] . '".');
        }
        redirect('/projects/' . $id . '#tasks');
    }

    // ------------------------------------------------------------------ comments

    public function comment(int $id): void
    {
        $this->find($id);
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body !== '') {
            Activity::comment('project', $id, $body);
            Db::update('projects', $id, ['updated_at' => now_utc()]);
        }
        redirect('/projects/' . $id . '#log');
    }

    // ------------------------------------------------------------------ helpers

    private function taskLabel(array $t): string
    {
        if ($t['parent_id']) {
            $parent = Db::value('SELECT name FROM project_tasks WHERE id = ?', [$t['parent_id']]);
            return $parent . ' › ' . $t['name'];
        }
        return $t['name'];
    }

    private function find(int $id): array
    {
        $p = Projects::find($id);
        if (!$p) {
            View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
            exit;
        }
        return $p;
    }
}
