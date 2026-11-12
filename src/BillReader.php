<?php
declare(strict_types=1);

namespace App;

/**
 * Reads an electric bill (a PDF, or one or more photos) with Claude and returns the opportunity's
 * utility bill fields. Used by Read a bill on the opportunity form and by website quote requests.
 * The files are sent to the Claude API and never stored here; the person checks what was found
 * before anything saves.
 *
 * Photos go through bin/image_prep.py first (HEIC to JPEG, rotation, at most 1568 px on the long
 * edge, which is all Claude looks at anyway). PDFs go as they are.
 */
final class BillReader
{
	public const MAX_FILES = 8;
	public const MAX_BYTES = 15 * 1024 * 1024;
	public const PREP_TIMEOUT = 30;

	private const SYSTEM = <<<'TXT'
		You read US electric utility bills for a solar installer in Pennsylvania, Maryland and Delaware.
		The pages or photos you are given are one customer's bills (possibly several pages each, possibly photographed at an angle).
		Return one entry in bills for each separate bill: a different account, or the same account for a different billing period. Pages or photos of the same bill are one entry. Keep the order the files came in.
		Copy values as printed. Never guess or work out a value the bill does not show: use an empty string instead.
		Numbers: digits and a decimal point only, no dollar signs, commas or units.
		Dates: YYYY-MM-DD.
		account_holder: the customer name on the account.
		service_address: the address of the service location (where the meter is), as one line; not the mailing address unless the bill shows only one address.
		utility: the electric distribution company that sends the bill (e.g. PPL Electric Utilities, PECO, Met-Ed).
		rate_name: the rate or rate schedule as printed (e.g. "RS Residential Service", "R - TOU").
		kwh_this_period: total kWh used in this billing period (sum the meters only if the bill does).
		current_charges: this period's new electric charges (delivery plus supply), not including any previous balance, payments, late fees from earlier periods, or non-electric items such as payment plans.
		customer_charge: the fixed monthly customer charge or basic service charge, in dollars.
		price_to_compare_cents: the Price to Compare as printed, in cents per kWh.
		supplier: the electric generation supplier if the bill names one other than the utility; empty if the utility supplies generation.
		annual_kwh: a 12-month total only when the bill prints one.
		usage_history: monthly kWh only when the numbers are printed as numbers (a table or labeled bars); never read values off an unlabeled bar chart. Month as YYYY-MM for the month the reading ends in.
		demand_kw: billed demand in kW, commercial bills only.
		uncertain: names of fields you filled but could not read clearly.
		is_bill: "yes" if this is an electric utility bill, otherwise "no".
		notes: one short sentence only when something matters for using this bill's numbers (e.g. estimated reading, two meters on one account, net metered, a first bill covering a partial month). Otherwise empty.
		TXT;

	/** Fields Claude returns; every one is a string so the schema stays simple (empty when absent). */
	private const FIELDS = [
		'is_bill', 'account_holder', 'service_address', 'utility', 'account_number', 'meter_number', 'rate_name',
		'period_start', 'period_end', 'kwh_this_period', 'current_charges', 'customer_charge', 'price_to_compare_cents',
		'supplier', 'annual_kwh', 'demand_kw', 'notes',
	];

	public static function schema(): array
	{
		$props = [];
		foreach (self::FIELDS as $f) {
			$props[$f] = ['type' => 'string'];
		}
		$props['usage_history'] = ['type' => 'array', 'items' => [
			'type' => 'object',
			'properties' => ['month' => ['type' => 'string'], 'kwh' => ['type' => 'string']],
			'required' => ['month', 'kwh'], 'additionalProperties' => false,
		]];
		$props['uncertain'] = ['type' => 'array', 'items' => ['type' => 'string']];
		$bill = ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
		return ['type' => 'object', 'properties' => ['bills' => ['type' => 'array', 'items' => $bill]], 'required' => ['bills'], 'additionalProperties' => false];
	}

