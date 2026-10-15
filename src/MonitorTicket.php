<?php
declare(strict_types=1);

namespace App;

use App\Controllers\MonitorPageController;

/**
 * Service tickets opened from a monitored system: what the new ticket form is filled with, the open
 * ticket already linked to a system, and the vendor portal link for a system.
 *
 * Matching a system to a project or customer is a best guess for the form, never saved on its own:
 * the system's linked project when it has one, else a project at the same street address, else a
 * project or customer whose name matches the system name. Only a single match is used.
 */
final class MonitorTicket
{
	/** Words left out when comparing a system name with project and customer names. */
	private const NAME_NOISE = ['residence', 'res', 'home', 'house', 'solar', 'pv', 'system', 'systems', 'array', 'project', 'the', 'and', 'llc', 'inc', 'kw', 'kwdc'];

	/** Street words shortened the same way on both sides of an address comparison. */
	private const STREET_ABBR = [
		'road' => 'rd', 'street' => 'st', 'avenue' => 'ave', 'lane' => 'ln', 'drive' => 'dr', 'court' => 'ct',
		'boulevard' => 'blvd', 'place' => 'pl', 'circle' => 'cir', 'terrace' => 'ter', 'highway' => 'hwy',
		'north' => 'n', 'south' => 's', 'east' => 'e', 'west' => 'w', 'pike' => 'pk', 'turnpike' => 'tpke',
	];

