<?php
declare(strict_types=1);

namespace App;

/**
 * Project task trees: instantiate from the template, roll up status, and derive the
 * project's phase from gate items. Order is never enforced; phases are groupings.
 */
final class Tasks
{
    public const PHASES = [
        'pre_install'  => 'Pre-Install',
        'installation' => 'Installation',
        'closeout'     => 'Closeout',
        'payments'     => 'Payments',
    ];

    public const GATES = [
        'start_clock'     => 'Starts day counter',
        'install_prereq'  => 'Required for Clear to install',
        'install_started' => 'Moves to Installation',
        'pto'             => 'Moves to Closeout',
    ];

    /** Copy the active template into a new project. */
    public static function instantiate(int $projectId, bool $hasPv, bool $hasBatteries): void
    {
        $templates = Db::all('SELECT * FROM task_templates WHERE is_active = 1 ORDER BY parent_id IS NOT NULL, sort_order, id');
        $applies = static fn (array $t) => $t['applies_when'] === 'all'
            || ($t['applies_when'] === 'pv' && $hasPv)
            || ($t['applies_when'] === 'storage' && $hasBatteries);

        $map = []; // template id => project task id
        $parentOwner = [];
        foreach ($templates as $t) {
            if (!$applies($t)) {
                continue;
            }
            if ($t['parent_id'] !== null && !isset($map[$t['parent_id']])) {
                continue; // parent was skipped or inactive
            }
            $parentId = $t['parent_id'] !== null ? $map[$t['parent_id']] : null;
            $owner = $t['default_owner_id'] ?? ($parentId ? $parentOwner[$parentId] ?? null : null);
            $id = Db::insert('project_tasks', [
                'project_id'  => $projectId,
                'parent_id'   => $parentId,
                'template_id' => $t['id'],
                'name'        => $t['name'],
                'phase'       => $t['phase'],
                'sort_order'  => $t['sort_order'],
                'needed'      => $t['default_needed'],
                'gate'        => $t['gate'],
                'ref_label'   => $t['ref_label'],
                'owner_id'    => $owner,
            ]);
            $map[$t['id']] = $id;
            $parentOwner[$id] = $owner;
        }
    }

    /**
     * After Solar PV or Batteries is switched on for an existing project, add the template
     * items for that scope ('pv' or 'storage') the project doesn't already have. Returns
     * the names added. Nothing is ever removed when a scope is switched off.
     */
    public static function addScope(int $projectId, string $scope): array
    {
        $added = [];
        $templates = Db::all('SELECT * FROM task_templates WHERE is_active = 1 ORDER BY parent_id IS NOT NULL, sort_order, id');
        $existing = static fn (int $tplId, ?int $parentId) => Db::value(
            'SELECT id FROM project_tasks WHERE project_id = ? AND template_id = ? AND ' . ($parentId ? 'parent_id = ?' : 'parent_id IS NULL') . ' ORDER BY id LIMIT 1',
            $parentId ? [$projectId, $tplId, $parentId] : [$projectId, $tplId]
        );
        foreach ($templates as $t) {
            if ($t['applies_when'] !== $scope) {
                continue;
            }
            if ($t['parent_id'] === null) {
                if (!$existing((int) $t['id'], null)) {
                    self::addFromTemplate($projectId, (int) $t['id']);
                    $added[] = $t['name'];
                }
                continue;
            }
            $parentTaskId = (int) Db::value('SELECT id FROM project_tasks WHERE project_id = ? AND template_id = ? AND parent_id IS NULL ORDER BY id LIMIT 1', [$projectId, $t['parent_id']]);
            if (!$parentTaskId || $existing((int) $t['id'], $parentTaskId)) {
                continue;
            }
            $parent = Db::one('SELECT * FROM project_tasks WHERE id = ?', [$parentTaskId]);
            Db::insert('project_tasks', [
                'project_id' => $projectId, 'parent_id' => $parentTaskId, 'template_id' => $t['id'], 'name' => $t['name'],
                'phase' => $parent['phase'], 'sort_order' => $t['sort_order'], 'needed' => $t['default_needed'],
                'gate' => $t['gate'], 'ref_label' => $t['ref_label'], 'owner_id' => $t['default_owner_id'] ?? $parent['owner_id'],
            ]);
            $added[] = $parent['name'] . ' > ' . $t['name'];
        }
        return $added;
    }

