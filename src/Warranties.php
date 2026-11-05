<?php
declare(strict_types=1);

namespace App;

/**
 * Warranty lines for a project and the fleet, with month-level start and end dates.
 *
 * Three lines are worked out, not stored:
 * - Trifecta labor and workmanship, 10 years from passing final inspection, when the project's
 *   program is Trifecta or SolarInsure (Trifecta's warranty stands behind SolarInsure for what
 *   SolarInsure doesn't cover: wiring and balance of system).
 * - SolarInsure, 30 years from installation, when the program is SolarInsure.
 * - SolarEdge extended, from installation, when the closeout question says it was bought. The
 *   term comes from the question's note ("20 years", "25 Year"), else it is assumed from the
 *   inverters (residential Home Hub: 25, otherwise 20) and marked as assumed.
 * Every other line (manufacturer, production guarantee) is a project_warranties row.
 *
 * The program is the project's warranty_program, or when blank the SolarInsure registration
 * answer (Yes: SolarInsure, No: Trifecta); unanswered counts as Trifecta, marked as assumed.
 *
 * Start dates follow a fallback chain and carry a tag: actual (the step's own date or a typed
 * date), derived (worked out from a neighboring step) or estimated (contract signed plus a
 * typical lag, used only for systems that are installed but have no install dates at all).
 * A project with no install yet has warranties that have not started.
 */
final class Warranties
{
	public const PROGRAMS = [
		'trifecta' => 'Trifecta 10 year',
		'solarinsure' => 'SolarInsure 30 year',
		'none' => 'None (not our install)',
		'unknown' => 'Unknown (needs review)',
	];
	public const KINDS = [
		'module_product' => 'Module product',
		'module_performance' => 'Module performance',
		'inverter' => 'Inverter',
		'inverter_extended' => 'Inverter extended',
		'mlpe' => 'Optimizers / microinverters',
		'battery' => 'Battery',
		'racking' => 'Racking',
		'production_guarantee' => 'Production guarantee',
		'other' => 'Other',
	];
	public const BASES = [
		'inspection' => 'Final inspection passed',
		'pto' => 'PTO',
		'install' => 'Installation',
		'contract' => 'Contract signed',
	];
	public const TAGS = ['actual' => 'Actual', 'derived' => 'Derived', 'estimated' => 'Estimated'];
	public const TRIFECTA_YEARS = 10;
	public const SOLARINSURE_YEARS = 30;
	/** Contract signed to installation, for estimates on installed jobs with no install dates. */
	public const LAG_MONTHS = 6;

	/** The program code (or null when none applies) and how it was decided. */
	public static function program(array $p, array $dates): array
	{
		$set = (string) ($p['warranty_program'] ?? '');
		if (isset(self::PROGRAMS[$set])) {
			return ['code' => $set, 'how' => 'Set on the project', 'assumed' => false];
		}
		if ($dates['si_needed'] === 1) {
			return ['code' => 'solarinsure', 'how' => 'SolarInsure question: Yes', 'assumed' => false];
		}
		if ($dates['si_needed'] === 0) {
			return ['code' => 'trifecta', 'how' => 'SolarInsure question: No', 'assumed' => false];
		}
		return ['code' => 'trifecta', 'how' => 'Assumed: the SolarInsure question is not answered', 'assumed' => true];
	}

