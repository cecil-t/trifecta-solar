<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Address;
use App\Auth;
use App\Db;
use App\Designs;
use App\Geo;
use App\Municipalities;
use App\Presales;
use App\Projects;
use App\SolarResource;
use App\UtilityRates;
use App\View;

/**
 * Pre-Sales: opportunities (a customer and a site being quoted) and their build sheets.
 * An opportunity is Open, Sold (tied to a project) or Lost (with an optional reason).
 */
final class PresalesController
{
	private const TRACKED = [
		'name' => 'Name', 'customer_id' => 'Customer', 'salesperson_id' => 'Salesperson', 'customer_type' => 'Customer type',
		'is_agricultural' => 'Agricultural', 'site_street' => 'Street', 'site_city' => 'City', 'site_state' => 'State',
		'site_zip' => 'ZIP', 'drive_url' => 'Drive folder', 'notes' => 'Notes',
		'utility_id' => 'Utility', 'bill_name' => 'Name on utility bill', 'utility_account' => 'Utility account #',
		'utility_meter' => 'Meter #', 'utility_tariff' => 'Utility rate', 'service_address' => 'Service address',
		'annual_usage_kwh' => 'Last 12 months usage (kWh)', 'new_service' => 'New electric service',
		'usage_profile' => 'Usage profile', 'monthly_usage' => 'Monthly use from the bill',
		'lead_source' => 'Lead source', 'lead_detail' => 'Lead came in by', 'referrer_org_id' => 'Referred by',
		'referrer_name' => 'Referred by (name)', 'handoff_user_id' => 'Handed off by',
		'municipality_id' => 'Municipality', 'site_lat' => 'Map point latitude', 'site_lon' => 'Map point longitude',
		'rate_name' => 'Utility rate (OpenEI)',
		'bill_charge' => 'Customer charge ($ a month)', 'bill_total' => 'Bill total ($)', 'bill_kwh' => 'kWh on the bill',
		'bill_months' => 'Months on the bill', 'ptc_cents' => 'Price to Compare (cents per kWh)',
	];

	public function index(): void
	{
		Presales::requireUse();
		$status = (string) ($_GET['status'] ?? 'open');
		if (!in_array($status, ['open', 'sold', 'lost', 'all'], true)) {
			$status = 'open';
		}
		$q = trim((string) ($_GET['q'] ?? ''));
		if ($q !== '') {
			$status = 'all';
		}
		[$vis, $params] = Presales::visibleWhere();
		$where = [$vis];
		if ($status !== 'all') {
			$where[] = 'o.status = ?';
			$params[] = $status;
		}
		if ($q !== '') {
			$where[] = '(o.name LIKE ? OR c.name LIKE ? OR o.site_city LIKE ? OR o.site_street LIKE ?)';
			array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
		}
		$rows = Db::all(
			'SELECT o.*, c.name AS customer_name, u.name AS salesperson_name, u.initials AS salesperson_initials,
				(SELECT COUNT(*) FROM presales_designs d WHERE d.opportunity_id = o.id) AS design_count,
				(SELECT MIN(price_total) FROM presales_designs d WHERE d.opportunity_id = o.id AND price_total > 0) AS price_min,
				(SELECT MAX(price_total) FROM presales_designs d WHERE d.opportunity_id = o.id AND price_total > 0) AS price_max,
				(SELECT MAX(dc_watts) FROM presales_designs d WHERE d.opportunity_id = o.id) AS dc_max,
				p.project_number
			 FROM presales_opportunities o
			 LEFT JOIN organizations c ON c.id = o.customer_id
			 LEFT JOIN users u ON u.id = o.salesperson_id
			 LEFT JOIN projects p ON p.id = o.project_id'
			. ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.updated_at DESC',
			$params
		);
		$counts = ['all' => 0];
		foreach (Db::all('SELECT status, COUNT(*) AS n FROM presales_opportunities o WHERE ' . $vis . ' GROUP BY status', Presales::visibleWhere()[1]) as $r) {
			$counts[$r['status']] = (int) $r['n'];
			$counts['all'] += (int) $r['n'];
		}
		View::render('presales/index', ['title' => 'Sales', 'rows' => $rows, 'status' => $status, 'q' => $q, 'counts' => $counts]);
	}

