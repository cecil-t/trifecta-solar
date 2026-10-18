<?php
declare(strict_types=1);

namespace App;

/**
 * SAM's CEC module and inverter libraries (cec_modules.csv and cec_inverters.csv in Sam::libFolder(),
 * from the SAM release pinned in the Dockerfile). Read straight from the files: a search reads the
 * names, and a name is valid only when the library lists it exactly.
 */
final class CecLibrary
{
	public const FILES = ['modules' => 'cec_modules.csv', 'inverters' => 'cec_inverters.csv'];

	/** Which library a catalog category uses, or null when it has none. */
	public static function kindFor(string $category): ?string
	{
		return match ($category) {
			'modules' => 'modules',
			'inverters', 'mlpe' => 'inverters',
			default => null,
		};
	}

	/**
	 * Spec-sheet fields for items SAM's CEC library doesn't list: [key => [label, unit, min, max, required]].
	 * Watts (modules) and AC kW (inverters) come from the item's own fields.
	 */
	public const SPEC_MODULE = [
		'vmp' => ['Vmp', 'V', 1, 1500, true],
		'imp' => ['Imp', 'A', 0.1, 50, true],
		'voc' => ['Voc', 'V', 1, 1500, true],
		'isc' => ['Isc', 'A', 0.1, 50, true],
		'gamma_pmp_pct' => ['Pmax temperature coefficient', '%/°C', -1.5, 0, true],
		'beta_voc_pct' => ['Voc temperature coefficient', '%/°C', -1.5, 0, true],
		'alpha_isc_pct' => ['Isc temperature coefficient', '%/°C', 0, 0.5, true],
		'cells' => ['Cells in series', '', 10, 400, true],
		'noct' => ['NOCT (or NMOT)', '°C', 30, 70, true],
		'length_mm' => ['Length', 'mm', 300, 4000, true],
		'width_mm' => ['Width', 'mm', 300, 3000, true],
	];
	public const SPEC_INVERTER = [
		'eff_pct' => ['CEC weighted efficiency', '%', 80, 99.9, true],
		'vdc_nominal' => ['Nominal DC voltage', 'V', 20, 1500, true],
		'mppt_low' => ['MPPT voltage, low', 'V', 0, 1500, false],
		'mppt_high' => ['MPPT voltage, high', 'V', 0, 1500, false],
		'vdc_max' => ['Maximum DC voltage', 'V', 0, 2000, false],
	];
	public const CELL_TECH = ['mono' => 'Monocrystalline', 'poly' => 'Polycrystalline', 'cdte' => 'Thin film CdTe', 'cigs' => 'Thin film CIGS'];

