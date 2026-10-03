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
