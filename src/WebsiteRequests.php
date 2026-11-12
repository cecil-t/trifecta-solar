<?php
declare(strict_types=1);

namespace App;

/**
 * Quote requests from the company website's Gravity Forms form, read through the Gravity Forms
 * REST API v2 with a read-only key (GF_SITE, GF_FORM_ID, GF_CONSUMER_KEY, GF_CONSUMER_SECRET in
 * .env). website:poll runs from cron every 15 minutes and works only when due: weekdays 8 AM to
 * 6 PM, weekends once from noon. New entries are kept once each, an uploaded bill is read with
 * Claude, and each request waits as Pending at the top of the Sales list.
 */
final class WebsiteRequests
{
	public const ENV = ['GF_SITE', 'GF_FORM_ID', 'GF_CONSUMER_KEY', 'GF_CONSUMER_SECRET'];

	/** The form's field IDs (GET /forms/{id} lists them; Lists > Website and AI shows the form). */
	public const FIELDS = [
		'name' => '1', 'phone' => '3', 'email' => '4', 'project_type' => '5', 'share' => '9', 'upload' => '6',
		'address' => '10', 'utility' => '11', 'utility_other' => '18', 'yearly_kwh' => '12', 'rate_plan' => '13',
		'period_kwh' => '15', 'current_charges' => '16', 'customer_charge' => '17', 'notes' => '7',
	];

	public const PAGE_SIZE = 25;
	public const MAX_PAGES = 8;
	public const MAX_BILL_TRIES = 3;

	public static function configured(): bool
	{
		foreach (self::ENV as $k) {
			if ((string) Config::get($k, '') === '') {
				return false;
			}
		}
		return true;
	}

	private static function base(): string
	{
		return rtrim((string) Config::get('GF_SITE'), '/') . '/wp-json/gf/v2';
	}

	private static function auth(): string
	{
		return 'Authorization: Basic ' . base64_encode(Config::get('GF_CONSUMER_KEY') . ':' . Config::get('GF_CONSUMER_SECRET'));
	}

	public static function formId(): int
	{
		return (int) Config::get('GF_FORM_ID', '0');
	}

	/** GET a path under /wp-json/gf/v2. Returns ok and json, or error. */
	public static function get(string $path): array
	{
		if (!self::configured()) {
			return ['ok' => false, 'error' => 'The website form key is not set (GF_ lines in the server .env file).'];
		}
		$r = Http::request('GET', self::base() . $path, [self::auth()], '', 30);
		if ($r['status'] === 200 && is_array($r['json'])) {
			return ['ok' => true, 'json' => $r['json'], 'ms' => $r['ms']];
		}
		$msg = is_array($r['json']) ? (string) ($r['json']['message'] ?? '') : '';
		return ['ok' => false, 'error' => $r['status'] === 0
			? 'Could not reach the website: ' . ($r['error'] ?? 'no answer')
			: 'The website answered ' . $r['status'] . ($msg !== '' ? ': ' . $msg : '') . ($r['status'] === 401 ? ' (check the GF_ key lines in .env)' : '')];
	}

	/** One page of the form's newest entries. */
	public static function entries(int $page = 1, int $size = self::PAGE_SIZE): array
	{
		return self::get('/forms/' . self::formId() . '/entries?' . http_build_query([
			'paging' => ['page_size' => $size, 'current_page' => $page],
			'sorting' => ['key' => 'id', 'direction' => 'DESC', 'is_numeric' => 'true'],
		]));
	}

	// ------------------------------------------------------------------ schedule