	/** Uploaded files from $_FILES[$key] (single or multiple) as [name, bytes]. */
	public static function fromUpload(string $key): array
	{
		$f = $_FILES[$key] ?? null;
		if (!$f) {
			return [];
		}
		$names = (array) $f['name'];
		$out = [];
		foreach ($names as $i => $name) {
			$err = ((array) $f['error'])[$i] ?? UPLOAD_ERR_NO_FILE;
			if ($err === UPLOAD_ERR_NO_FILE) {
				continue;
			}
			if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
				throw new \InvalidArgumentException($name . ' is too large (15 MB at most).');
			}
			if ($err !== UPLOAD_ERR_OK) {
				throw new \InvalidArgumentException($name . ' did not upload. Try again.');
			}
			$tmp = ((array) $f['tmp_name'])[$i];
			$out[] = ['name' => (string) $name, 'bytes' => (string) file_get_contents($tmp)];
		}
		return $out;
	}

	/**
	 * Read the bill. Returns ok and fields (ready for the opportunity form) plus raw (what Claude
	 * found) and cost, or ok false and error.
	 *
	 * @param list<array{name:string, bytes:string}> $files
	 */
	public static function read(array $files, string $purpose = 'bill'): array
	{
		if (!$files) {
			return ['ok' => false, 'error' => 'Choose the bill: a PDF, or photos of its pages.'];
		}
		if (count($files) > self::MAX_FILES) {
			return ['ok' => false, 'error' => 'Up to ' . self::MAX_FILES . ' files at a time.'];
		}
		$content = [];
		foreach ($files as $f) {
			$block = self::block($f['name'], $f['bytes']);
			if (is_string($block)) {
				return ['ok' => false, 'error' => $block];
			}
			$content[] = $block;
		}
		$content[] = ['type' => 'text', 'text' => 'Read these electric bills and fill in the fields, one entry per bill.'];
		$r = Claude::ask($purpose, trim(self::SYSTEM), $content, self::schema(), 6000, 180);
		if (!$r['ok']) {
			return ['ok' => false, 'error' => $r['error'], 'cost' => $r['cost']];
		}
		$bills = [];
		foreach ((array) ($r['json']['bills'] ?? []) as $item) {
			$raw = self::clean((array) $item);
			if (($raw['is_bill'] ?? '') !== 'no') {
				$bills[] = ['raw' => $raw, 'fields' => self::fields($raw), 'summary' => self::summary($raw)];
			}
		}
		if (!$bills) {
			return ['ok' => false, 'error' => 'That doesn\'t look like an electric bill.', 'cost' => $r['cost']];
		}
		return ['ok' => true, 'bills' => $bills, 'cost' => $r['cost'], 'model' => $r['model'],
			'tokens' => $r['input_tokens'] + $r['output_tokens']];
	}

	/** One content block for a file, or an error message. */
	private static function block(string $name, string $bytes): array|string
	{
		$label = $name !== '' ? $name : 'The file';
		if ($bytes === '') {
			return $label . ' is empty.';
		}
		if (strlen($bytes) > self::MAX_BYTES) {
			return $label . ' is too large (15 MB at most).';
		}
		$kind = self::kind($bytes);
		if ($kind === 'pdf') {
			return ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]];
		}
		if ($kind === null) {
			return $label . ' is not a PDF or a photo (JPG, PNG, HEIC or WebP).';
		}
		$jpeg = self::prepImage($bytes);
		if (is_string($jpeg) && !str_starts_with($jpeg, "\xFF\xD8")) {
			// No Python here (development): send JPEG and PNG as they are when small enough
			if ($kind === 'heic') {
				return $label . ': ' . $jpeg;
			}
			if (strlen($bytes) > 4 * 1024 * 1024) {
				return $label . ' is too large to send without resizing, and resizing is unavailable: ' . $jpeg;
			}
			return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/' . $kind, 'data' => base64_encode($bytes)]];
		}
		return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg)]];
	}

	/** pdf, jpeg, png, webp, heic, or null, from the file's first bytes. */
	public static function kind(string $bytes): ?string
	{
		return match (true) {
			str_starts_with($bytes, '%PDF-') => 'pdf',
			str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpeg',
			str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'png',
			str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'webp',
			substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1', 'avif'], true) => 'heic',
			default => null,
		};
	}

	/** The photo as a JPEG (bytes starting FF D8), or an error message. */
	private static function prepImage(string $bytes): string
	{
		$py = Pdf::python();
		if (!is_executable($py)) {
			return 'Photo conversion is not installed on this server (no ' . $py . '). Rebuild the Docker image.';
		}
		$r = Process::run([$py, '-I', APP_ROOT . '/bin/image_prep.py'], $bytes, self::PREP_TIMEOUT,
			['PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'HOME' => sys_get_temp_dir()]);
		if ($r['timed_out']) {
			return 'Converting the photo took too long.';
		}
		if ($r['code'] !== 0 || !str_starts_with($r['out'], "\xFF\xD8")) {
			$why = trim(substr($r['err'], -300));
			return str_contains($why, 'cannot identify') ? 'This photo format can\'t be read here (HEIC needs the updated server image).' : ($why !== '' ? $why : 'The photo could not be converted.');
		}
		return $r['out'];
	}

	/** Trimmed strings, numbers stripped of $ and commas. */
	private static function clean(array $j): array
	{
		$out = [];
		foreach (self::FIELDS as $f) {
			$out[$f] = trim((string) ($j[$f] ?? ''));
		}
		foreach (['kwh_this_period', 'current_charges', 'customer_charge', 'price_to_compare_cents', 'annual_kwh', 'demand_kw'] as $f) {
			$v = str_replace([',', '$', ' '], '', $out[$f]);
			$out[$f] = is_numeric($v) ? $v : '';
		}
		$out['usage_history'] = [];
		foreach ((array) ($j['usage_history'] ?? []) as $h) {
			$kwh = str_replace([',', ' '], '', (string) ($h['kwh'] ?? ''));
			if (preg_match('/^(\d{4})-(\d{2})/', (string) ($h['month'] ?? ''), $m) && is_numeric($kwh) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
				$out['usage_history'][] = ['month' => $m[1] . '-' . $m[2], 'kwh' => (float) $kwh];
			}
		}
		$out['uncertain'] = array_values(array_filter(array_map('strval', (array) ($j['uncertain'] ?? []))));
		return $out;
	}

	/**
	 * What the bill gives the opportunity form, keyed by form field name. Yearly use is the printed
	 * 12-month total, else the 12 most recent months of the printed history when there are 12.
	 */
	public static function fields(array $raw): array
	{
		$f = [
			'bill_name' => self::tidyName((string) ($raw['account_holder'] ?? '')),
			'service_address' => $raw['service_address'] ?? '',
			'utility_text' => $raw['utility'] ?? '',
			'utility_id' => self::matchUtility((string) ($raw['utility'] ?? '')),
			'utility_account' => $raw['account_number'] ?? '',
			'utility_meter' => $raw['meter_number'] ?? '',
			'utility_tariff' => $raw['rate_name'] ?? '',
			'bill_kwh' => $raw['kwh_this_period'] ?? '',
			'bill_total' => $raw['current_charges'] ?? '',
			'bill_charge' => $raw['customer_charge'] ?? '',
			'bill_months' => ($raw['kwh_this_period'] ?? '') !== '' ? '1' : '',
			'ptc_cents' => $raw['price_to_compare_cents'] ?? '',
			'annual_usage_kwh' => '',
			'monthly_usage' => [],
		];
		// Latest reading per month, newest 12 months
		$byMonth = [];
		foreach ($raw['usage_history'] ?? [] as $h) {
			$byMonth[$h['month']] = $h['kwh'];
		}
		krsort($byMonth);
		$last12 = array_slice($byMonth, 0, 12, true);
		if (count($last12) === 12) {
			$months = array_fill(0, 12, null);
			foreach ($last12 as $ym => $kwh) {
				$months[(int) substr($ym, 5, 2) - 1] = (string) round($kwh);
			}
			if (!in_array(null, $months, true)) {
				$f['monthly_usage'] = $months;
				$f['annual_usage_kwh'] = (string) round(array_sum(array_map('floatval', $months)));
			}
		}
		if (($raw['annual_kwh'] ?? '') !== '') {
			$f['annual_usage_kwh'] = (string) round((float) $raw['annual_kwh']);
		}
		// A price to compare in dollars (0.1176) is moved to cents
		if ($f['ptc_cents'] !== '' && (float) $f['ptc_cents'] < 1) {
			$f['ptc_cents'] = (string) round((float) $f['ptc_cents'] * 100, 4);
		}
		return $f;
	}

	/**
	 * One line with a bill's basic information, for the opportunity's Notes when it is not the
	 * primary bill: utility, account, meter, rate, service address, name, use and charges.
	 */
	public static function summary(array $raw): string
	{
		$money = static fn (string $v): string => '$' . number_format((float) $v, 2);
		$date = static fn (string $v): string => ($t = strtotime($v)) ? date('m/d/Y', $t) : $v;
		$f = self::fields($raw);
		$parts = array_filter([
			$raw['utility'],
			$raw['account_number'] !== '' ? 'account ' . $raw['account_number'] : '',
			$raw['meter_number'] !== '' ? 'meter ' . $raw['meter_number'] : '',
			$raw['rate_name'] !== '' ? 'rate ' . $raw['rate_name'] : '',
			$raw['service_address'] !== '' ? 'service address ' . $raw['service_address'] : '',
			$f['bill_name'] !== '' ? 'name on bill ' . $f['bill_name'] : '',
			$f['annual_usage_kwh'] !== '' ? number_format((float) $f['annual_usage_kwh']) . ' kWh in the last 12 months' : '',
			$raw['kwh_this_period'] !== '' ? trim(($raw['period_start'] !== '' ? 'billing period ' . $date($raw['period_start']) . ' to ' . $date($raw['period_end']) . ': ' : 'this bill: ')
				. number_format((float) $raw['kwh_this_period']) . ' kWh' . ($raw['current_charges'] !== '' ? ', current charges ' . $money($raw['current_charges']) : '')) : '',
			$raw['customer_charge'] !== '' ? 'customer charge ' . $money($raw['customer_charge']) . ' a month' : '',
			$raw['price_to_compare_cents'] !== '' ? 'Price to Compare ' . $raw['price_to_compare_cents'] . ' cents per kWh' : '',
			$raw['demand_kw'] !== '' ? 'billed demand ' . $raw['demand_kw'] . ' kW' : '',
			$raw['supplier'] !== '' ? 'generation from ' . $raw['supplier'] : '',
		], static fn ($p) => $p !== '');
		return implode('; ', $parts) . '.';
	}

	/** "Second utility bill provided by customer: ..." for the bills after the primary one, in order. */
	public static function otherBillsNote(array $bills, int $primary): string
	{
		$ordinals = ['Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth'];
		$lines = [];
		foreach ($bills as $i => $b) {
			if ($i !== $primary) {
				$lines[] = ($ordinals[count($lines)] ?? 'Another') . ' utility bill provided by customer: ' . (is_string($b['summary'] ?? null) ? $b['summary'] : self::summary($b['raw']));
			}
		}
		return implode("\n", $lines);
	}

	/** "JOHN Q SMITH" reads better as "John Q Smith"; mixed case is left as printed. */
	private static function tidyName(string $s): string
	{
		return $s !== '' && $s === mb_strtoupper($s) ? mb_convert_case(mb_strtolower($s), MB_CASE_TITLE) : $s;
	}

	/** Our Utilities list entry for the utility name on the bill, or null. */
	public static function matchUtility(string $name): ?int
	{
		$norm = static fn (string $s): string => preg_replace('/[^a-z0-9]/', '', strtolower(str_replace('&', '', $s))) ?? '';
		$theirs = $norm($name);
		if ($theirs === '') {
			return null;
		}
		$aliases = ['metropolitanedison' => 'meted', 'pennsylvaniaelectric' => 'penelec', 'ppl' => 'ppl', 'pecoenergy' => 'peco', 'westpennpower' => 'westpenn'];
		foreach ($aliases as $from => $to) {
			if (str_contains($theirs, $from)) {
				$theirs .= ' ' . $to;
			}
		}
		$generic = ['west', 'penn', 'electric', 'power', 'energy', 'company', 'the', 'utilities', 'pennsylvania', 'rural'];
		$hits = [];
		foreach (Db::all('SELECT id, name FROM utilities WHERE is_active = 1') as $u) {
			$ours = $norm((string) $u['name']);
			$first = $norm((string) (preg_split('/[\s,]+/', (string) $u['name'])[0] ?? ''));
			if ($ours !== '' && str_contains($theirs, $ours)) {
				$hits[(int) $u['id']] = strlen($ours) + 100;
			} elseif (strlen($first) >= 3 && !in_array($first, $generic, true) && str_contains($theirs, $first)) {
				$hits[(int) $u['id']] = strlen($first);
			}
		}
		if (!$hits) {
			return null;
		}
		arsort($hits);
		return (int) array_key_first($hits);
	}
}
