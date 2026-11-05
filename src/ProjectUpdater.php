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
 *
 * More per-project keys:
 *   "warranty_program": "trifecta" | "solarinsure" | "none" | "unknown"  (fill-blanks)
 *   "monitor_site": {"vendor": "solaredge", "site_id": "1234567"}       links the monitored system to
 *                                                                      the project when it has none
 *   "warranties": [{"kind": "module_product", "provider": "Example Solar", "covers": "...",
 *                   "term_years": 25, "start_basis": "install", "note": "..."}]
 *                 add-only: a line with the same kind, provider and covers is left alone
 *
 * And a top-level "tickets" list links service tickets that have no project:
 *   "tickets": [{"ticket_number": "S26001", "project_number": "26001"}]
 */
final class ProjectUpdater
{
	/** file key => [projects column, log label, organization type] */
	private const FIELDS = [
		'designer' => ['designer_org_id', 'Designer', 'designer'],
		'installer' => ['installer_org_id', 'Installer', 'contractor'],
	];

	private array $log = [];
	private array $counts = ['filled' => 0, 'already' => 0, 'kept' => 0, 'missing' => 0, 'errors' => 0,
		'sites_linked' => 0, 'warranties_added' => 0, 'tickets_linked' => 0];

	public function __construct(private bool $dryRun = false)
	{
	}