	public function create(): void
	{
		Presales::requireUse();
		$o = $_SESSION['old_presales'] ?? ['salesperson_id' => Auth::id(), 'site_state' => 'PA', 'customer_type' => 'residential'];
		unset($_SESSION['old_presales']);
		$this->form($o, null);
	}

	public function edit(int $id): void
	{
		$o = $_SESSION['old_presales'] ?? $this->find($id);
		unset($_SESSION['old_presales']);
		$this->form($o, $id);
	}

	private function form(array $o, ?int $id): void
	{
		if (!empty($o['referrer_org_id']) && !isset($o['referrer_label'])) {
			$o['referrer_label'] = (string) Db::value('SELECT name FROM organizations WHERE id = ?', [(int) $o['referrer_org_id']]);
		}
		View::render('presales/opportunity_form', [
			'title' => $id ? 'Edit ' . $o['name'] : 'New opportunity', 'o' => $o, 'id' => $id,
			'customers' => Projects::orgs('customer'), 'utilities' => Projects::utilities(),
			'municipalities' => Projects::municipalities(), 'counties' => Projects::counties(),
			'users' => Db::all('SELECT id, name, initials FROM users WHERE is_active = 1 AND (is_sales = 1 OR is_admin = 1) ORDER BY name'),
			'canAssign' => Auth::isAdmin(),
		]);
	}

	public function store(): void
	{
		Presales::requireUse();
		try {
			$data = $this->input(null);
			$data['created_by'] = Auth::id();
			$id = Db::insert('presales_opportunities', $data);
			Activity::event('presales', $id, 'Opportunity created');
			$designId = Designs::create($id, Designs::defaultName($id));
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_presales'] = $_POST;
			flash('error', $e->getMessage());
			redirect('/presales/new');
		}
		redirect('/presales/' . $id . '/designs/' . $designId);
	}

	public function update(int $id): void
	{
		$before = $this->find($id);
		try {
			$data = $this->input($id);
			$data['updated_at'] = now_utc();
			Db::update('presales_opportunities', $id, $data);
			Activity::changes('presales', $id, $before, $data, self::TRACKED, [
				'customer_id' => static fn ($v) => Db::value('SELECT name FROM organizations WHERE id = ?', [$v]) ?? $v,
				'salesperson_id' => static fn ($v) => Db::value('SELECT name FROM users WHERE id = ?', [$v]) ?? $v,
				'utility_id' => static fn ($v) => Db::value('SELECT name FROM utilities WHERE id = ?', [$v]) ?? $v,
				'is_agricultural' => static fn ($v) => $v ? 'Yes' : 'No',
				'new_service' => static fn ($v) => $v ? 'Yes' : 'No',
				'usage_profile' => static fn ($v) => \App\Savings::PROFILES[$v][0] ?? $v,
				'monthly_usage' => static fn ($v) => is_array($m = json_decode((string) $v, true)) ? implode(', ', array_map(static fn ($x) => number_format((float) $x), $m)) . ' kWh' : $v,
				'lead_source' => static fn ($v) => Presales::LEAD_SOURCES[$v] ?? $v,
				'lead_detail' => static fn ($v) => Presales::LEAD_DETAILS[$v] ?? $v,
				'referrer_org_id' => static fn ($v) => Db::value('SELECT name FROM organizations WHERE id = ?', [$v]) ?? $v,
				'handoff_user_id' => static fn ($v) => Db::value('SELECT name FROM users WHERE id = ?', [$v]) ?? $v,
				'municipality_id' => static fn ($v) => Db::value("SELECT m.name || ' (' || c.name || ' Co.)' FROM municipalities m JOIN counties c ON c.id = m.county_id WHERE m.id = ?", [$v]) ?? $v,
			]);
		} catch (\InvalidArgumentException $e) {
			$_SESSION['old_presales'] = $_POST + ['id' => $id];
			flash('error', $e->getMessage());
			redirect('/presales/' . $id . '/edit');
		}
		flash('success', 'Saved.');
		redirect('/presales/' . $id);
	}