	/** Due now? Weekdays 8:00 to 18:00; Saturday and Sunday once, the first run from noon on. */
	public static function due(\DateTimeImmutable $now): bool
	{
		$dow = (int) $now->format('N');
		$hm = (int) $now->format('Hi');
		if ($dow <= 5) {
			return $hm >= 800 && $hm <= 1800;
		}
		if ($hm < 1200) {
			return false;
		}
		$noonUtc = $now->setTime(12, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
		return !Db::value('SELECT 1 FROM website_polls WHERE ok = 1 AND started_at >= ?', [$noonUtc]);
	}

	public static function lastRun(bool $okOnly = false): ?array
	{
		return Db::one('SELECT * FROM website_polls' . ($okOnly ? ' WHERE ok = 1' : '') . ' ORDER BY id DESC LIMIT 1');
	}

	// ------------------------------------------------------------------ poll

	/**
	 * Fetch new entries, keep each once, and read their bills. Returns ok, new (count), read
	 * (bills read), messages, or error.
	 */
	public static function poll(bool $forced = false, bool $dryRun = false): array
	{
		$log = [];
		$runId = $dryRun ? 0 : Db::insert('website_polls', ['started_at' => now_utc(), 'forced' => $forced ? 1 : 0]);
		$new = 0;
		try {
			$formId = self::formId();
			$seen = array_flip(array_map('intval', array_column(Db::all('SELECT entry_id FROM web_requests WHERE source = ? AND form_id = ?', ['gravity_forms', $formId]), 'entry_id')));
			for ($page = 1; $page <= self::MAX_PAGES; $page++) {
				$r = self::entries($page);
				if (!$r['ok']) {
					throw new \RuntimeException($r['error']);
				}
				$list = (array) ($r['json']['entries'] ?? []);
				$fresh = 0;
				foreach ($list as $entry) {
					$eid = (int) ($entry['id'] ?? 0);
					if (!$eid || isset($seen[$eid]) || ($entry['status'] ?? 'active') !== 'active') {
						continue;
					}
					$fresh++;
					$data = self::map($entry);
					$log[] = 'New request #' . $eid . ': ' . ($data['name'] !== '' ? $data['name'] : '(no name)') . ($data['files'] ? ', ' . count($data['files']) . ' bill file(s)' : '');
					if (!$dryRun) {
						$files = $data['files'];
						unset($data['files']);
						Db::insert('web_requests', [
							'form_id' => $formId, 'entry_id' => $eid, 'submitted_at' => self::utc((string) ($entry['date_created'] ?? '')),
							'data_json' => json_encode($data), 'files_json' => $files ? json_encode($files) : null,
						]);
						$new++;
					}
					$seen[$eid] = true;
				}
				// Newest first: a page with nothing new, or the last page, means we have caught up
				if ($fresh === 0 || count($list) < self::PAGE_SIZE) {
					break;
				}
			}
			$read = $dryRun ? 0 : self::readPending($log);
			if ($runId) {
				Db::update('website_polls', $runId, ['finished_at' => now_utc(), 'ok' => 1, 'new_count' => $new]);
			}
			return ['ok' => true, 'new' => $dryRun ? count(array_filter($log, static fn ($l) => str_starts_with($l, 'New'))) : $new, 'read' => $read, 'log' => $log];
		} catch (\Throwable $e) {
			if ($runId) {
				Db::update('website_polls', $runId, ['finished_at' => now_utc(), 'ok' => 0, 'new_count' => $new, 'error' => mb_substr($e->getMessage(), 0, 500)]);
			}
			return ['ok' => false, 'error' => $e->getMessage(), 'new' => $new, 'log' => $log];
		}
	}

	/** Read the bills of pending requests not read yet (a few tries each). Returns how many were read. */
	public static function readPending(array &$log = []): int
	{
		if (!Claude::configured()) {
			return 0;
		}
		$n = 0;
		foreach (Db::all("SELECT id FROM web_requests WHERE status = 'pending' AND files_json IS NOT NULL AND bill_json IS NULL AND bill_attempts < ? ORDER BY id", [self::MAX_BILL_TRIES]) as $row) {
			$r = self::readBill((int) $row['id']);
			$log[] = 'Request ' . $row['id'] . ': ' . ($r['ok'] ? 'bill read' : 'bill not read: ' . $r['error']);
			$n += $r['ok'] ? 1 : 0;
		}
		return $n;
	}

	/** Download a request's bill files from the website and read them. Saves the result on the request. */
	public static function readBill(int $id): array
	{
		$req = Db::one('SELECT * FROM web_requests WHERE id = ?', [$id]);
		$urls = $req ? (json_decode((string) $req['files_json'], true) ?: []) : [];
		if (!$urls) {
			return ['ok' => false, 'error' => 'This request has no bill attached.'];
		}
		$files = [];
		foreach ($urls as $i => $url) {
			$d = self::download((string) $url);
			if (!$d['ok']) {
				return self::billFailed($id, (int) $req['bill_attempts'], 'File ' . ($i + 1) . ': ' . $d['error']);
			}
			$files[] = ['name' => basename((string) parse_url((string) $url, PHP_URL_PATH)) ?: 'bill', 'bytes' => $d['bytes']];
		}
		$r = BillReader::read($files, 'bill');
		if (!$r['ok']) {
			return self::billFailed($id, (int) $req['bill_attempts'], $r['error']);
		}
		Db::update('web_requests', $id, ['bill_json' => json_encode(['fields' => $r['fields'], 'raw' => $r['raw']]), 'bill_error' => null,
			'bill_read_at' => now_utc(), 'bill_attempts' => (int) $req['bill_attempts'] + 1]);
		return ['ok' => true];
	}

	private static function billFailed(int $id, int $tries, string $error): array
	{
		Db::update('web_requests', $id, ['bill_error' => mb_substr($error, 0, 500), 'bill_attempts' => $tries + 1]);
		return ['ok' => false, 'error' => $error];
	}

	/** A bill file from the website (an upload link from the form), with the API key sent along. */
	public static function download(string $url): array
	{
		$site = parse_url((string) Config::get('GF_SITE'), PHP_URL_HOST);
		$host = parse_url($url, PHP_URL_HOST);
		if (!$host || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || !self::sameSite((string) $host, (string) $site)) {
			return ['ok' => false, 'error' => 'The file link is not on the company website.'];
		}
		$r = Http::request('GET', $url, ['Accept: */*', self::auth()], '', 60);
		if ($r['status'] !== 200 || $r['body'] === '') {
			return ['ok' => false, 'error' => 'The website answered ' . ($r['status'] ?: 'nothing') . ' for the file.'];
		}
		if (strlen($r['body']) > BillReader::MAX_BYTES) {
			return ['ok' => false, 'error' => 'The file is too large (15 MB at most).'];
		}
		$type = '';
		foreach ($r['headers'] as $h) {
			if (stripos($h, 'Content-Type:') === 0) {
				$type = trim(substr($h, 13));
			}
		}
		return ['ok' => true, 'bytes' => $r['body'], 'type' => $type];
	}

	private static function sameSite(string $host, string $site): bool
	{
		$strip = static fn (string $h): string => preg_replace('/^www\./', '', strtolower($h)) ?? '';
		return $strip($host) === $strip($site);
	}

	/** An entry's answers in our terms (files: the upload links). */
	public static function map(array $entry): array
	{
		$v = static fn (string $k): string => trim((string) ($entry[self::FIELDS[$k]] ?? ''));
		$first = trim((string) ($entry[self::FIELDS['name'] . '.3'] ?? ''));
		$last = trim((string) ($entry[self::FIELDS['name'] . '.6'] ?? ''));
		$name = trim($first . ' ' . $last);
		if ($name === '') {
			$name = $v('name');
		}
		$upload = $v('upload');
		$files = [];
		if ($upload !== '') {
			$decoded = json_decode($upload, true);
			$files = is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [$upload];
		}
		$utility = $v('utility');
		if ($v('utility_other') !== '' && ($utility === '' || stripos($utility, 'other') !== false)) {
			$utility = $v('utility_other');
		}
		return [
			'name' => $name, 'first' => $first, 'last' => $last, 'phone' => $v('phone'), 'email' => $v('email'),
			'project_type' => $v('project_type'), 'share' => $v('share'), 'address' => $v('address'),
			'utility' => $utility, 'yearly_kwh' => self::number($v('yearly_kwh')), 'rate_plan' => $v('rate_plan'),
			'period_kwh' => self::number($v('period_kwh')), 'current_charges' => self::number($v('current_charges')),
			'customer_charge' => self::number($v('customer_charge')), 'notes' => $v('notes'), 'files' => $files,
		];
	}

	private static function number(string $s): string
	{
		$n = str_replace([',', '$', ' '], '', $s);
		return is_numeric($n) ? $n : $s;
	}

	/** Gravity Forms dates are UTC ("2026-11-12 16:03:11"). */
	private static function utc(string $s): ?string
	{
		$t = $s !== '' ? strtotime($s . ' UTC') : false;
		return $t ? gmdate('Y-m-d\TH:i:s\Z', $t) : null;
	}

	// ------------------------------------------------------------------ list, matching, prefill

	public static function pending(): array
	{
		return array_map([self::class, 'decode'], Db::all("SELECT * FROM web_requests WHERE status = 'pending' ORDER BY COALESCE(submitted_at, created_at) DESC"));
	}

	public static function find(int $id): ?array
	{
		$r = Db::one('SELECT * FROM web_requests WHERE id = ?', [$id]);
		return $r ? self::decode($r) : null;
	}

	private static function decode(array $r): array
	{
		$r['data'] = json_decode((string) $r['data_json'], true) ?: [];
		$r['files'] = json_decode((string) $r['files_json'], true) ?: [];
		$r['bill'] = json_decode((string) $r['bill_json'], true) ?: null;
		return $r;
	}

	/** Customer type and agricultural flag from the form's Project type answer. */
	public static function type(string $answer): array
	{
		$a = strtolower($answer);
		return match (true) {
			str_contains($a, 'farm') || str_contains($a, 'agri') => ['commercial', 1],
			str_contains($a, 'non-profit') && !str_contains($a, 'commercial') => ['nonprofit', 0],
			str_contains($a, 'commercial') => ['commercial', 0],
			default => ['residential', 0],
		};
	}

	/**
	 * Existing customers that may be this person: same email, same phone (last 10 digits) or the same
	 * street on an opportunity or project. Returns id, name and why.
	 */
	public static function matches(array $req): array
	{
		$d = $req['data'];
		$hits = [];
		$add = static function (array $rows, string $why) use (&$hits): void {
			foreach ($rows as $r) {
				$id = (int) $r['id'];
				$hits[$id] ??= ['id' => $id, 'name' => (string) $r['name'], 'why' => []];
				if (!in_array($why, $hits[$id]['why'], true)) {
					$hits[$id]['why'][] = $why;
				}
			}
		};
		$email = strtolower(trim((string) ($d['email'] ?? '')));
		if ($email !== '') {
			$add(Db::all("SELECT id, name FROM organizations WHERE type = 'customer' AND lower(email) = ?", [$email]), 'same email');
			$add(Db::all("SELECT o.id, o.name FROM contacts c JOIN organizations o ON o.id = c.owner_id AND c.owner_type = 'organization' WHERE o.type = 'customer' AND lower(c.email) = ?", [$email]), 'same email');
		}
		$digits = substr(preg_replace('/\D/', '', (string) ($d['phone'] ?? '')) ?? '', -10);
		if (strlen($digits) === 10) {
			$phoneSql = "substr(replace(replace(replace(replace(replace(replace(%s, ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), '+', ''), -10)";
			$add(Db::all("SELECT id, name FROM organizations WHERE type = 'customer' AND " . sprintf($phoneSql, 'phone') . ' = ?', [$digits]), 'same phone');
			$add(Db::all("SELECT o.id, o.name FROM contacts c JOIN organizations o ON o.id = c.owner_id AND c.owner_type = 'organization' WHERE o.type = 'customer' AND " . sprintf($phoneSql, 'c.phone') . ' = ?', [$digits]), 'same phone');
		}
		$street = self::streetKey((string) ($d['address'] ?? ''));
		if ($street !== '') {
			foreach (Db::all("SELECT o.id, o.name, p.site_street FROM projects p JOIN organizations o ON o.id = p.customer_id WHERE p.site_street IS NOT NULL
				UNION SELECT o.id, o.name, q.site_street FROM presales_opportunities q JOIN organizations o ON o.id = q.customer_id WHERE q.site_street IS NOT NULL") as $r) {
				if (self::streetKey((string) $r['site_street']) === $street) {
					$add([$r], 'same street address');
				}
			}
		}
		$name = strtolower(trim((string) ($d['name'] ?? '')));
		if ($name !== '') {
			$add(Db::all("SELECT id, name FROM organizations WHERE type = 'customer' AND lower(name) = ?", [$name]), 'same name');
		}
		return array_values($hits);
	}

	/** House number plus the first word of the street, lowercased ("123 main"), or ''. */
	private static function streetKey(string $address): string
	{
		$line = strtolower(trim(explode(',', $address)[0]));
		return preg_match('/^(\d+[a-z]?)\s+([a-z0-9]+)/', $line, $m) ? $m[1] . ' ' . $m[2] : '';
	}

	/**
	 * The New opportunity form, filled from a request: the form's answers, then what the bill reader
	 * found where the form left a field blank. $customerId picks an existing customer; 0 makes a new one.
	 * Returns the form values and the names of fields the bill filled (highlighted on the form).
	 */
	public static function prefill(array $req, int $customerId): array
	{
		$d = $req['data'];
		[$type, $ag] = self::type((string) ($d['project_type'] ?? ''));
		$bill = $req['bill']['fields'] ?? [];
		$o = [
			'salesperson_id' => Auth::id(), 'site_state' => 'PA', 'customer_type' => $type, 'is_agricultural' => $ag,
			'lead_source' => 'trifecta', 'lead_detail' => 'website', 'web_request_id' => (int) $req['id'],
			'notes' => (string) ($d['notes'] ?? ''),
			'full_address' => (string) ($d['address'] ?? ''),
		];
		if ($customerId) {
			$o['customer_id'] = $customerId;
		} else {
			$o += ['customer_id' => 'new', 'new_customer_name' => $d['name'] ?? '', 'new_customer_phone' => $d['phone'] ?? '', 'new_customer_email' => $d['email'] ?? ''];
		}
		$fromForm = [
			'utility_id' => BillReader::matchUtility((string) ($d['utility'] ?? '')),
			'annual_usage_kwh' => $d['yearly_kwh'] ?? '',
			'utility_tariff' => $d['rate_plan'] ?? '',
			'bill_kwh' => $d['period_kwh'] ?? '',
			'bill_total' => $d['current_charges'] ?? '',
			'bill_charge' => $d['customer_charge'] ?? '',
		];
		foreach ($fromForm as $k => $val) {
			if ($val !== '' && $val !== null) {
				$o[$k] = $val;
			}
		}
		if (($o['bill_kwh'] ?? '') !== '') {
			$o['bill_months'] = 1;
		}
		$auto = [];
		foreach (['bill_name', 'utility_id', 'utility_account', 'utility_meter', 'utility_tariff', 'annual_usage_kwh', 'bill_kwh', 'bill_total', 'bill_charge', 'bill_months', 'ptc_cents'] as $k) {
			$val = $bill[$k] ?? '';
			if ($val !== '' && $val !== null && (($o[$k] ?? '') === '' || ($o[$k] ?? null) === null)) {
				$o[$k] = $val;
				$auto[] = $k;
			}
		}
		if (($bill['monthly_usage'] ?? []) !== []) {
			$o['monthly_usage'] = $bill['monthly_usage'];
			$auto[] = 'monthly_usage';
			$o['annual_usage_kwh'] = $bill['annual_usage_kwh'] ?? ($o['annual_usage_kwh'] ?? '');
		}
		if ($o['full_address'] === '' && ($bill['service_address'] ?? '') !== '') {
			$o['full_address'] = $bill['service_address'];
		}
		return [$o, $auto];
	}
}
