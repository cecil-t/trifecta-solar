<?php
declare(strict_types=1);

namespace App;

/**
 * Fills blank fields on existing projects from an updates file (import:updates).
 * Fill-blanks only: a field that already has a value is never changed, it is listed instead.
 * Everything runs in one transaction; --dry-run rolls it back after reporting.
 *
 * File format (keep it in the gitignored import/ folder):
 *   {"projects": [
 *     {"project_number": "26001", "designer": "Example Design Co"},
 *     {"project_number": "26002", "designer": "Example Design Co", "installer": "Example Electric"}
 *   ]}
 *
 * Directory fields name an organization that must already exist (it is never created here).
 */
final class ProjectUpdater
{
	/** file key => [projects column, log label, organization type] */
	private const FIELDS = [
		'designer' => ['designer_org_id', 'Designer', 'designer'],
		'installer' => ['installer_org_id', 'Installer', 'contractor'],
	];

	private array $log = [];
	private array $counts = ['filled' => 0, 'already' => 0, 'kept' => 0, 'missing' => 0, 'errors' => 0];

	public function __construct(private bool $dryRun = false)
	{
	}

	/** @return array{counts:array, log:string[]} */
	public function run(array $data): array
	{
		if (!isset($data['projects']) || !is_array($data['projects'])) {
			throw new \InvalidArgumentException('The file needs a "projects" list.');
		}
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			foreach ($data['projects'] as $row) {
				$this->project(is_array($row) ? $row : []);
			}
			$this->dryRun ? $pdo->rollBack() : $pdo->commit();
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
		return ['counts' => $this->counts, 'log' => $this->log];
	}

	private function project(array $row): void
	{
		$number = trim((string) ($row['project_number'] ?? ''));
		$p = $number !== '' ? Db::one('SELECT * FROM projects WHERE project_number = ?', [$number]) : null;
		if (!$p) {
			$this->counts['missing']++;
			$this->log[] = 'skip   ' . ($number !== '' ? $number : '(no project_number)') . ': no such project';
			return;
		}
		$label = $p['project_number'] . ' ' . $p['name'];
		foreach ($row as $key => $value) {
			if ($key === 'project_number') {
				continue;
			}
			if (!isset(self::FIELDS[$key])) {
				$this->counts['errors']++;
				$this->log[] = "  ! $label: unknown field \"$key\" (known: " . implode(', ', array_keys(self::FIELDS)) . ')';
				continue;
			}
			[$column, $fieldLabel, $orgType] = self::FIELDS[$key];
			$name = trim((string) $value);
			$org = $name !== '' ? Db::one('SELECT id, name FROM organizations WHERE type = ? AND name = ? COLLATE NOCASE', [$orgType, $name]) : null;
			if (!$org) {
				$this->counts['errors']++;
				$this->log[] = "  ! $label: no $orgType named \"$name\" in the Directory";
				continue;
			}
			$current = $p[$column];
			if ((int) $current === (int) $org['id']) {
				$this->counts['already']++;
				$this->log[] = "ok     $label: $fieldLabel is already {$org['name']}";
				continue;
			}
			if ($current !== null && $current !== '') {
				$this->counts['kept']++;
				$has = (string) Db::value('SELECT name FROM organizations WHERE id = ?', [$current]);
				$this->log[] = "keep   $label: $fieldLabel is $has, not changed";
				continue;
			}
			Db::update('projects', (int) $p['id'], [$column => (int) $org['id'], 'updated_at' => now_utc()]);
			Activity::changes('project', (int) $p['id'], [$column => null], [$column => (int) $org['id']],
				[$column => $fieldLabel], [$column => static fn () => $org['name']]);
			$p[$column] = (int) $org['id'];
			$this->counts['filled']++;
			$this->log[] = "fill   $label: $fieldLabel = {$org['name']}";
		}
	}
}