	/** @return array{counts:array, log:string[]} */
	public function run(array $data): array
	{
		if (!is_array($data['projects'] ?? null) && !is_array($data['tickets'] ?? null)) {
			throw new \InvalidArgumentException('The file needs a "projects" or "tickets" list.');
		}
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			foreach ($data['projects'] ?? [] as $row) {
				$this->project(is_array($row) ? $row : []);
			}
			foreach ($data['tickets'] ?? [] as $row) {
				$this->ticket(is_array($row) ? $row : []);
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
			if ($key === 'warranty_program') {
				$this->program($p, (string) $value, $label);
				continue;
			}
			if ($key === 'monitor_site') {
				$this->site($p, is_array($value) ? $value : [], $label);
				continue;
			}
			if ($key === 'warranties') {
				foreach (is_array($value) ? $value : [] as $w) {
					$this->warranty($p, is_array($w) ? $w : [], $label);
				}
				continue;
			}
			if (!isset(self::FIELDS[$key])) {
				$this->counts['errors']++;
				$this->log[] = "  ! $label: unknown field \"$key\" (known: " . implode(', ', [...array_keys(self::FIELDS), 'warranty_program', 'monitor_site', 'warranties']) . ')';
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

	private function program(array $p, string $value, string $label): void
	{
		if (!isset(Warranties::PROGRAMS[$value])) {
			$this->counts['errors']++;
			$this->log[] = "  ! $label: warranty_program \"$value\" is not one of " . implode(', ', array_keys(Warranties::PROGRAMS));
			return;
		}
		if ((string) $p['warranty_program'] === $value) {
			$this->counts['already']++;
			$this->log[] = "ok     $label: warranty program is already $value";
			return;
		}
		if ($p['warranty_program'] !== null && $p['warranty_program'] !== '') {
			$this->counts['kept']++;
			$this->log[] = "keep   $label: warranty program is {$p['warranty_program']}, not changed";
			return;
		}
		Db::update('projects', (int) $p['id'], ['warranty_program' => $value, 'updated_at' => now_utc()]);
		Activity::changes('project', (int) $p['id'], ['warranty_program' => null], ['warranty_program' => $value],
			['warranty_program' => 'Warranty program'], ['warranty_program' => static fn ($v) => Warranties::PROGRAMS[$v] ?? $v]);
		$this->counts['filled']++;
		$this->log[] = "fill   $label: warranty program = $value";
	}

	private function site(array $p, array $v, string $label): void
	{
		$vendor = trim((string) ($v['vendor'] ?? ''));
		$siteId = trim((string) ($v['site_id'] ?? ''));
		$site = Db::one('SELECT id, name, project_id FROM monitor_sites WHERE vendor = ? AND vendor_site_id = ?', [$vendor, $siteId]);
		if (!$site) {
			$this->counts['errors']++;
			$this->log[] = "  ! $label: no $vendor monitoring system $siteId";
			return;
		}
		if ((int) $site['project_id'] === (int) $p['id']) {
			$this->counts['already']++;
			$this->log[] = "ok     $label: $vendor {$site['name']} is already linked";
			return;
		}
		if ($site['project_id']) {
			$this->counts['kept']++;
			$other = (string) Db::value("SELECT project_number || ' ' || name FROM projects WHERE id = ?", [(int) $site['project_id']]);
			$this->log[] = "keep   $label: $vendor {$site['name']} is linked to $other, not changed";
			return;
		}
		Db::update('monitor_sites', (int) $site['id'], ['project_id' => (int) $p['id']]);
		Activity::event('project', (int) $p['id'], 'Monitoring system linked by import: ' . $site['name'] . ' (' . $vendor . ' ' . $siteId . ')', null);
		$this->counts['sites_linked']++;
		$this->log[] = "link   $label: $vendor {$site['name']}";
	}

	private function warranty(array $p, array $w, string $label): void
	{
		$kind = (string) ($w['kind'] ?? '');
		$years = isset($w['term_years']) && is_numeric($w['term_years']) ? (float) $w['term_years'] : 0;
		$basis = (string) ($w['start_basis'] ?? 'install');
		if (!isset(Warranties::KINDS[$kind]) || $years <= 0 || $years > 50 || !isset(Warranties::BASES[$basis])) {
			$this->counts['errors']++;
			$this->log[] = "  ! $label: warranty needs a known kind, term_years 1 to 50 and a known start_basis: " . json_encode($w);
			return;
		}
		$text = static fn (string $k) => trim((string) ($w[$k] ?? '')) ?: null;
		$row = [
			'kind' => $kind, 'provider' => $text('provider'), 'covers' => $text('covers'), 'term_years' => $years,
			'start_basis' => $basis, 'start_date' => Projects::parseDate((string) ($w['start_date'] ?? '')),
			'cost_cents' => isset($w['cost']) && is_numeric($w['cost']) ? (int) round((float) $w['cost'] * 100) : null,
			'reference' => $text('reference'), 'note' => $text('note'),
			'guarantee_kwh' => isset($w['guarantee_kwh']) && is_numeric($w['guarantee_kwh']) ? (float) $w['guarantee_kwh'] : null,
			'guarantee_rate' => isset($w['guarantee_rate']) && is_numeric($w['guarantee_rate']) ? (float) $w['guarantee_rate'] : null,
		];
		$dupe = Db::value('SELECT id FROM project_warranties WHERE project_id = ? AND kind = ? AND COALESCE(provider, \'\') = ? COLLATE NOCASE AND COALESCE(covers, \'\') = ? COLLATE NOCASE',
			[(int) $p['id'], $kind, (string) $row['provider'], (string) $row['covers']]);
		$what = Warranties::KINDS[$kind] . ($row['provider'] ? ' (' . $row['provider'] . ')' : '') . ', ' . rtrim(rtrim(number_format($years, 1), '0'), '.') . ' years';
		if ($dupe) {
			$this->counts['already']++;
			$this->log[] = "ok     $label: already has $what";
			return;
		}
		Db::insert('project_warranties', $row + ['project_id' => (int) $p['id'], 'source' => 'import']);
		Activity::event('project', (int) $p['id'], 'Warranty added by import: ' . $what . ($row['covers'] ? ', ' . $row['covers'] : ''), null);
		$this->counts['warranties_added']++;
		$this->log[] = "add    $label: $what";
	}

	private function ticket(array $row): void
	{
		$tn = trim((string) ($row['ticket_number'] ?? ''));
		$pn = trim((string) ($row['project_number'] ?? ''));
		$t = Db::one('SELECT id, ticket_number, project_id FROM service_tickets WHERE ticket_number = ?', [$tn]);
		$p = Db::one('SELECT id, project_number, name FROM projects WHERE project_number = ?', [$pn]);
		if (!$t || !$p) {
			$this->counts['missing']++;
			$this->log[] = 'skip   ticket ' . ($tn ?: '(none)') . ': ' . (!$t ? 'no such ticket' : "no project $pn");
			return;
		}
		$label = "ticket $tn";
		if ((int) $t['project_id'] === (int) $p['id']) {
			$this->counts['already']++;
			$this->log[] = "ok     $label: already on {$p['project_number']} {$p['name']}";
			return;
		}
		if ($t['project_id']) {
			$this->counts['kept']++;
			$this->log[] = "keep   $label: already on another project, not changed";
			return;
		}
		Db::update('service_tickets', (int) $t['id'], ['project_id' => (int) $p['id'], 'updated_at' => now_utc()]);
		Activity::event('service', (int) $t['id'], 'Project linked by import: ' . $p['project_number'] . ' ' . $p['name'], null);
		$this->counts['tickets_linked']++;
		$this->log[] = "link   $label: {$p['project_number']} {$p['name']}";
	}
}