	/**
	 * A link straight to the system in the vendor's portal, where the vendor has a stable one.
	 * APsystems EMA and Growatt Shiner open on the fleet view, so they get none.
	 */
	public static function portalUrl(string $vendor, string $vendorSiteId): ?string
	{
		if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $vendorSiteId)) {
			return null;
		}
		return match ($vendor) {
			'solaredge' => 'https://monitoring.solaredge.com/solaredge-web/p/site/' . $vendorSiteId . '/',
			'enphase' => 'https://enlighten.enphaseenergy.com/systems/' . $vendorSiteId,
			'sma' => 'https://ennexos.sunnyportal.com/' . $vendorSiteId . '/dashboard',
			default => null,
		};
	}

	/**
	 * The newest open ticket (not completed) for each of these monitored systems.
	 *
	 * @param int[] $siteIds
	 * @return array<int,array{id:int,ticket_number:string}> by monitor site id
	 */
	public static function openTickets(array $siteIds): array
	{
		$siteIds = array_values(array_unique(array_filter(array_map('intval', $siteIds))));
		if (!$siteIds) {
			return [];
		}
		$rows = Db::all(
			'SELECT id, ticket_number, monitor_site_id FROM service_tickets
			 WHERE completed_on IS NULL AND monitor_site_id IN (' . implode(',', array_fill(0, count($siteIds), '?')) . ')
			 ORDER BY id',
			$siteIds
		);
		$out = [];
		foreach ($rows as $r) {
			$out[(int) $r['monitor_site_id']] = ['id' => (int) $r['id'], 'ticket_number' => (string) $r['ticket_number']];
		}
		return $out;
	}

	/**
	 * Fields for a new ticket about this monitored system, plus notes for the person filling the form.
	 *
	 * @return array{ticket:array<string,mixed>,notes:string[],site:array<string,mixed>}|null
	 */
	public static function prefill(int $siteId): ?array
	{
		$s = Db::one('SELECT * FROM monitor_sites WHERE id = ?', [$siteId]);
		if (!$s) {
			return null;
		}
		$vendor = MonitorCollector::VENDORS[$s['vendor']] ?? $s['vendor'];
		$name = $s['name'] !== '' ? (string) $s['name'] : (string) $s['vendor_site_id'];
		$t = ['monitor_site_id' => (int) $s['id'], 'source' => 'monitoring'];
		$notes = [];

		// Why it needs attention, the same tests as the Monitor page's Issues list.
		$last = Db::value('SELECT MAX(date) FROM monitor_daily WHERE site_id = ? AND wh > 0', [$siteId]);
		$why = [];
		if (in_array($s['state'], ['alert', 'offline'], true) && trim((string) $s['status_text']) !== '') {
			$why[] = trim((string) $s['status_text']);
		}
		$recent = date('Y-m-d', strtotime('-' . MonitorPageController::NO_PRODUCTION_DAYS . ' days'));
		if ($s['state'] !== 'pending' && (!$last || $last < $recent) && substr((string) $s['first_seen_at'], 0, 10) <= $recent) {
			$why[] = $last ? 'No production since ' . date('m/d/Y', strtotime((string) $last)) : 'No production on record';
		}
		$size = (float) $s['capacity_kw'] > 0 ? number_format((float) $s['capacity_kw'], 2) . ' kW DC'
			: ((float) ($s['capacity_ac_kw'] ?? 0) > 0 ? number_format((float) $s['capacity_ac_kw'], 2) . ' kW AC' : '');
		$lines = [
			$vendor . ' monitoring: ' . ($why ? implode('; ', $why) : 'opened from the Monitor page') . '.',
			'System: ' . $name . ', ' . $vendor . ' ID ' . $s['vendor_site_id'] . ($size !== '' ? ', ' . $size : '') . '.',
		];
		if ($last && !in_array('No production since ' . date('m/d/Y', strtotime((string) $last)), $why, true)) {
			$lines[] = 'Last production on record ' . date('m/d/Y', strtotime((string) $last)) . '.';
		}
		$t['description'] = implode("\n", $lines);
		if ($url = self::portalUrl((string) $s['vendor'], (string) $s['vendor_site_id'])) {
			$t['monitoring_url'] = $url;
		}

		// Customer and project: a guess for the person to confirm.
		[$project, $how] = self::matchProject($s, $name);
		if ($project) {
			$t += [
				'project_id' => (int) $project['id'], 'customer_id' => $project['customer_id'], 'trifecta_install' => 1,
				'site_street' => $project['site_street'], 'site_city' => $project['site_city'],
				'site_state' => $project['site_state'] ?: 'PA', 'site_zip' => $project['site_zip'],
			];
			$notes[] = 'Project ' . $project['project_number'] . ' ' . $project['name'] . ' ' . $how . '. Check it is the right one.';
		} else {
			$customer = self::matchCustomer($name);
			if ($customer) {
				$t['customer_id'] = (int) $customer['id'];
				$notes[] = 'Customer ' . $customer['name'] . ' matched by name. Check it is the right one, and pick the project if we installed it.';
			} else {
				$t['new_customer_name'] = $name;
				$notes[] = 'No customer or project matched "' . $name . '". Choose the customer, or pick + New customer (the name is filled in).';
			}
			foreach (['street', 'city', 'state', 'zip'] as $f) {
				if (trim((string) ($s['site_' . $f] ?? '')) !== '') {
					$t['site_' . $f] = $s['site_' . $f];
				}
			}
			if (!empty($s['site_street']) || !empty($s['site_city'])) {
				$notes[] = 'Site address from ' . $vendor . '.';
			}
		}
		return ['ticket' => $t, 'notes' => $notes, 'site' => $s + ['vendor_label' => $vendor, 'display_name' => $name]];
	}

	/**
	 * Saving a ticket that came from a monitored system with a project picked: link the system to that
	 * project when it has no project yet (the Monitor page then links the system name to the project).
	 */
	public static function linkProject(?int $siteId, ?int $projectId): void
	{
		if (!$siteId || !$projectId) {
			return;
		}
		$s = Db::one('SELECT vendor, vendor_site_id, name FROM monitor_sites WHERE id = ? AND project_id IS NULL', [$siteId]);
		if ($s) {
			Db::run('UPDATE monitor_sites SET project_id = ? WHERE id = ?', [$projectId, $siteId]);
			Activity::event('project', $projectId, 'Linked to ' . (MonitorCollector::VENDORS[$s['vendor']] ?? $s['vendor'])
				. ' monitoring system ' . ($s['name'] !== '' ? $s['name'] . ' (ID ' . $s['vendor_site_id'] . ')' : $s['vendor_site_id']) . ' from a service ticket');
		}
	}

	/**
	 * Every monitored system for the ticket form's picker, with the ones that match this ticket first:
	 * linked to the ticket's project, named in its monitoring portal link, or at its site street.
	 *
	 * @return array{suggested:array<int,array>,by_vendor:array<string,array<int,array>>}
	 */
	public static function choices(array $t): array
	{
		$sites = Db::all('SELECT id, vendor, vendor_site_id, name, project_id, site_street FROM monitor_sites ORDER BY name COLLATE NOCASE, vendor_site_id');
		$url = (string) ($t['monitoring_url'] ?? '');
		$street = self::streetKey((string) ($t['site_street'] ?? ''));
		$suggested = [];
		$byVendor = [];
		foreach ($sites as $s) {
			$s['label'] = ($s['name'] !== '' ? $s['name'] : 'ID ' . $s['vendor_site_id']) . ($s['name'] !== '' ? ' (ID ' . $s['vendor_site_id'] . ')' : '');
			$s['vendor_label'] = MonitorCollector::VENDORS[$s['vendor']] ?? $s['vendor'];
			$why = null;
			if (!empty($t['project_id']) && (int) $s['project_id'] === (int) $t['project_id']) {
				$why = 'linked to this project';
			} elseif ($url !== '' && preg_match('/(?<![0-9A-Za-z])' . preg_quote((string) $s['vendor_site_id'], '/') . '(?![0-9A-Za-z])/', $url)) {
				$why = 'ID in the portal link';
			} elseif ($street !== '' && self::streetKey((string) $s['site_street']) === $street) {
				$why = 'same site street';
			}
			if ($why !== null && (int) $s['id'] !== (int) ($t['monitor_site_id'] ?? 0)) {
				$suggested[] = $s + ['why' => $why];
			} else {
				$byVendor[$s['vendor_label']][] = $s;
			}
		}
		ksort($byVendor);
		return ['suggested' => $suggested, 'by_vendor' => $byVendor];
	}

	/** "SolarEdge Example Residence (ID 1234567)", for the ticket log. */
	public static function label(?int $siteId): string
	{
		$s = $siteId ? Db::one('SELECT vendor, vendor_site_id, name FROM monitor_sites WHERE id = ?', [$siteId]) : null;
		if (!$s) {
			return '';
		}
		return (MonitorCollector::VENDORS[$s['vendor']] ?? $s['vendor']) . ' ' . ($s['name'] !== '' ? $s['name'] . ' (ID ' . $s['vendor_site_id'] . ')' : 'ID ' . $s['vendor_site_id']);
	}

	/** @return array{0:?array,1:string} the project and how it was found */
	private static function matchProject(array $s, string $name): array
	{
		$cols = 'id, project_number, name, customer_id, site_street, site_city, site_state, site_zip';
		if ($s['project_id'] && ($p = Db::one("SELECT $cols FROM projects WHERE id = ?", [$s['project_id']]))) {
			return [$p, 'is linked to this system'];
		}
		$projects = Db::all("SELECT $cols FROM projects");

		$street = self::streetKey((string) ($s['site_street'] ?? ''));
		if ($street !== '') {
			$hits = array_values(array_filter($projects, static function (array $p) use ($s, $street): bool {
				if (self::streetKey((string) $p['site_street']) !== $street) {
					return false;
				}
				$zip = substr(preg_replace('/\D/', '', (string) $s['site_zip']), 0, 5);
				$city = strtolower(trim((string) $s['site_city']));
				return ($zip !== '' && $zip === substr(preg_replace('/\D/', '', (string) $p['site_zip']), 0, 5))
					|| ($city !== '' && $city === strtolower(trim((string) $p['site_city'])));
			}));
			if (count($hits) === 1) {
				return [$hits[0], 'matched by site address'];
			}
		}

		$key = self::nameKey($name);
		if ($key !== '') {
			$hits = array_values(array_filter($projects, static fn (array $p): bool => self::nameKey((string) $p['name']) === $key));
			if (count($hits) === 1) {
				return [$hits[0], 'matched by name'];
			}
		}
		return [null, ''];
	}

	private static function matchCustomer(string $name): ?array
	{
		$key = self::nameKey($name);
		if ($key === '') {
			return null;
		}
		$hits = array_values(array_filter(
			Db::all("SELECT id, name FROM organizations WHERE type = 'customer'"),
			static fn (array $c): bool => self::nameKey((string) $c['name']) === $key
		));
		return count($hits) === 1 ? $hits[0] : null;
	}

	/** "123 N. Main Street, Apt 2" and "123 North Main St" both become "123 n main st". */
	private static function streetKey(string $street): string
	{
		$street = strtolower(preg_replace('/[,#].*$/', '', $street));
		$words = preg_split('/[^a-z0-9]+/', $street, -1, PREG_SPLIT_NO_EMPTY);
		if (!$words || !ctype_digit($words[0][0])) {
			return ''; // no house number: too loose to match on
		}
		return implode(' ', array_map(static fn (string $w): string => self::STREET_ABBR[$w] ?? $w, $words));
	}

	/** "The Smith Residence (11.4 kW)" and "Smith Residence" both become "smith". */
	private static function nameKey(string $name): string
	{
		$words = preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
		$words = array_filter($words, static fn (string $w): bool => !in_array($w, self::NAME_NOISE, true) && !preg_match('/^\d+(\.\d+)?(kw|kwdc)?$/', $w));
		return implode(' ', $words);
	}
}