    /** Copy one template task (and its active sub-tasks) into an existing project. */
    public static function addFromTemplate(int $projectId, int $templateId): ?int
    {
        $t = Db::one('SELECT * FROM task_templates WHERE id = ? AND parent_id IS NULL', [$templateId]);
        if (!$t) {
            return null;
        }
        $sort = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM project_tasks WHERE project_id = ? AND parent_id IS NULL AND phase = ?', [$projectId, $t['phase']]);
        $id = Db::insert('project_tasks', [
            'project_id' => $projectId, 'template_id' => $t['id'], 'name' => $t['name'], 'phase' => $t['phase'],
            'sort_order' => $sort, 'needed' => $t['default_needed'], 'gate' => $t['gate'],
            'ref_label' => $t['ref_label'], 'owner_id' => $t['default_owner_id'],
        ]);
        foreach (Db::all('SELECT * FROM task_templates WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order', [$templateId]) as $s) {
            Db::insert('project_tasks', [
                'project_id' => $projectId, 'parent_id' => $id, 'template_id' => $s['id'], 'name' => $s['name'],
                'phase' => $t['phase'], 'sort_order' => $s['sort_order'], 'needed' => $s['default_needed'],
                'gate' => $s['gate'], 'owner_id' => $s['default_owner_id'] ?? $t['default_owner_id'],
            ]);
        }
        return $id;
    }

    /** Duplicate a top-level task with blank dates/references (e.g. a second utility upgrade). */
    public static function duplicate(int $taskId): ?int
    {
        $t = Db::one('SELECT * FROM project_tasks WHERE id = ? AND parent_id IS NULL', [$taskId]);
        if (!$t) {
            return null;
        }
        $fresh = static fn (array $r, ?int $parent, string $name) => [
            'project_id' => $r['project_id'], 'parent_id' => $parent, 'template_id' => $r['template_id'],
            'name' => $name, 'phase' => $r['phase'], 'sort_order' => $r['sort_order'] + ($parent ? 0 : 1),
            'needed' => $r['needed'], 'gate' => $r['gate'], 'ref_label' => $r['ref_label'], 'owner_id' => $r['owner_id'],
        ];
        $count = (int) Db::value('SELECT COUNT(*) FROM project_tasks WHERE project_id = ? AND parent_id IS NULL AND (name = ? OR name LIKE ?)', [$t['project_id'], $t['name'], $t['name'] . ' #%']);
        $baseName = preg_replace('/ #\d+$/', '', $t['name']);
        $newId = Db::insert('project_tasks', $fresh($t, null, $baseName . ' #' . ($count + 1)));
        foreach (Db::all('SELECT * FROM project_tasks WHERE parent_id = ? ORDER BY sort_order, id', [$taskId]) as $s) {
            Db::insert('project_tasks', $fresh($s, $newId, $s['name']));
        }
        return $newId;
    }