	/**
	 * The step dates warranties start from, for many projects at once.
	 * @return array<int, array> project id => dates
	 */
	public static function datesFor(array $projectIds): array
	{
		$out = [];
		foreach ($projectIds as $id) {
			$out[(int) $id] = self::blankDates();
		}
		if (!$out) {
			return [];
		}
		$in = implode(',', array_map('intval', array_keys($out)));
		$rows = Db::all(
			"SELECT t.project_id, t.name, t.gate, t.needed, t.done_date, t.note, pt.name AS parent_name
			 FROM project_tasks t LEFT JOIN project_tasks pt ON pt.id = t.parent_id
			 WHERE t.project_id IN ($in)
			   AND (t.gate IN ('start_clock', 'pto')
			     OR (t.parent_id IS NULL AND t.name IN ('Final inspection', 'SolarInsure registration', 'SolarEdge extended warranty'))
			     OR (pt.name = 'Installation' AND t.name = 'Completed'))"
		);
		foreach ($rows as $r) {
			$d = &$out[(int) $r['project_id']];
			$done = $r['done_date'] ?: null;
			$needed = $r['needed'] === null ? null : (int) $r['needed'];
			if ($r['gate'] === 'start_clock') {
				$d['contract'] = $d['contract'] ?? $done;
			} elseif ($r['gate'] === 'pto') {
				$d['pto'] = $d['pto'] ?? $done;
			} elseif ($r['parent_name'] === 'Installation') {
				$d['install'] = $d['install'] ?? $done;
			} elseif ($r['name'] === 'Final inspection') {
				$d['inspection'] = $d['inspection'] ?? $done;
			} elseif ($r['name'] === 'SolarInsure registration') {
				$d['si_needed'] = $needed;
				$d['si_done'] = $done;
				$d['si_note'] = $r['note'];
			} elseif ($r['name'] === 'SolarEdge extended warranty') {
				$d['se_needed'] = $needed;
				$d['se_done'] = $done;
				$d['se_note'] = $r['note'];
			}
			unset($d);
		}
		return $out;
	}

	private static function blankDates(): array
	{
		return ['contract' => null, 'install' => null, 'inspection' => null, 'pto' => null,
			'si_needed' => null, 'si_done' => null, 'si_note' => null, 'se_needed' => null, 'se_done' => null, 'se_note' => null];
	}

	/**
	 * Start date for a basis: [date, tag, how] or [null, null, why not].
	 * $installed: the system is in (completed, legacy or has PTO), so an estimate is allowed.
	 */
	public static function start(string $basis, array $d, ?string $typed, bool $installed): array
	{
		if ($typed) {
			return [$typed, 'actual', 'Typed date'];
		}
		$plus = static fn (string $date, int $months) => (new \DateTimeImmutable($date))->modify(($months >= 0 ? '+' : '') . $months . ' months')->format('Y-m-d');
		$chains = [
			'inspection' => [['inspection', 0, 'actual', 'Final inspection passed'], ['pto', -1, 'derived', 'PTO less a month'],
				['install', 0, 'derived', 'Installation completed']],
			'install' => [['install', 0, 'actual', 'Installation completed'], ['inspection', 0, 'derived', 'Final inspection passed'],
				['pto', -1, 'derived', 'PTO less a month']],
			'pto' => [['pto', 0, 'actual', 'PTO'], ['inspection', 1, 'derived', 'Final inspection plus a month'],
				['install', 1, 'derived', 'Installation completed plus a month']],
			'contract' => [['contract', 0, 'actual', 'Contract signed']],
		];
		foreach ($chains[$basis] ?? [] as [$key, $months, $tag, $how]) {
			if (!empty($d[$key])) {
				return [$months ? $plus($d[$key], $months) : $d[$key], $tag, $how];
			}
		}
		if ($installed && !empty($d['contract'])) {
			return [$plus($d['contract'], self::LAG_MONTHS), 'estimated', 'Contract signed plus ' . self::LAG_MONTHS . ' months'];
		}
		return [null, null, $installed ? 'No dates to work from' : 'Not installed yet'];
	}

	/** Every warranty line for one project. */
	public static function forProject(array $p): array
	{
		$dates = self::datesFor([(int) $p['id']])[(int) $p['id']];
		$stored = Db::all('SELECT * FROM project_warranties WHERE project_id = ? ORDER BY kind, id', [(int) $p['id']]);
		$inverters = Db::all('SELECT description FROM project_inverters WHERE project_id = ?', [(int) $p['id']]);
		return self::lines($p, $dates, $stored, array_column($inverters, 'description'));
	}

	/** True when the system is in: completed, legacy, or has PTO / install / inspection dates. */
	public static function installed(array $p, array $d): bool
	{
		return !empty($p['archived_at']) || !empty($p['is_legacy']) || $d['pto'] || $d['install'] || $d['inspection'];
	}

