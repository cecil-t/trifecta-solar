<?php
declare(strict_types=1);

namespace App;

/**
 * One-off data cleanups Greg asked for. Each only fills a blank ("?") answer and logs an
 * event on the project; nothing already answered is touched. Run with --dry-run first.
 */
final class Cleanup
{
    /** Inverter text that means SolarEdge (brand, SE model numbers, Home Hub, HD-Wave). */
    private const SOLAREDGE = '/solar\s*edge|\bSE\b|\bSE\d|home\s*hub|hd[\s-]*wave/i';

    /** @return string[] log lines */
    public static function questionDefaults(bool $dryRun): array
    {
        $log = [];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // 1) SolarEdge extended warranty: No when every inverter line is a known non-SolarEdge brand.
            foreach (self::blankAnswers('SolarEdge extended warranty') as $t) {
                $lines = Db::all('SELECT description FROM project_inverters WHERE project_id = ?', [$t['project_id']]);
                $descs = array_map(static fn ($l) => trim((string) $l['description']), $lines);
                if (!$descs || in_array('', $descs, true)) {
                    $log[] = "skip   {$t['label']}: inverter type unknown";
                    continue;
                }
                if (preg_grep(self::SOLAREDGE, $descs)) {
                    $log[] = "skip   {$t['label']}: has SolarEdge inverters (" . implode(', ', $descs) . ')';
                    continue;
                }
                self::setNo($t, 'SolarEdge extended warranty', 'inverters are not SolarEdge (' . implode(', ', array_unique($descs)) . ')');
                $log[] = "No     {$t['label']}: SolarEdge extended warranty (" . implode(', ', array_unique($descs)) . ')';
            }

            // 2) Utility rebate and 3) VNM: the spreadsheet only recorded "Yes", so a blank on an
            // imported project means No. Projects created in the app are left alone.
            foreach (['Utility rebate', 'Virtual net metering'] as $name) {
                foreach (self::blankAnswers($name) as $t) {
                    $imported = Db::value("SELECT 1 FROM activity_log WHERE entity_type = 'project' AND entity_id = ? AND body LIKE 'Imported from %'", [$t['project_id']]);
                    if (!$imported) {
                        $log[] = "skip   {$t['label']}: $name (project not from the spreadsheet)";
                        continue;
                    }
                    self::setNo($t, $name, 'blank on the spreadsheet means No');
                    $log[] = "No     {$t['label']}: $name";
                }
            }
            $dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $log;
    }

    /**
     * Mark every open payment milestone done (today) on projects in the Completed phase.
     * Cancelled projects are skipped; payments marked "No" are left alone.
     * @return string[] log lines
     */
    public static function completedPayments(bool $dryRun): array
    {
        $log = [];
        $today = date('Y-m-d');
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $projects = Db::all("SELECT * FROM projects WHERE COALESCE(hold_state, '') <> 'cancelled' ORDER BY project_number");
            $rows = Tasks::forProjects(array_column($projects, 'id'));
            foreach ($projects as $p) {
                $st = Tasks::status($p, $rows[(int) $p['id']] ?? []);
                if ($st['phase'] !== 'complete') {
                    continue;
                }
                $names = [];
                foreach ($st['tree']['payments'] ?? [] as $t) {
                    if ((string) $t['needed'] === '0') {
                        continue;
                    }
                    $items = $t['subs'] ?: [$t];
                    foreach ($items as $i) {
                        if ((string) $i['needed'] === '0' || !empty($i['done_date'])) {
                            continue;
                        }
                        Db::update('project_tasks', (int) $i['id'], ['done_date' => $today, 'needed' => 1, 'updated_at' => now_utc()]);
                        $names[] = ($t['subs'] ? $t['name'] . ' > ' : '') . $i['name'];
                    }
                }
                if ($names) {
                    Activity::event('project', (int) $p['id'], 'Marked payments received ' . fmt_date($today) . ' (project completed; data cleanup): ' . implode(', ', $names), null);
                    $log[] = "paid   {$p['project_number']} {$p['name']}: " . implode(', ', $names);
                }
            }
            if (!$log) {
                $log[] = 'Nothing to do: every Completed project already has its payments marked.';
            }
            $dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $log;
    }