    /** All tasks for the given projects, keyed by project id => flat list. */
    public static function forProjects(array $projectIds): array
    {
        if (!$projectIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $projectIds));
        $out = [];
        foreach (Db::all("SELECT * FROM project_tasks WHERE project_id IN ($in) ORDER BY sort_order, id") as $row) {
            $out[(int) $row['project_id']][] = $row;
        }
        return $out;
    }

    /** Build [phase => [task + 'subs' => [...] + 'resolved' => bool]] from a flat list. */
    public static function tree(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $r) {
            $byParent[$r['parent_id'] ?? 0][] = $r;
        }
        $tree = array_fill_keys(array_keys(self::PHASES), []);
        foreach ($byParent[0] ?? [] as $t) {
            $t['subs'] = $byParent[$t['id']] ?? [];
            foreach ($t['subs'] as &$s) {
                $s['resolved'] = self::leafResolved($s) || $t['needed'] === 0 || $t['needed'] === '0';
            }
            unset($s);
            $t['resolved'] = self::taskResolved($t);
            $tree[$t['phase']][] = $t;
        }
        return $tree;
    }

    public static function leafResolved(array $r): bool
    {
        return (string) $r['needed'] === '0' || !empty($r['done_date']);
    }

    public static function taskResolved(array $t): bool
    {
        if ((string) $t['needed'] === '0') {
            return true;
        }
        if (!$t['subs']) {
            return !empty($t['done_date']);
        }
        foreach ($t['subs'] as $s) {
            if (!self::leafResolved($s)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Derive project status from its tasks.
     * @return array{phase:string, clear_to_install:?bool, start_date:?string, pto_date:?string, days:?int, complete:bool, open_count:int}
     */
    public static function status(array $project, array $rows): array
    {
        $tree = self::tree($rows);
        $parents = [];
        foreach ($rows as $r) {
            $parents[$r['id']] = $r;
        }
        $active = static function (array $r) use ($parents): bool {
            if ((string) $r['needed'] === '0') {
                return false;
            }
            $p = $r['parent_id'] ? ($parents[$r['parent_id']] ?? null) : null;
            return !($p && (string) $p['needed'] === '0');
        };

        $start = $installStarted = $pto = null;
        $prereqGroups = [];
        foreach ($rows as $r) {
            if (!$r['gate']) {
                continue;
            }
            switch ($r['gate']) {
                case 'start_clock':
                    $start = $start ?? ($r['done_date'] ?: null);
                    break;
                case 'install_started':
                    if ($r['done_date']) { $installStarted = $installStarted ?? $r['done_date']; }
                    break;
                case 'pto':
                    if ($r['done_date']) { $pto = $pto ?? $r['done_date']; }
                    break;
                case 'install_prereq':
                    $group = $r['parent_id'] ?: $r['id'];
                    $prereqGroups[$group] ??= ['any_done' => false, 'any_active' => false];
                    if ($active($r)) {
                        $prereqGroups[$group]['any_active'] = true;
                        if ($r['done_date']) { $prereqGroups[$group]['any_done'] = true; }
                    }
                    break;
            }
        }
        $clear = null;
        if ($prereqGroups) {
            $clear = true;
            foreach ($prereqGroups as $g) {
                if ($g['any_active'] && !$g['any_done']) {
                    $clear = false;
                }
            }
        }

        $complete = true;
        foreach (['closeout', 'payments'] as $ph) {
            foreach ($tree[$ph] as $t) {
                if (!$t['resolved']) { $complete = false; }
            }
        }

        $phase = match (true) {
            $project['hold_state'] === 'cancelled' => 'cancelled',
            !empty($project['archived_at'])         => 'complete', // marked completed by hand
            $pto !== null && $complete             => 'complete',
            $pto !== null                          => 'closeout',
            $installStarted !== null               => 'installation',
            default                                => 'pre_install',
        };

        $days = null;
        if ($start) {
            $end = $pto ?: date('Y-m-d');
            $days = (int) floor((strtotime($end) - strtotime($start)) / 86400);
        }

        $open = 0;
        foreach ($tree as $tasks) {
            foreach ($tasks as $t) {
                if (!$t['resolved']) { $open++; }
            }
        }

        return [
            'phase' => $phase, 'clear_to_install' => $clear, 'start_date' => $start,
            'pto_date' => $pto, 'install_started' => $installStarted, 'days' => $days,
            'complete' => $complete, 'open_count' => $open, 'tree' => $tree,
        ];
    }

    public const PHASE_LABELS = [
        'pre_install' => 'Pre-Install', 'installation' => 'Installation', 'closeout' => 'Closeout',
        'complete' => 'Completed', 'cancelled' => 'Cancelled',
    ];

    /** Open step => the later step of the same task that implies it is done (open items rule R2). */
    public const IMPLIED_BY = [
        'Design > Planset sent' => 'Design > Planset received',
        'Zoning permit > Applied' => 'Zoning permit > Received',
        'Building permit > Applied' => 'Building permit > Received',
        'Interconnection > Applied (app and fee)' => 'Interconnection > Approved / permission to install',
        'Interconnection > Conditional approval' => 'Interconnection > Approved / permission to install',
        'Utility > COC submitted' => 'Utility > PTO received',
        'Utility > Meter swap / DER visit' => 'Utility > PTO received',
    ];
    /** Tasks whose sub-steps run side by side; open items shows them as one line (rule R4). */
    public const GROUPED = ['Materials', 'Monitoring portal', 'Photos', 'Commissioning'];

    /**
     * Open items a user owns that are actionable now, on active projects (not on hold,
     * cancelled or completed). Actionable means: the task's phase has been reached (a
     * Closeout task waits for PTO), or it has a target date within the next 30 days or
     * already past. For Payments, only each project's next unpaid milestone counts.
     * Then: leftovers from a phase the project has moved past are hidden (R1), a step is
     * resolved when a later step of its task is done (R2), and grouped sub-steps like
     * Materials collapse to one line (R4). Display only; no data is changed.
     */
    public static function openItemsFor(int $userId, int $limit = 2000): array
    {
        $rows = Db::all(
            "SELECT t.*, p.project_number, p.name AS project_name, p.id AS pid,
                    parent.name AS parent_name, parent.phase AS parent_phase,
                    EXISTS (SELECT 1 FROM project_tasks c WHERE c.parent_id = t.id) AS has_subs
             FROM project_tasks t
             JOIN projects p ON p.id = t.project_id
             LEFT JOIN project_tasks parent ON parent.id = t.parent_id
             WHERE p.hold_state IS NULL AND p.archived_at IS NULL
               AND COALESCE(t.owner_id, parent.owner_id) = ?
               AND t.done_date IS NULL
               AND COALESCE(t.needed, -1) <> 0
               AND (
                    (parent.id IS NULL AND (t.needed IS NULL OR NOT EXISTS (SELECT 1 FROM project_tasks c WHERE c.parent_id = t.id)))
                 OR (parent.id IS NOT NULL AND parent.needed = 1)
               )
             ORDER BY t.target_date IS NULL, t.target_date, p.project_number,
                      CASE t.phase WHEN 'pre_install' THEN 1 WHEN 'installation' THEN 2 WHEN 'closeout' THEN 3 ELSE 4 END,
                      COALESCE(parent.sort_order, t.sort_order), COALESCE(parent.id, t.id), t.sort_order",
            [$userId]
        );
        if (!$rows) {
            return [];
        }
        $pids = array_values(array_unique(array_map(static fn ($r) => (int) $r['pid'], $rows)));
        $all = self::forProjects($pids);
        $projects = [];
        foreach (Db::all('SELECT * FROM projects WHERE id IN (' . implode(',', $pids) . ')') as $p) {
            $projects[(int) $p['id']] = $p;
        }
        $rank = ['pre_install' => 1, 'installation' => 2, 'closeout' => 3, 'complete' => 4];
        $phaseRank = [];
        $nextPayment = [];
        foreach ($pids as $pid) {
            $st = self::status($projects[$pid], $all[$pid] ?? []);
            $phaseRank[$pid] = $rank[$st['phase']] ?? 1;
            foreach ($st['tree']['payments'] ?? [] as $t) {
                if (!$t['resolved']) {
                    $nextPayment[$pid] = (int) $t['id'];
                    break;
                }
            }
        }
        // Done steps per project, keyed "Parent > Name", for the implied-done rule.
        $doneKeys = [];
        $children = [];
        foreach ($all as $pid => $list) {
            $names = array_column($list, 'name', 'id');
            foreach ($list as $t) {
                if ($t['parent_id']) {
                    $children[(int) $t['parent_id']][] = $t;
                }
                if ($t['done_date']) {
                    $doneKeys[$pid][($t['parent_id'] ? ($names[$t['parent_id']] ?? '') . ' > ' : '') . $t['name']] = true;
                }
            }
        }
        $soon = date('Y-m-d', strtotime('+30 days'));
        $out = [];
        $groups = [];
        foreach ($rows as $r) {
            $pid = (int) $r['pid'];
            $phase = $r['parent_phase'] ?? $r['phase'];
            $topId = (int) ($r['parent_id'] ?? $r['id']);
            $rankHere = $rank[$phase] ?? 1;
            // R1: an earlier phase's leftovers stop showing once the project has moved past that phase.
            if ($phase !== 'payments' && $rankHere < $phaseRank[$pid]) {
                continue;
            }
            // R2: a step is treated as resolved when a later step of the same task is done.
            $label = ($r['parent_name'] ? $r['parent_name'] . ' > ' : '') . $r['name'];
            if (isset(self::IMPLIED_BY[$label]) && isset($doneKeys[$pid][self::IMPLIED_BY[$label]])) {
                continue;
            }
            $keep = ($r['target_date'] && $r['target_date'] <= $soon)
                || ($phase === 'payments' ? ($nextPayment[$pid] ?? 0) === $topId : $rankHere <= $phaseRank[$pid]);
            if (!$keep) {
                continue;
            }
            // R4: side-by-side sub-steps (Materials, Photos...) collapse to one line per task.
            if ($r['parent_id'] && in_array($r['parent_name'], self::GROUPED, true)) {
                $gid = (int) $r['parent_id'];
                if (isset($groups[$gid])) {
                    $out[$groups[$gid]]['group_open'][] = $r['name'];
                    continue;
                }
                $total = count(array_filter($children[$gid] ?? [], static fn ($c) => (int) ($c['needed'] ?? 1) !== 0));
                $r = ['id' => $gid, 'name' => $r['parent_name'], 'parent_name' => null, 'parent_id' => null, 'needed' => 1,
                    'note' => null, 'group_open' => [$r['name']], 'group_total' => $total] + $r;
                $groups[$gid] = count($out);
            }
            $out[] = $r;
            if (count($out) >= $limit) {
                break;
            }
        }
        foreach ($groups as $idx) {
            $g = &$out[$idx];
            $g['note'] = count($g['group_open']) . ' of ' . $g['group_total'] . ' open: ' . implode(', ', $g['group_open']);
            unset($g);
        }
        return $out;
    }
}