	public function show(int $id): void
	{
		$o = Db::one(
			'SELECT o.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
				u.name AS salesperson_name, p.project_number, p.name AS project_name, ut.name AS utility_name,
				m.name AS municipality_name, co.name AS county_name
			 FROM presales_opportunities o
			 LEFT JOIN municipalities m ON m.id = o.municipality_id
			 LEFT JOIN counties co ON co.id = m.county_id
			 LEFT JOIN organizations c ON c.id = o.customer_id
			 LEFT JOIN users u ON u.id = o.salesperson_id
			 LEFT JOIN projects p ON p.id = o.project_id
			 LEFT JOIN utilities ut ON ut.id = o.utility_id
			 WHERE o.id = ?', [$id]
		) ?? $this->notFound();
		if (!Presales::canSee($o)) {
			$this->notFound();
		}
		$designs = Db::all('SELECT d.*, u.name AS updated_by_name FROM presales_designs d LEFT JOIN users u ON u.id = d.updated_by
			WHERE d.opportunity_id = ? ORDER BY d.sort_order, d.id', [$id]);
		$cards = [];
		foreach ($designs as $d) {
			$lines = Designs::lines((int) $d['id']);
			$calc = Designs::calc($d, $lines, Designs::arrays((int) $d['id']));
			$cards[] = ['d' => $d, 'calc' => $calc, 'flags' => Designs::priceFlags($lines, $d)];
		}
		$contacts = $o['customer_id'] ? Db::all("SELECT * FROM contacts WHERE owner_type = 'organization' AND owner_id = ? AND is_active = 1 ORDER BY is_primary DESC, name", [$o['customer_id']]) : [];
		[$vis, $vp] = Presales::visibleWhere();
		$otherOpps = $o['customer_id'] ? Db::all('SELECT o.id, o.name, o.status FROM presales_opportunities o WHERE o.customer_id = ? AND o.id <> ? AND ' . $vis . ' ORDER BY o.updated_at DESC', [$o['customer_id'], $id, ...$vp]) : [];
		$customerProjects = $o['customer_id'] ? Db::all('SELECT id, project_number, name FROM projects WHERE customer_id = ? ORDER BY project_number DESC', [$o['customer_id']]) : [];
		View::render('presales/show', [
			'title' => $o['name'], 'o' => $o, 'cards' => $cards, 'contacts' => $contacts, 'otherOpps' => $otherOpps,
			'customerProjects' => $customerProjects,
			'activity' => Activity::feed('presales', $id),
		]);
	}

	/** Open / Sold / Lost. Sold names the build sheet sold; Lost takes an optional reason. */
	public function status(int $id): void
	{
		$o = $this->find($id);
		$status = (string) ($_POST['status'] ?? '');
		if (!isset(Presales::STATUSES[$status])) {
			redirect('/presales/' . $id);
		}
		$data = ['status' => $status, 'status_at' => now_utc(), 'updated_at' => now_utc()];
		$msg = 'Marked ' . Presales::STATUSES[$status];
		if ($status === 'lost') {
			$data['lost_reason'] = trim((string) ($_POST['lost_reason'] ?? '')) ?: null;
			if ($data['lost_reason']) {
				$msg .= ': ' . $data['lost_reason'];
			}
		} elseif ($status === 'sold') {
			$did = (int) ($_POST['sold_design_id'] ?? 0);
			$design = Db::one('SELECT * FROM presales_designs WHERE id = ? AND opportunity_id = ?', [$did, $id]);
			if (!$design) {
				flash('error', 'Pick the build sheet that sold.');
				redirect('/presales/' . $id);
			}
			$data['sold_design_id'] = $did;
			$data['lost_reason'] = null;
			$msg .= ' with "' . $design['name'] . '"';
		} else {
			$data['lost_reason'] = null;
			$data['sold_design_id'] = null;
		}
		Db::update('presales_opportunities', $id, $data);
		Activity::event('presales', $id, $msg);
		flash('success', $msg . '.');
		redirect('/presales/' . $id);
	}

	public function comment(int $id): void
	{
		$this->find($id);
		$body = trim((string) ($_POST['body'] ?? ''));
		if ($body !== '') {
			Activity::comment('presales', $id, $body);
			Db::update('presales_opportunities', $id, ['updated_at' => now_utc()]);
		}
		redirect('/presales/' . $id . '#log');
	}

	private function input(?int $id): array
	{
		$name = trim((string) ($_POST['name'] ?? ''));
		$customerId = null;
		if (($_POST['customer_id'] ?? '') === 'new') {
			$cname = trim((string) ($_POST['new_customer_name'] ?? ''));
			if ($cname === '') {
				throw new \InvalidArgumentException('Enter the new customer\'s name.');
			}
			$dupe = Db::value("SELECT id FROM organizations WHERE type = 'customer' AND name = ? COLLATE NOCASE", [$cname]);
			$customerId = $dupe ?: Db::insert('organizations', [
				'type' => 'customer', 'name' => $cname,
				'phone' => trim((string) ($_POST['new_customer_phone'] ?? '')) ?: null,
				'email' => trim((string) ($_POST['new_customer_email'] ?? '')) ?: null,
				'created_by' => Auth::id(),
			]);
			if (!$dupe) {
				Activity::event('organization', (int) $customerId, 'Customer added from Sales');
			}
		} elseif ((int) ($_POST['customer_id'] ?? 0)) {
			$customerId = (int) Db::value("SELECT id FROM organizations WHERE id = ? AND type = 'customer'", [(int) $_POST['customer_id']]) ?: null;
		}
		// The name defaults to the customer's name
		if ($name === '' && $customerId) {
			$name = (string) Db::value('SELECT name FROM organizations WHERE id = ?', [(int) $customerId]);
		}
		if ($name === '') {
			throw new \InvalidArgumentException('Pick the customer or type a name for the opportunity.');
		}
		$drive = trim((string) ($_POST['drive_url'] ?? ''));
		if ($err = link_error('The Drive folder link', $drive)) {
			throw new \InvalidArgumentException($err);
		}
		$usage = Projects::parseNumber($_POST['annual_usage_kwh'] ?? '');
		if ($usage === null && trim((string) ($_POST['annual_usage_kwh'] ?? '')) !== '') {
			throw new \InvalidArgumentException('Last 12 months usage must be a number of kWh.');
		}
		$usage = $usage === null ? null : (int) round($usage);
		// The 12 months from the bill (all or none); when given, the yearly use is their total
		$months = [];
		foreach (range(0, 11) as $i) {
			$raw = trim((string) ($_POST['monthly_usage'][$i] ?? ''));
			$v = Projects::parseNumber($raw);
			if ($raw !== '' && ($v === null || $v < 0)) {
				throw new \InvalidArgumentException('Monthly use for ' . \App\Savings::MONTHS[$i] . ' must be a number of kWh.');
			}
			$months[] = $raw === '' ? null : (float) $v;
		}
		$filled = count(array_filter($months, static fn ($v) => $v !== null));
		if ($filled > 0 && $filled < 12) {
			throw new \InvalidArgumentException('Enter all 12 months of use from the bill, or none (the yearly use is then spread by the usage profile).');
		}
		$monthly = $filled === 12 ? json_encode(array_map(static fn ($v) => round($v), $months)) : null;
		if ($monthly !== null) {
			$usage = (int) round(array_sum($months));
		}
		$profile = (string) ($_POST['usage_profile'] ?? '');
		$profile = isset(\App\Savings::PROFILES[$profile]) ? $profile : null;
		$sales = (int) ($_POST['salesperson_id'] ?? 0);
		// Only admins assign or reassign the salesperson; a salesperson's own opportunities stay theirs
		if (!Auth::isAdmin()) {
			$sales = $id ? (int) Db::value('SELECT salesperson_id FROM presales_opportunities WHERE id = ?', [$id]) : (int) Auth::id();
		}
		$type = (string) ($_POST['customer_type'] ?? '');
		$lead = (string) ($_POST['lead_source'] ?? '');
		$lead = array_key_exists($lead, Presales::LEAD_SOURCES) ? $lead : null;
		$detail = (string) ($_POST['lead_detail'] ?? '');
		$refOrg = $lead === 'referral' ? (int) Db::value('SELECT id FROM organizations WHERE id = ?', [(int) ($_POST['referrer_org_id'] ?? 0)]) : 0;
		$refName = $lead === 'referral' && !$refOrg ? (trim((string) ($_POST['referrer_name'] ?? '')) ?: null) : null;
		$handoff = (int) ($_POST['handoff_user_id'] ?? 0);
		$handoff = $handoff && $handoff !== $sales && Db::value('SELECT 1 FROM users WHERE id = ?', [$handoff]) ? $handoff : null;
		// Site: municipality, the map point (from the address lookup, typed in, or found now) and the rate
		$street = trim((string) ($_POST['site_street'] ?? ''));
		$city = trim((string) ($_POST['site_city'] ?? ''));
		$state = in_array($_POST['site_state'] ?? '', ['PA', 'MD', 'DE'], true) ? (string) $_POST['site_state'] : 'PA';
		$zip = trim((string) ($_POST['site_zip'] ?? ''));
		$before = $id ? Db::one('SELECT * FROM presales_opportunities WHERE id = ?', [$id]) : null;
		$muni = $_POST['municipality_id'] ?? '';
		if ($muni === 'new') {
			$m = Municipalities::findOrCreate((string) ($_POST['new_muni_name'] ?? ''), (int) ($_POST['new_muni_county_id'] ?? 0));
			$muni = $m['id'];
		}
		$muni = (int) $muni && Db::value('SELECT 1 FROM municipalities WHERE id = ?', [(int) $muni]) ? (int) $muni : null;
		$site = $this->sitePoint($street, $city, $state, $zip, $before);
		$rate = $this->rateInput($before, $site, (string) ($_POST['customer_type'] ?? ''));

		return $site + $rate + $this->billInput() + [
			'municipality_id' => $muni,
			'lead_source' => $lead,
			'lead_detail' => $lead === 'trifecta' && array_key_exists($detail, Presales::LEAD_DETAILS) ? $detail : null,
			'referrer_org_id' => $refOrg ?: null,
			'referrer_name' => $refName,
			'handoff_user_id' => $handoff,
			'name' => $name,
			'customer_id' => $customerId ? (int) $customerId : null,
			'salesperson_id' => $sales && Db::value('SELECT 1 FROM users WHERE id = ?', [$sales]) ? $sales : null,
			'customer_type' => array_key_exists($type, Projects::CUSTOMER_TYPES) ? $type : null,
			'is_agricultural' => empty($_POST['is_agricultural']) ? 0 : 1,
			'site_street' => trim((string) ($_POST['site_street'] ?? '')) ?: null,
			'site_city' => trim((string) ($_POST['site_city'] ?? '')) ?: null,
			'site_state' => in_array($_POST['site_state'] ?? '', ['PA', 'MD', 'DE'], true) ? $_POST['site_state'] : 'PA',
			'site_zip' => trim((string) ($_POST['site_zip'] ?? '')) ?: null,
			'drive_url' => $drive ?: null,
			'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
			'utility_id' => ($u = (int) ($_POST['utility_id'] ?? 0)) && Db::value('SELECT 1 FROM utilities WHERE id = ?', [$u]) ? $u : null,
			'bill_name' => trim((string) ($_POST['bill_name'] ?? '')) ?: null,
			'utility_account' => trim((string) ($_POST['utility_account'] ?? '')) ?: null,
			'utility_meter' => trim((string) ($_POST['utility_meter'] ?? '')) ?: null,
			'utility_tariff' => trim((string) ($_POST['utility_tariff'] ?? '')) ?: null,
			'service_address' => trim((string) ($_POST['service_address'] ?? '')) ?: null,
			'annual_usage_kwh' => $usage,
			'monthly_usage' => $monthly,
			'usage_profile' => $profile,
			'new_service' => empty($_POST['new_service']) ? 0 : 1,
		];
	}

	/**
	 * The rate from the customer's bill and a typed Price to Compare. The customer charge saves on its
	 * own; the bill total and kWh go together, and the rate they give must be believable.
	 */
	private function billInput(): array
	{
		$num = static function (string $k, string $label, float $min, float $max): ?float {
			$raw = trim((string) ($_POST[$k] ?? ''));
			if ($raw === '') {
				return null;
			}
			$v = Projects::parseNumber($raw);
			if ($v === null || !preg_match('/\d/', $raw) || $v < $min || $v > $max) {
				throw new \InvalidArgumentException($label . ' must be a number from ' . $min . ' to ' . number_format($max) . '.');
			}
			return $v;
		};
		$charge = $num('bill_charge', 'Customer charge', 0, 10000);
		$total = $num('bill_total', 'Bill total', 0.01, 10000000);
		$kwh = $num('bill_kwh', 'kWh on the bill', 1, 100000000);
		$months = (int) ($_POST['bill_months'] ?? 1);
		$months = $months >= 1 && $months <= 12 ? $months : 1;
		$ptc = $num('ptc_cents', 'Price to Compare', 0.01, 100);
		if (($total === null) !== ($kwh === null)) {
			throw new \InvalidArgumentException('Rate from the bill: enter both the bill total and the kWh on the bill, or neither.');
		}
		$out = ['bill_charge' => $charge, 'bill_total' => $total, 'bill_kwh' => $kwh, 'bill_months' => $total === null ? null : $months,
			'ptc_cents' => $ptc === null ? null : round($ptc, 4)];
		if ($total !== null) {
			$rate = ($total - ($charge ?? 0) * $months) / $kwh;
			if ($rate < 0.01 || $rate > 2) {
				throw new \InvalidArgumentException('Rate from the bill works out to ' . number_format($rate * 100, 2) . ' cents per kWh, which can\'t be right. Check the customer charge, the bill total, the kWh and how many months they cover.');
			}
		}
		return $out;
	}

	/** The site's map point: as posted, else the saved one while the address is unchanged, else looked up now. */
	private function sitePoint(string $street, string $city, string $state, string $zip, ?array $before): array
	{
		$address = Geo::address($street, $city, $state, $zip);
		$lat = trim((string) ($_POST['site_lat'] ?? ''));
		$lon = trim((string) ($_POST['site_lon'] ?? ''));
		if ($lat !== '' || $lon !== '') {
			if (!is_numeric($lat) || !is_numeric($lon) || ($err = SolarResource::pointError((float) $lat, (float) $lon)) !== null) {
				throw new \InvalidArgumentException('Map point: ' . ($err ?? 'type a latitude and longitude, e.g. 40.1600 and -76.3900.'));
			}
			$src = (string) ($_POST['geo_source'] ?? '');
			$same = $before && $before['site_lat'] !== null && abs((float) $before['site_lat'] - (float) $lat) < 0.000001 && abs((float) $before['site_lon'] - (float) $lon) < 0.000001;
			return ['site_lat' => round((float) $lat, 6), 'site_lon' => round((float) $lon, 6),
				'geo_source' => $same ? $before['geo_source'] : (in_array($src, ['google', 'google_approx', 'census', 'osm', 'osm_street'], true) ? $src : 'manual'),
				'geo_address' => $address ?: null];
		}
		if ($address === '') {
			return ['site_lat' => null, 'site_lon' => null, 'geo_source' => null, 'geo_address' => null];
		}
		$r = Address::lookup($address);
		if ($r['lat'] === null) {
			flash('info', 'The map point for this address could not be found. Type it in on the opportunity\'s Edit page (Site, Map point) so production can run.');
			return ['site_lat' => null, 'site_lon' => null, 'geo_source' => null, 'geo_address' => null];
		}
		return ['site_lat' => $r['lat'], 'site_lon' => $r['lon'], 'geo_source' => $r['source'], 'geo_address' => $address];
	}

	/**
	 * The picked OpenEI rate: fetched in full when it changes, so bill math uses the rate as picked,
	 * with its history (UtilityRates::rateTrend). A history that can't be fetched now is left blank.
	 */
	private function rateInput(?array $before, array $site, string $customerType): array
	{
		$label = trim((string) ($_POST['rate_label'] ?? ''));
		if ($label === '') {
			return ['rate_label' => null, 'rate_name' => null, 'rate_utility' => null, 'rate_json' => null, 'rate_fetched_at' => null, 'rate_trend' => null];
		}
		if ($before && $before['rate_label'] === $label && $before['rate_json']) {
			return $before['rate_trend'] || $site['site_lat'] === null ? [] : $this->rateTrend($site, $customerType, (string) $before['rate_utility'], (string) $before['rate_name']);
		}
		$r = UtilityRates::rate($label);
		if (!$r['ok']) {
			throw new \InvalidArgumentException('Utility rate: ' . $r['error'] . ' Pick it again, or clear it.');
		}
		$out = ['rate_label' => $label, 'rate_name' => (string) ($r['rate']['name'] ?? ''), 'rate_utility' => (string) ($r['rate']['utility'] ?? ''),
			'rate_json' => json_encode($r['rate']), 'rate_fetched_at' => now_utc(), 'rate_trend' => null];
		return $site['site_lat'] === null ? $out : array_merge($out, $this->rateTrend($site, $customerType, $out['rate_utility'], $out['rate_name']));
	}

	private function rateTrend(array $site, string $customerType, string $utility, string $name): array
	{
		set_time_limit(120);
		$t = UtilityRates::rateTrend((float) $site['site_lat'], (float) $site['site_lon'], $customerType !== '' && $customerType !== 'residential' ? 'Commercial' : 'Residential', $utility, $name);
		return $t['ok'] ? ['rate_trend' => json_encode($t)] : [];
	}

	/** JSON for the rate picker: OpenEI's rates at a point for a utility and sector, one per rate name, newest version. */
	public function utilityRates(): void
	{
		Presales::requireUse();
		header('Content-Type: application/json');
		$lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
		$lon = filter_var($_GET['lon'] ?? null, FILTER_VALIDATE_FLOAT);
		if ($lat === false || $lon === false || SolarResource::pointError($lat, $lon) !== null) {
			echo json_encode(['ok' => false, 'error' => 'Find the site\'s map point first (Site, Look up).']);
			return;
		}
		$sector = in_array($_GET['sector'] ?? '', UtilityRates::SECTORS, true) ? (string) $_GET['sector'] : 'Residential';
		$ours = (string) (Db::value('SELECT name FROM utilities WHERE id = ?', [(int) ($_GET['utility_id'] ?? 0)]) ?? '');
		$found = UtilityRates::pickerList($lat, $lon, $sector, $ours);
		if (!$found['ok']) {
			echo json_encode(['ok' => false, 'error' => $found['error']]);
			return;
		}
		$list = $found['rates'];
		$others = $found['other_utilities'];
		echo json_encode(['ok' => true, 'rates' => $list, 'sector' => $sector, 'utility' => $ours, 'other_utilities' => $others]);
	}

	private function find(int $id): array
	{
		$o = Db::one('SELECT * FROM presales_opportunities WHERE id = ?', [$id]) ?? $this->notFound();
		return Presales::canSee($o) ? $o : $this->notFound();
	}

	private function notFound(): never
	{
		View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
		exit;
	}
}