	/**
	 * @param string[] $inverters inverter descriptions (for the assumed SolarEdge extended term)
	 * @return array[] lines, program lines first
	 */
	public static function lines(array $p, array $d, array $stored, array $inverters): array
	{
		$installed = self::installed($p, $d);
		$prog = self::program($p, $d);
		$typed = $p['warranty_start'] ?? null;
		$out = [];
		if (in_array($prog['code'], ['trifecta', 'solarinsure'], true)) {
			$out[] = self::line([
				'key' => 'trifecta', 'kind' => 'trifecta', 'label' => 'Trifecta labor and workmanship', 'provider' => 'Trifecta Solar',
				'covers' => $prog['code'] === 'solarinsure' ? 'Wiring and balance of system, behind SolarInsure' : 'Installation workmanship and labor on manufacturer claims',
				'years' => self::TRIFECTA_YEARS, 'basis' => 'inspection',
			], self::start('inspection', $d, $typed, $installed));
		}
		if ($prog['code'] === 'solarinsure') {
			$late = $installed && $d['si_done'] === null;
			$out[] = self::line([
				'key' => 'solarinsure', 'kind' => 'solarinsure', 'label' => 'SolarInsure', 'provider' => 'SolarInsure',
				'covers' => 'Components, roof penetrations and labor', 'years' => self::SOLARINSURE_YEARS, 'basis' => 'install',
				'reference' => $d['si_done'] ? 'Registered ' . fmt_date($d['si_done']) : null,
				'flag' => $late ? 'Not registered yet' : null, 'note' => $d['si_note'],
			], self::start('install', $d, $typed, $installed));
		}
		if ($d['se_needed'] === 1) {
			[$years, $assumed] = self::solarEdgeYears((string) $d['se_note'], $inverters);
			$out[] = self::line([
				'key' => 'se_extended', 'kind' => 'inverter_extended', 'label' => 'SolarEdge extended', 'provider' => 'SolarEdge',
				'covers' => 'Inverter and optimizers, extended term', 'years' => $years, 'basis' => 'install', 'assumed' => $assumed,
				'reference' => $d['se_done'] ? 'Registered ' . fmt_date($d['se_done']) : null,
				'flag' => $installed && $d['se_done'] === null ? 'Not registered yet' : null,
				'note' => $assumed ? 'Term assumed from the inverters; put the years in the question\'s note to set it' : $d['se_note'],
			], self::start('install', $d, null, $installed));
		}
		foreach ($stored as $w) {
			$out[] = self::line([
				'key' => 'w' . $w['id'], 'id' => (int) $w['id'], 'kind' => $w['kind'], 'label' => self::KINDS[$w['kind']] ?? $w['kind'],
				'provider' => $w['provider'], 'covers' => $w['covers'], 'years' => (float) $w['term_years'], 'basis' => $w['start_basis'],
				'reference' => $w['reference'], 'cost_cents' => $w['cost_cents'] !== null ? (int) $w['cost_cents'] : null,
				'note' => $w['note'], 'source' => $w['source'], 'start_typed' => $w['start_date'],
				'guarantee_kwh' => $w['guarantee_kwh'] !== null ? (float) $w['guarantee_kwh'] : null,
				'guarantee_rate' => $w['guarantee_rate'] !== null ? (float) $w['guarantee_rate'] : null,
			], self::start($w['start_basis'], $d, $w['start_date'], $installed));
		}
		return $out;
	}

	/** [years, assumed] for a SolarEdge extended warranty. */
	public static function solarEdgeYears(string $note, array $inverters): array
	{
		if (preg_match('/\b(20|25)\b/', $note, $m)) {
			return [(int) $m[1], false];
		}
		foreach ($inverters as $i) {
			if (preg_match('/home hub|energy hub|H-US|\bSE\d{4,5}H\b/i', (string) $i)) {
				return [25, true];
			}
		}
		return [20, true];
	}