    /**
     * Payment milestones the first import left unnamed (the contract label wrapped onto two
     * lines). Fills only blank names, matched by the row's position in the project's list.
     */
    private const PAYMENT_LABELS = [
        '25026' => ['Deposit', 'Payment at Materials Purchase, 25%, with up to 50% allowable', 'Payment at Time of Install, 50%', 'Payment after Completion, 25%'],
        '25052' => ['Down Payment to Trifecta', 'Payment from Immerse at Time of Materials Purchase, 20%', 'Payment from Climatize at Start of Construction, 60%', 'Payment from Climatize at Project Completion,'],
        '26014' => ['Deposit, At Time of Signing', 'Due when Ordering Material, 20%', 'Due Approx. 2 Weeks Before Anticipated Install Date, 57%', 'Balance Due Upon Completion'],
        '26025' => ['Deposit for Project and Panel Purchase', 'Payment at Tracker Purchase, 10%', 'Payment 2 Weeks Before Anticipated Install Date, 60%', 'Payment after Completion, remaining Amount'],
        '26030' => ['Deposit, At Time of Signing', 'Due when Ordering Material, 20%', 'Due Approx. 2 Weeks Before Anticipated Install Date, 57%', 'Balance Due Upon Completion'],
        '26031' => ['Deposit, 7% to Safe Harbor', 'Due when Materials Ordered, 23%', 'Due Approx. 2 Weeks Before Anticipated Install Date, 60%', 'Final Payment (Remaining Balance)'],
    ];

    /** @return string[] log lines */
    public static function paymentLabels(bool $dryRun): array
    {
        $log = [];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach (self::PAYMENT_LABELS as $number => $labels) {
                $p = Db::one('SELECT id, name FROM projects WHERE project_number = ?', [$number]);
                if (!$p) {
                    continue;
                }
                $rows = Db::all("SELECT id, name FROM project_tasks WHERE project_id = ? AND phase = 'payments' AND parent_id IS NULL ORDER BY sort_order, id", [$p['id']]);
                if (count($rows) !== count($labels)) {
                    $log[] = "skip   $number {$p['name']}: payment list was changed since import";
                    continue;
                }
                foreach ($rows as $k => $r) {
                    if (trim((string) $r['name']) === '') {
                        Db::update('project_tasks', (int) $r['id'], ['name' => $labels[$k], 'updated_at' => now_utc()]);
                        Activity::event('project', (int) $p['id'], 'Named unlabeled payment from the contract: ' . $labels[$k] . ' (data cleanup)', null);
                        $log[] = "name   $number {$p['name']}: {$labels[$k]}";
                    }
                }
            }
            if (!$log) {
                $log[] = 'Nothing to do: no unnamed payments found.';
            }
            $dryRun ? $pdo->rollBack() : $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $log;
    }

    /** Top-level tasks from the named template whose Needed is still blank. */
    private static function blankAnswers(string $templateName): array
    {
        return Db::all(
            "SELECT t.id, t.project_id, p.project_number || ' ' || p.name AS label
             FROM project_tasks t
             JOIN task_templates tt ON tt.id = t.template_id
             JOIN projects p ON p.id = t.project_id
             WHERE tt.name = ? AND t.parent_id IS NULL AND t.needed IS NULL
             ORDER BY p.project_number",
            [$templateName]
        );
    }

    private static function setNo(array $t, string $name, string $why): void
    {
        Db::update('project_tasks', (int) $t['id'], ['needed' => 0, 'updated_at' => now_utc()]);
        Activity::event('project', (int) $t['project_id'], "Set $name to No: $why (data cleanup)", null);
    }
}
