<?php
declare(strict_types=1);

namespace App;

/** Project-level helpers: numbering, equipment math, option lists, input parsing. */
final class Projects
{
	public const CUSTOMER_TYPES = [
		'residential' => 'Residential', 'commercial' => 'Commercial',
		'nonprofit' => 'Non-Profit', 'government' => 'Government',
	];
	public const INSTALL_TYPES = ['roof' => 'Rooftop', 'ground' => 'Fixed ground mount', 'tracker' => 'Dual-axis tracker'];
	public const INVERTER_INTERNET = [
		'none' => 'No internet', 'ethernet' => 'Customer provided Ethernet', 'wifi' => 'Customer provided Wi-Fi',
		'cell' => 'Cell card in inverter', 'hotspot' => 'Mobile hotspot',
	];
	public const SREC_PROVIDERS = [
		'flett' => 'Flett Exchange', 'sol_systems' => 'Sol Systems', 'knollwood' => 'Knollwood Energy', 'xpansive' => 'Xpansive (SREC Trade)',
	];
	public const ROOF_MATERIALS = [
		'shingle' => 'Asphalt shingle', 'standing_seam' => 'Standing seam metal',
		'exposed_fastener' => 'Exposed-fastener metal', 'slate' => 'Slate', 'tile' => 'Tile',
		'membrane' => 'Flat membrane (EPDM / TPO)', 'wood_shake' => 'Wood shake', 'other' => 'Other',
	];

	/** Suggest the next YYNNN project number for the current year. */
	public static function nextNumber(): string
	{
		$yy = date('y');
		$max = Db::value("SELECT MAX(CAST(project_number AS INTEGER)) FROM projects WHERE project_number GLOB ?", [$yy . '[0-9][0-9][0-9]']);
		return $max ? (string) ((int) $max + 1) : $yy . '001';
	}

	public static function find(int $id): ?array
	{
		return Db::one(
			'SELECT p.*, c.name AS customer_name, u.name AS salesperson_name, u.initials AS salesperson_initials,
					ut.name AS utility_name, m.name AS municipality_name, co.name AS county_name, co.state_code AS county_state,
					zo.name AS zoning_org_name, bo.name AS building_org_name, io.name AS inspection_org_name,
					de.name AS designer_name, ins.name AS installer_name, nu.name AS notes_updated_by_name
			 FROM projects p
			 LEFT JOIN organizations c ON c.id = p.customer_id
			 LEFT JOIN users u ON u.id = p.salesperson_id
			 LEFT JOIN utilities ut ON ut.id = p.utility_id
			 LEFT JOIN municipalities m ON m.id = p.municipality_id
			 LEFT JOIN counties co ON co.id = m.county_id
			 LEFT JOIN organizations zo ON zo.id = p.zoning_org_id
			 LEFT JOIN organizations bo ON bo.id = p.building_org_id
			 LEFT JOIN organizations io ON io.id = p.inspection_org_id
			 LEFT JOIN organizations de ON de.id = p.designer_org_id
			 LEFT JOIN organizations ins ON ins.id = p.installer_org_id
			 LEFT JOIN users nu ON nu.id = p.notes_updated_by
			 WHERE p.id = ?',
			[$id]
		);
	}

	public static function equipment(int $projectId): array
	{
		return [
			'modules'   => Db::all('SELECT * FROM project_modules WHERE project_id = ? ORDER BY sort_order, id', [$projectId]),
			'inverters' => Db::all('SELECT * FROM project_inverters WHERE project_id = ? ORDER BY sort_order, id', [$projectId]),
			'batteries' => Db::all('SELECT * FROM project_batteries WHERE project_id = ? ORDER BY sort_order, id', [$projectId]),
		];
	}

	/** Roof faces of a rooftop project, in display order. */
	public static function roofs(int $projectId): array
	{
		return Db::all('SELECT * FROM project_roofs WHERE project_id = ? ORDER BY sort_order, id', [$projectId]);
	}

	/**
	 * A roof tilt typed as degrees ("27", "26.6") or as a roofer's pitch ("6/12", "6:12"), in degrees.
	 * Null when blank; throws when it can't be read or is out of range.
	 */
	public static function parseTilt(string $raw): ?float
	{
		$raw = trim(str_replace(['°', 'deg'], '', $raw));
		if ($raw === '') {
			return null;
		}
		if (preg_match('#^(\d+(?:\.\d+)?)\s*[/:]\s*12$#', $raw, $m)) {
			$deg = rad2deg(atan((float) $m[1] / 12));
		} elseif (is_numeric($raw)) {
			$deg = (float) $raw;
		} else {
			throw new \InvalidArgumentException('Roof tilt "' . $raw . '" should be degrees (e.g. 27) or a pitch (e.g. 6/12).');
		}
		if ($deg < 0 || $deg > 90) {
			throw new \InvalidArgumentException('Roof tilt must be between 0 and 90 degrees.');
		}
		return round($deg, 1);
	}

	/** Compass point for an azimuth in degrees (180 = S). */
	public static function compass(int $deg): string
	{
		$points = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
		return $points[(int) round((($deg % 360) + 360) % 360 / 22.5) % 16];
	}

