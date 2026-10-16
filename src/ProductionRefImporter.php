<?php
declare(strict_types=1);

namespace App;

/**
 * Adds reference jobs from a JSON file: {"jobs": [{name, project_number, monitor (vendor:site id,
 * e.g. "solaredge:1234567"), in_service, lat, lon, module, inverter, inverter_count, optimizers,
 * equipment, arrays [{azimuth, tilt, modules, mount}], losses {total, inverter, optimizer,
 * shading} (total as OpenSolar states it, without shading), proposal_tool, proposal_annual, proposal_monthly [12], notes}]}.
 * Add-only: a job whose name exists is skipped. Module and inverter names are checked against
 * SAM's CEC library files when they are present. One transaction; --dry-run rolls it back.
 */
final class ProductionRefImporter
{
	public function __construct(private bool $dryRun = false)
	{
	}

	/** @return array{counts:array, log:string[]} */
	public function run(array $data): array
	{
		$counts = ['added' => 0, 'skipped' => 0];
		$log = [];
		$pdo = Db::pdo();
		$pdo->beginTransaction();
		try {
			foreach ($data['jobs'] ?? [] as $j) {
				$name = trim((string) ($j['name'] ?? ''));
				if ($name === '') {
					throw new \InvalidArgumentException('Every job needs a name.');
				}
				if (Db::value('SELECT id FROM production_refs WHERE name = ?', [$name])) {
					$counts['skipped']++;
					$log[] = "skip   $name (already there)";
					continue;
				}
				$row = $this->row($j, $name, $log);
				$id = Db::insert('production_refs', $row);
				Activity::event('production_ref', $id, 'Added by import', null);
				$counts['added']++;
				$log[] = "add    $name: " . count($row['arrays'] ? json_decode($row['arrays'], true) : []) . ' arrays'
					. ($row['project_id'] ? ', project linked' : ', no project') . ($row['monitor_site_id'] ? ', monitoring linked' : ', no monitoring');
			}
			$this->dryRun ? $pdo->rollBack() : $pdo->commit();
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
		return ['counts' => $counts, 'log' => $log];
	}

	private function row(array $j, string $name, array &$log): array
	{
		$num = static fn ($v): ?float => is_numeric($v) ? (float) $v : null;
		$lat = $num($j['lat'] ?? null);
		$lon = $num($j['lon'] ?? null);
		if ($err = SolarResource::pointError($lat, $lon)) {
			throw new \InvalidArgumentException("$name: $err");
		}
		foreach (['module' => 'cec_modules.csv', 'inverter' => 'cec_inverters.csv'] as $k => $file) {
			$v = trim((string) ($j[$k] ?? ''));
			if ($v === '') {
				throw new \InvalidArgumentException("$name: $k (the SAM CEC library name) is required.");
			}
			$path = Sam::libFolder() . '/' . $file;
			if (is_file($path) && !self::inLibrary($path, $v)) {
				throw new \InvalidArgumentException("$name: \"$v\" is not in SAM's $file.");
			}
		}
		$arrays = [];
		foreach ($j['arrays'] ?? [] as $a) {
			$mods = (int) ($a['modules'] ?? 0);
			$az = $num($a['azimuth'] ?? null);
			$tilt = $num($a['tilt'] ?? null);
			if ($mods <= 0 || $az === null || $tilt === null || $az < 0 || $az >= 360 || $tilt < 0 || $tilt > 90) {
				throw new \InvalidArgumentException("$name: each array needs modules, azimuth 0 to 359 and tilt 0 to 90.");
			}
			$arrays[] = ['azimuth' => $az, 'tilt' => $tilt, 'modules' => $mods, 'mount' => ($a['mount'] ?? '') === 'ground' ? 'ground' : 'roof'];
		}
		if (!$arrays) {
			throw new \InvalidArgumentException("$name: no arrays.");
		}
		$monthly = $j['proposal_monthly'] ?? null;
		if ($monthly !== null && (!is_array($monthly) || count($monthly) !== 12)) {
			throw new \InvalidArgumentException("$name: proposal_monthly must have 12 values, January first.");
		}
		$projectId = null;
		if (!empty($j['project_number'])) {
			$projectId = Db::value('SELECT id FROM projects WHERE project_number = ?', [(string) $j['project_number']]) ?: null;
			if (!$projectId) {
				$log[] = "note   $name: no project numbered " . $j['project_number'];
			}
		}
		$siteId = null;
		if (!empty($j['monitor']) && str_contains((string) $j['monitor'], ':')) {
			[$vendor, $vid] = explode(':', (string) $j['monitor'], 2);
			$siteId = Db::value('SELECT id FROM monitor_sites WHERE vendor = ? AND vendor_site_id = ?', [$vendor, $vid]) ?: null;
			if (!$siteId) {
				$log[] = "note   $name: no monitored system " . $j['monitor'];
			}
		}
		$l = $j['losses'] ?? [];
		return [
			'name' => $name,
			'project_id' => $projectId,
			'monitor_site_id' => $siteId,
			'in_service' => Projects::parseDate((string) ($j['in_service'] ?? '')),
			'lat' => $lat,
			'lon' => $lon,
			'module' => trim((string) $j['module']),
			'inverter' => trim((string) $j['inverter']),
			'inverter_count' => max(1, (int) ($j['inverter_count'] ?? 1)),
			'optimizers' => empty($j['optimizers']) ? 0 : 1,
			'equipment' => trim((string) ($j['equipment'] ?? '')) ?: null,
			'arrays' => json_encode($arrays),
			'loss_total' => $num($l['total'] ?? null),
			'loss_inverter' => $num($l['inverter'] ?? null),
			'loss_optimizer' => $num($l['optimizer'] ?? null),
			'loss_shading' => $num($l['shading'] ?? null),
			'proposal_tool' => trim((string) ($j['proposal_tool'] ?? '')) ?: null,
			'proposal_annual' => $num($j['proposal_annual'] ?? null),
			'proposal_monthly' => $monthly !== null ? json_encode(array_map('floatval', $monthly)) : null,
			'notes' => trim((string) ($j['notes'] ?? '')) ?: null,
		];
	}

	private static function inLibrary(string $path, string $name): bool
	{
		$f = fopen($path, 'r');
		while (($row = fgetcsv($f, null, ',', '"', '')) !== false) {
			if (($row[0] ?? null) === $name) {
				fclose($f);
				return true;
			}
		}
		fclose($f);
		return false;
	}
}