	private static function line(array $l, array $start): array
	{
		[$date, $tag, $how] = $start;
		$l += ['id' => null, 'provider' => null, 'covers' => null, 'reference' => null, 'cost_cents' => null, 'note' => null,
			'source' => 'program', 'assumed' => false, 'flag' => null, 'start_typed' => null, 'guarantee_kwh' => null, 'guarantee_rate' => null];
		$l['start'] = $date;
		$l['start_tag'] = $tag;
		$l['start_how'] = $how;
		$l['end'] = $date ? self::addYears($date, (float) $l['years']) : null;
		$l['months_left'] = $l['end'] ? self::monthsBetween(date('Y-m-d'), $l['end']) : null;
		$l['status'] = $date === null ? 'not_started' : ($l['months_left'] <= 0 ? 'expired' : 'active');
		return $l;
	}

	public static function addYears(string $date, float $years): string
	{
		return (new \DateTimeImmutable($date))->modify('+' . (int) round($years * 12) . ' months')->format('Y-m-d');
	}

	/** Whole months from $from to $to (negative when $to is earlier). */
	public static function monthsBetween(string $from, string $to): int
	{
		[$fy, $fm, $fd] = array_map('intval', explode('-', $from));
		[$ty, $tm, $td] = array_map('intval', explode('-', $to));
		$m = ($ty - $fy) * 12 + ($tm - $fm);
		if ($m > 0 && $td < $fd) {
			$m--;
		} elseif ($m < 0 && $td > $fd) {
			$m++;
		}
		return $m;
	}

	/** Lowercases the first letter for use mid-sentence, leaving acronyms (PTO) and names (SolarInsure) alone. */
	public static function lc(string $s): string
	{
		return preg_match('/^[A-Z][a-z]/', $s) && !str_starts_with($s, 'SolarInsure') && !str_starts_with($s, 'SolarEdge') ? lcfirst($s) : $s;
	}

	/** "Mar 2026", "~Mar 2026" when estimated. */
	public static function month(?string $date, ?string $tag = null): string
	{
		if (!$date) {
			return '';
		}
		return ($tag === 'estimated' ? '~' : '') . date('M Y', strtotime($date));
	}

	/** "3 yr 4 mo", "8 mo", "Expired". */
	public static function left(?int $months): string
	{
		if ($months === null) {
			return '';
		}
		if ($months <= 0) {
			return 'Expired';
		}
		$y = intdiv($months, 12);
		$m = $months % 12;
		return trim(($y ? $y . ' yr ' : '') . ($m ? $m . ' mo' : ''));
	}

	/**
	 * Every project with its lines, for the Warranty report. Cancelled projects are left out.
	 * @return array[] project rows with 'lines', 'program', 'kw', 'trifecta' (its line or null)
	 */
	public static function fleet(): array
	{
		$projects = Db::all(
			"SELECT p.id, p.project_number, p.name, p.archived_at, p.hold_state, p.is_legacy, p.warranty_program, p.warranty_start,
					p.customer_type, p.site_city, c.name AS customer_name
			 FROM projects p LEFT JOIN organizations c ON c.id = p.customer_id
			 WHERE p.hold_state IS NULL OR p.hold_state <> 'cancelled'"
		);
		$ids = array_column($projects, 'id');
		$dates = self::datesFor($ids);
		$stored = [];
		foreach (Db::all('SELECT * FROM project_warranties ORDER BY kind, id') as $w) {
			$stored[(int) $w['project_id']][] = $w;
		}
		$inv = [];
		foreach (Db::all('SELECT project_id, description FROM project_inverters') as $i) {
			$inv[(int) $i['project_id']][] = $i['description'];
		}
		$kw = Projects::dcKwMap();
		$out = [];
		foreach ($projects as $p) {
			$id = (int) $p['id'];
			$d = $dates[$id];
			$p['kw'] = (float) ($kw[$id] ?? 0);
			$p['program'] = self::program($p, $d);
			$p['installed'] = self::installed($p, $d);
			$p['lines'] = self::lines($p, $d, $stored[$id] ?? [], $inv[$id] ?? []);
			$p['trifecta'] = null;
			foreach ($p['lines'] as $l) {
				if ($l['key'] === 'trifecta') {
					$p['trifecta'] = $l;
				}
			}
			$active = array_filter($p['lines'], static fn ($l) => $l['status'] === 'active');
			usort($active, static fn ($a, $b) => strcmp($a['end'], $b['end']));
			$p['next'] = $active[0] ?? null;
			$out[] = $p;
		}
		return $out;
	}
}