	/** DC/AC kW, ratio, storage, and $/W from equipment lines and contract price. */
	public static function totals(array $eq, ?int $priceCents): array
	{
		$dc = 0.0; $panels = 0;
		foreach ($eq['modules'] as $m) { $dc += $m['qty'] * $m['watts'] / 1000; $panels += $m['qty']; }
		$ac = 0.0; $invCount = 0;
		foreach ($eq['inverters'] as $i) { $ac += $i['qty'] * $i['ac_kw']; $invCount += $i['qty']; }
		$kwh = 0.0; $bkw = 0.0; $bCount = 0;
		foreach ($eq['batteries'] as $b) { $kwh += $b['qty'] * (float) $b['kwh']; $bkw += $b['qty'] * (float) $b['kw']; $bCount += $b['qty']; }
		return [
			'dc_kw' => $dc, 'ac_kw' => $ac, 'panels' => $panels, 'inverters' => $invCount,
			'ratio' => $ac > 0 ? $dc / $ac : null,
			'storage_kwh' => $kwh, 'storage_kw' => $bkw, 'batteries' => $bCount,
			'price_per_watt' => ($priceCents && $dc > 0) ? $priceCents / 100 / ($dc * 1000) : null,
		];
	}

	/** DC kW per project for list views. */
	public static function dcKwMap(): array
	{
		$out = [];
		foreach (Db::all('SELECT project_id, SUM(qty * watts) / 1000.0 AS kw FROM project_modules GROUP BY project_id') as $r) {
			$out[(int) $r['project_id']] = (float) $r['kw'];
		}
		return $out;
	}

	/** project id => total battery kWh (lines with no kWh count as 0). */
	public static function batteryKwhMap(): array
	{
		$out = [];
		foreach (Db::all('SELECT project_id, SUM(qty * COALESCE(kwh, 0)) AS kwh FROM project_batteries GROUP BY project_id') as $r) {
			$out[(int) $r['project_id']] = (float) $r['kwh'];
		}
		return $out;
	}

	/**
	 * Project counts by status, the same way the Projects tabs count them: an open project on hold
	 * counts only under on_hold, and active is Pre-Install + Installation + Closeout.
	 * @return array{all:int,active:int,pre_install:int,installation:int,closeout:int,on_hold:int,complete:int,cancelled:int}
	 */
	public static function phaseCounts(): array
	{
		$counts = ['all' => 0, 'active' => 0, 'pre_install' => 0, 'installation' => 0, 'closeout' => 0, 'on_hold' => 0, 'complete' => 0, 'cancelled' => 0];
		$projects = Db::all('SELECT * FROM projects');
		$tasks = Tasks::forProjects(array_column($projects, 'id'));
		foreach ($projects as $p) {
			$phase = Tasks::status($p, $tasks[(int) $p['id']] ?? [])['phase'];
			$counts['all']++;
			if (!in_array($phase, ['complete', 'cancelled'], true) && $p['hold_state'] === 'on_hold') {
				$counts['on_hold']++;
				continue;
			}
			$counts[$phase]++;
			if (in_array($phase, ['pre_install', 'installation', 'closeout'], true)) {
				$counts['active']++;
			}
		}
		return $counts;
	}

	public static function money(?int $cents): string
	{
		return $cents === null ? '' : '$' . number_format($cents / 100, 2);
	}

	/** "123,456.78" or "$123456" -> cents; blank -> null. */
	public static function parseMoney(?string $s): ?int
	{
		$s = trim((string) $s);
		if ($s === '') {
			return null;
		}
		$n = (float) preg_replace('/[^0-9.\-]/', '', $s);
		return (int) round($n * 100);
	}

	public static function parseNumber(?string $s): ?float
	{
		$s = trim((string) $s);
		if ($s === '') {
			return null;
		}
		return (float) preg_replace('/[^0-9.\-]/', '', $s);
	}

	/** Accepts YYYY-MM-DD (from date inputs) or M/D/YYYY; returns YYYY-MM-DD or null. */
	public static function parseDate(?string $s): ?string
	{
		$s = trim((string) $s);
		if ($s === '') {
			return null;
		}
		foreach (['!Y-m-d', '!n/j/Y', '!m/d/Y'] as $fmt) {
			$d = \DateTimeImmutable::createFromFormat($fmt, $s);
			if ($d && $d->format(ltrim($fmt, '!')) === $s) {
				return $d->format('Y-m-d');
			}
		}
		return null;
	}

	// ---- option lists for forms ----

	public static function users(): array
	{
		return Db::all('SELECT id, name, initials FROM users WHERE is_active = 1 ORDER BY name');
	}

	public static function orgs(string $type): array
	{
		return Db::all('SELECT id, name FROM organizations WHERE type = ? AND is_active = 1 ORDER BY name', [$type]);
	}

	public static function utilities(): array
	{
		return Db::all('SELECT id, name FROM utilities WHERE is_active = 1 ORDER BY sort_order, name');
	}

	public static function fundingSources(): array
	{
		return Db::all('SELECT id, name FROM funding_sources WHERE is_active = 1 ORDER BY sort_order, name');
	}

	public static function municipalities(): array
	{
		return Db::all(
			'SELECT m.id, m.name, c.name AS county, c.state_code
			 FROM municipalities m JOIN counties c ON c.id = m.county_id
			 ORDER BY m.name, c.name'
		);
	}

	public static function counties(): array
	{
		return Db::all('SELECT id, name, state_code FROM counties ORDER BY state_code = \'PA\' DESC, state_code, name');
	}

	public static function rackingSuggestions(): array
	{
		return Db::all("SELECT DISTINCT racking FROM projects WHERE racking IS NOT NULL AND racking <> '' ORDER BY racking");
	}
}