	/**
	 * Spec-sheet values from a form ($in: field => typed value), checked, or null when the item
	 * has no spec fields. Throws with a readable message.
	 */
	public static function specFromInput(string $kind, array $in, ?float $watts): ?array
	{
		$fields = $kind === 'modules' ? self::SPEC_MODULE : self::SPEC_INVERTER;
		$out = [];
		foreach ($fields as $k => [$label, $unit, $min, $max, $required]) {
			$raw = trim(str_replace(',', '', (string) ($in[$k] ?? '')));
			if ($raw === '') {
				if ($required) {
					throw new \InvalidArgumentException('Spec sheet: ' . $label . ' is required.');
				}
				continue;
			}
			if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
				throw new \InvalidArgumentException('Spec sheet: ' . $label . ' must be a number from ' . $min . ' to ' . $max . ($unit !== '' ? ' ' . $unit : '') . '.');
			}
			$out[$k] = $k === 'cells' ? (int) $raw : (float) $raw;
		}
		if ($kind === 'modules') {
			if ($out['vmp'] >= $out['voc'] || $out['imp'] >= $out['isc']) {
				throw new \InvalidArgumentException('Spec sheet: Vmp must be below Voc, and Imp below Isc.');
			}
			if ($watts && abs($out['vmp'] * $out['imp'] - $watts) / $watts > 0.05) {
				throw new \InvalidArgumentException('Spec sheet: Vmp x Imp is ' . round($out['vmp'] * $out['imp']) . ' W, more than 5% off the module\'s ' . round($watts) . ' W. Check the values.');
			}
			$tech = (string) ($in['tech'] ?? 'mono');
			$out['tech'] = isset(self::CELL_TECH[$tech]) ? $tech : 'mono';
			$out['bifacial'] = empty($in['bifacial']) ? 0 : 1;
		} elseif (isset($out['mppt_low'], $out['mppt_high']) && $out['mppt_low'] >= $out['mppt_high']) {
			throw new \InvalidArgumentException('Spec sheet: the MPPT low voltage must be below the high one.');
		}
		return $out;
	}

	/** One line describing saved spec-sheet values, for logs and the catalog list. */
	public static function specSummary(?string $json): string
	{
		$s = json_decode((string) $json, true);
		if (!is_array($s) || !$s) {
			return '';
		}
		if (isset($s['vmp'])) {
			return sprintf('Vmp %s V, Imp %s A, Voc %s V, Isc %s A, Pmax %s%%/°C, %d cells, %s x %s mm',
				$s['vmp'], $s['imp'], $s['voc'], $s['isc'], $s['gamma_pmp_pct'], $s['cells'], $s['length_mm'], $s['width_mm']);
		}
		return sprintf('CEC efficiency %s%%, nominal %s V DC', $s['eff_pct'] ?? '', $s['vdc_nominal'] ?? '');
	}

	public static function available(): bool
	{
		return is_file(self::path('modules')) && is_file(self::path('inverters'));
	}

	private static function path(string $kind): string
	{
		return Sam::libFolder() . '/' . self::FILES[$kind];
	}

	/** Lowercase letters and digits only, so "Q.TRON M-G2+" matches "qtron mg2". */
	public static function norm(string $s): string
	{
		return (string) preg_replace('/[^a-z0-9]/', '', strtolower($s));
	}

	/**
	 * Library rows whose name holds every word of the query (punctuation ignored), modules first.
	 *
	 * @return list<array{id:string, name:string, kind:string}>  in the picker's shape
	 */
	public static function search(string $q, int $limit = 25, ?string $only = null): array
	{
		$words = array_values(array_filter(array_map([self::class, 'norm'], preg_split('/\s+/', trim($q)) ?: [])));
		if (!$words) {
			return [];
		}
		$out = [];
		foreach (self::index() as $line) {
			[$kind, $name, $desc, $n] = explode("\t", $line);
			if ($only !== null && $only !== $kind) {
				continue;
			}
			foreach ($words as $w) {
				if (!str_contains($n, $w)) {
					continue 2;
				}
			}
			$out[] = ['id' => $name, 'name' => $name, 'kind' => $desc];
			if (count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Every library name as "kind, name, description, normalized name" lines, built once per
	 * library version into a file next to the database (reading the CSVs takes about half a second).
	 *
	 * @return list<string>
	 */
	private static function index(): array
	{
		$stamp = '';
		foreach (self::FILES as $kind => $file) {
			$stamp .= is_file(self::path($kind)) ? filemtime(self::path($kind)) . filesize(self::path($kind)) : '0';
		}
		$cache = dirname(Db::path()) . '/cec-index-' . substr(sha1($stamp), 0, 12) . '.tsv';
		if (is_file($cache)) {
			return file($cache, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		}
		$lines = [];
		foreach (self::FILES as $kind => $file) {
			foreach (self::rows($kind) as $r) {
				$lines[] = $kind . "\t" . str_replace("\t", ' ', $r['name']) . "\t" . self::describe($kind, $r) . "\t" . self::norm($r['name']);
			}
		}
		if ($lines) {
			foreach (glob(dirname(Db::path()) . '/cec-index-*.tsv') ?: [] as $old) {
				@unlink($old);
			}
			@file_put_contents($cache . '.tmp', implode("\n", $lines) . "\n") && @rename($cache . '.tmp', $cache);
		}
		return $lines;
	}

	/** One entry by its exact library name ({name, description}), or null. */
	public static function find(string $kind, string $name): ?array
	{
		if ($name === '' || !isset(self::FILES[$kind])) {
			return null;
		}
		foreach (self::index() as $line) {
			[$k, $n, $desc] = explode("\t", $line);
			if ($k === $kind && $n === $name) {
				return ['name' => $n, 'description' => $desc];
			}
		}
		return null;
	}

	/**
	 * A module's size from the library (Length and Width, meters), or null when the library has none
	 * (most entries leave them blank).
	 *
	 * @return array{0:float, 1:float}|null  length, width in millimeters
	 */
	public static function moduleSize(string $name): ?array
	{
		$path = self::path('modules');
		if ($name === '' || !is_file($path) || !($f = fopen($path, 'r'))) {
			return null;
		}
		$col = array_flip(fgetcsv($f, null, ',', '"', '') ?: []);
		$out = null;
		while (($row = fgetcsv($f, null, ',', '"', '')) !== false) {
			if (($row[0] ?? '') === $name) {
				$l = (float) ($row[$col['Length'] ?? -1] ?? 0);
				$w = (float) ($row[$col['Width'] ?? -1] ?? 0);
				$out = $l > 0.3 && $w > 0.3 ? [max($l, $w) * 1000, min($l, $w) * 1000] : null;
				break;
			}
		}
		fclose($f);
		return $out;
	}

	/** Short description for a picker line: module watts and bifacial, or inverter kW AC and voltage. */
	public static function describe(string $kind, array $r): string
	{
		if ($kind === 'modules') {
			return 'Module, ' . rtrim(rtrim(number_format((float) $r['stc'], 1, '.', ''), '0'), '.') . ' W' . ($r['bifacial'] ? ', bifacial' : '');
		}
		return 'Inverter, ' . rtrim(rtrim(number_format((float) $r['paco'] / 1000, 2, '.', ''), '0'), '.') . ' kW AC, ' . $r['vac'] . ' V';
	}

	/**
	 * The rows of one library: name plus the few values the app shows (modules: STC watts,
	 * bifacial; inverters: Paco watts, AC voltage).
	 *
	 * @return \Generator<array>
	 */
	private static function rows(string $kind): \Generator
	{
		$path = self::path($kind);
		if (!is_file($path)) {
			return;
		}
		$f = fopen($path, 'r');
		if (!$f) {
			return;
		}
		$head = fgetcsv($f, null, ',', '"', '') ?: [];
		fgetcsv($f, null, ',', '"', '');   // units
		fgetcsv($f, null, ',', '"', '');   // SAM variable names
		$col = array_flip($head);
		while (($row = fgetcsv($f, null, ',', '"', '')) !== false) {
			if (!isset($row[0]) || $row[0] === '') {
				continue;
			}
			yield $kind === 'modules'
				? ['name' => $row[0], 'stc' => (float) ($row[$col['STC'] ?? 4] ?? 0), 'bifacial' => (int) ($row[$col['Bifacial'] ?? 3] ?? 0)]
				: ['name' => $row[0], 'paco' => (float) ($row[$col['Paco'] ?? 3] ?? 0), 'vac' => (string) ($row[$col['Vac'] ?? 1] ?? '')];
		}
		fclose($f);
	}
}
