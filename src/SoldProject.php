<?php
declare(strict_types=1);

namespace App;

/**
 * A sold build sheet becomes a project. plan() works out everything the project will get (shown
 * on the Create project page before anything is written); create() writes it; link() ties the
 * build sheet to a project someone already entered by hand, filling only what is blank there.
 *
 * Once a project exists, the sold build sheet is locked (locked()): its numbers are the contract.
 * A change after that is a new build sheet (change orders, not built yet).
 *
 * Payment steps carry the schedule's labels only; invoices and amounts live in QuickBooks.
 */
final class SoldProject
{
	/** Materials sub-step (matched by words in its name) => build sheet categories it lists. */
	private const MATERIALS = [
		'panels' => ['words' => ['panel', 'module'], 'cats' => ['modules']],
		'inverters' => ['words' => ['inverter'], 'cats' => ['inverters', 'mlpe', 'monitoring']],
		'racking' => ['words' => ['racking', 'tracker'], 'cats' => ['racking']],
		'batteries' => ['words' => ['batter'], 'cats' => ['batteries']],
		'bos' => ['words' => ['bos', 'balance'], 'cats' => ['bos', 'electrical']],
	];

	/** Build sheet categories that mean outside sitework on the job. */
	private const SITEWORK = ['sitework', 'foundation', 'trench_parts'];

	/** True when this is the opportunity's sold build sheet and a project was made or linked from it. */
	public static function locked(array $o, array $d): bool
	{
		return ($o['status'] ?? '') === 'sold' && (int) ($o['sold_design_id'] ?? 0) === (int) $d['id'] && !empty($o['project_id']);
	}

	/**
	 * Everything the new project gets from the opportunity and the build sheet.
	 * @return array{project: array, modules: array, inverters: array, batteries: array, arrays: array,
	 *   payments: string[], materials: array<string, string>, sitework: ?string, answers: array,
	 *   warranties: array, monthly: ?array, model: ?string, calc: array, warnings: string[]}
	 */
	public static function plan(array $o, array $d): array
	{
		$lines = Designs::lines((int) $d['id']);
		$arrays = Designs::arrays((int) $d['id']);
		$calc = Designs::calc($d, $lines, $arrays);
		$warnings = [];

		$byCat = [];
		foreach ($calc['lines'] as $l) {
			if (($l['qty_resolved'] ?? 0) > 0 && $l['category'] !== 'extra') {
				$byCat[$l['category']][] = $l;
			}
		}
		$qty = static fn (array $l): int => (int) round((float) $l['qty_resolved']);

		$modules = $inverters = $batteries = [];
		foreach ($byCat['modules'] ?? [] as $l) {
			$modules[] = ['qty' => $qty($l), 'watts' => (float) ($l['watts'] ?? 0), 'description' => $l['name']];
		}
		foreach (array_merge($byCat['inverters'] ?? [], $byCat['mlpe'] ?? []) as $l) {
			// optimizers and rapid shutdown have no AC rating and are not inverters
			if ($l['category'] === 'mlpe' && (float) ($l['ac_kw'] ?? 0) <= 0) {
				continue;
			}
			$inverters[] = ['qty' => $qty($l), 'ac_kw' => (float) ($l['ac_kw'] ?? 0), 'description' => $l['name']];
		}
		foreach ($byCat['batteries'] ?? [] as $l) {
			$batteries[] = ['qty' => $qty($l), 'kwh' => $l['kwh'] !== null ? (float) $l['kwh'] : null,
				'kw' => $l['kw'] !== null ? (float) $l['kw'] : null, 'description' => $l['name']];
		}
		if (!$modules) {
			$warnings[] = 'The build sheet has no modules.';
		}

		// Arrays, with area and perimeter from the site layout
		$stats = Layout::arrayStats(Layout::decode($d['layout_json'] ?? null), $arrays, $lines);
		$rows = [];
		foreach (array_values($arrays) as $i => $a) {
			$mount = isset(Presales::MOUNTS[$a['mount'] ?? '']) ? $a['mount'] : 'roof';
			$rows[] = [
				'name' => $a['name'] ?: null, 'mount' => $mount,
				'material' => isset(Projects::ROOF_MATERIALS[$a['material'] ?? '']) ? $a['material'] : null,
				'azimuth' => $mount === 'tracker' || $a['azimuth'] === null ? null : (int) $a['azimuth'],
				'tilt' => $mount === 'tracker' || $a['tilt'] === null ? null : (float) $a['tilt'],
				'panels' => $a['panels'] !== null ? (int) $a['panels'] : null,
				'shading_pct' => $a['shading_pct'] !== null ? (float) $a['shading_pct'] : null,
				'area_m2' => $stats['rows'][$i]['area'] ?? null, 'perimeter_m' => $stats['rows'][$i]['perimeter'] ?? null,
			];
		}

		// Payment steps: the schedule's labels, in order
		$payments = [];
		foreach ($calc['payments'] as $pay) {
			$label = trim((string) $pay['label']);
			if ($label !== '' && !in_array($label, $payments, true)) {
				$payments[] = $label;
			}
		}

		// A note for each Materials sub-step: what this build sheet sold in that category
		$describe = static function (array $l) use ($qty): string {
			return in_array($l['qty_rule'], ['manual', 'modules', 'module_pairs'], true) ? $qty($l) . ' x ' . $l['name'] : $l['name'] . ' (whole system)';
		};
		$materials = [];
		foreach (self::MATERIALS as $key => $m) {
			$items = [];
			foreach ($m['cats'] as $cat) {
				foreach ($byCat[$cat] ?? [] as $l) {
					$items[] = $describe($l);
				}
			}
			if ($items) {
				$materials[$key] = implode("; ", $items);
			}
		}
		$site = [];
		foreach (self::SITEWORK as $cat) {
			foreach ($byCat[$cat] ?? [] as $l) {
				$site[] = $l['category'] === 'trench_parts' ? null : $describe($l);
			}
		}
		$trenchFt = 0.0;
		foreach ($calc['trench_rows']['trenches'] ?? [] as $t) {
			$trenchFt += (float) ($t['length_ft'] ?? 0);
		}
		if ($trenchFt > 0) {
			$site[] = 'Trench builder: ' . number_format($trenchFt) . ' ft in ' . count($calc['trench_rows']['trenches']) . ' segment' . (count($calc['trench_rows']['trenches']) === 1 ? '' : 's');
		}
		$site = array_values(array_filter($site));
		$sitework = $site ? implode("; ", $site) : null;

		// Closeout and installation questions the build sheet already answers
		$solarInsure = !empty($d['warranty_is_solarinsure']);
		$answers = ['solarinsure' => ['needed' => $solarInsure ? 1 : 0, 'note' => null]];
		$extended = [];
		$otherExtended = [];
		foreach (array_merge($byCat['inverters'] ?? [], $byCat['mlpe'] ?? []) as $l) {
			if (!empty($l['ext_warranty'])) {
				if (stripos($l['name'] . ' ' . ($l['cec_name'] ?? ''), 'solaredge') !== false) {
					$extended[] = (int) ($l['ext_warranty_years'] ?? 0);
				} else {
					$otherExtended[] = $l;
				}
			}
		}
		$years = $extended ? max($extended) : 0;
		$answers['solaredge'] = ['needed' => $extended ? 1 : 0, 'note' => $years > 0 ? $years . ' years' : null];
		if ($sitework) {
			$answers['sitework'] = ['needed' => 1, 'note' => $sitework];
		}
		foreach (json_decode((string) ($d['incentives'] ?? ''), true) ?: [] as $x) {
			if (stripos((string) ($x['label'] ?? ''), 'rebate') !== false) {
				$answers['rebate'] = ['needed' => 1, 'note' => 'On the proposal: ' . $x['label']];
				break;
			}
		}

		// Manufacturer warranties with a known term, plus the production guarantee when it was offered
		$warranties = [];
		$kinds = ['modules' => 'module_product', 'inverters' => 'inverter', 'mlpe' => 'mlpe', 'batteries' => 'battery', 'racking' => 'racking'];
		foreach ($kinds as $cat => $kind) {
			foreach ($byCat[$cat] ?? [] as $l) {
				if ((int) ($l['product_warranty_years'] ?? 0) > 0) {
					$warranties[] = ['kind' => $kind, 'covers' => $l['name'], 'term_years' => (int) $l['product_warranty_years'], 'start_basis' => 'install'];
				}
				if ($cat === 'modules' && (int) ($l['performance_warranty_years'] ?? 0) > 0) {
					$warranties[] = ['kind' => 'module_performance', 'covers' => $l['name'], 'term_years' => (int) $l['performance_warranty_years'], 'start_basis' => 'install'];
				}
			}
		}
		foreach ($otherExtended as $l) {
			if ((int) ($l['ext_warranty_years'] ?? 0) > 0) {
				$warranties[] = ['kind' => 'inverter_extended', 'covers' => $l['name'], 'term_years' => (int) $l['ext_warranty_years'], 'start_basis' => 'install'];
			}
		}
		$prod = Production::saved($d);
		if (!empty($d['guarantee_on'])) {
			$g = Proposal::data($o, $d, false)['guarantee'] ?? null;
			if ($g) {
				$warranties[] = ['kind' => 'production_guarantee', 'provider' => 'Trifecta Solar', 'term_years' => (int) $g['years'], 'start_basis' => 'pto',
					'covers' => rtrim(rtrim(number_format((float) $g['pct'], 2), '0'), '.') . '% of the PVWatts v8 estimate, cumulative',
					'guarantee_kwh' => round((float) $g['kwh']), 'guarantee_rate' => (float) $g['rate']];
			} else {
				$warnings[] = 'The production guarantee is on, but it has no production estimate, so it is not carried over. Add it on the project\'s Warranties card.';
			}
		}

		// Production estimate the proposal led with
		$model = $prod ? Savings::model($d) : null;
		$annual = $prod ? (float) ($prod[$model]['annual_kwh'] ?? 0) : 0.0;
		$monthly = $prod && is_array($prod[$model]['monthly_kwh'] ?? null) && count($prod[$model]['monthly_kwh']) === 12
			? array_map(static fn ($v) => round((float) $v), $prod[$model]['monthly_kwh']) : null;
		if (!$prod) {
			$warnings[] = 'No production estimate on the build sheet, so the project gets no estimated kWh.';
		} elseif (($d['production_key'] ?? '') !== '' && Production::inputs($o, $lines, $arrays, $calc)['key'] !== $d['production_key']) {
			$warnings[] = 'The production estimate is older than the build sheet\'s last changes.';
		}

		$racking = array_values(array_unique(array_map(static fn ($l) => $l['name'], $byCat['racking'] ?? [])));
		$project = [
			'customer_id' => $o['customer_id'] ?: null,
			'salesperson_id' => $o['salesperson_id'] ?: null,
			'customer_type' => $o['customer_type'] ?: null,
			'is_agricultural' => (int) $o['is_agricultural'],
			'install_type' => $d['install_type'] ?: null,
			'racking' => $racking ? implode(', ', $racking) : null,
			'has_pv' => $modules ? 1 : 0,
			'has_batteries' => $batteries ? 1 : 0,
			'tax_exempt' => (int) $d['tax_exempt'],
			'contract_price_cents' => (int) round((float) $calc['price'] * 100),
			'est_annual_kwh' => $annual > 0 ? (int) round($annual) : null,
			'est_monthly_kwh' => $monthly ? json_encode($monthly) : null,
			'site_street' => $o['site_street'] ?: null, 'site_city' => $o['site_city'] ?: null,
			'site_state' => $o['site_state'] ?: null, 'site_zip' => $o['site_zip'] ?: null,
			'utility_id' => $o['utility_id'] ?: null,
			'municipality_id' => $o['municipality_id'] ?: null,
			'drive_url' => $o['drive_url'] ?: null,
			'inverter_internet' => isset(Projects::INVERTER_INTERNET[$d['inverter_internet'] ?? '']) ? $d['inverter_internet'] : null,
			'quote_number' => $d['quote_number'] ?: null,
			'bill_name' => $o['bill_name'] ?: null, 'utility_account' => $o['utility_account'] ?: null,
			'utility_meter' => $o['utility_meter'] ?: null, 'utility_tariff' => $o['utility_tariff'] ?: null,
			'service_address' => $o['service_address'] ?: null,
			'annual_usage_kwh' => $o['annual_usage_kwh'] !== null && $o['annual_usage_kwh'] !== '' ? (int) $o['annual_usage_kwh'] : null,
			'warranty_program' => $solarInsure ? 'solarinsure' : 'trifecta',
			'notes' => trim((string) $d['notes']) !== '' ? trim((string) $d['notes']) : null,
			'design_id' => (int) $d['id'],
		];
		if (!$project['customer_id']) {
			$warnings[] = 'The opportunity has no customer.';
		}
		if ($calc['price'] <= 0) {
			$warnings[] = 'The build sheet has no price.';
		}

		// What the commission record will hold (the build sheet's own plan, else today's plan)
		$draft = Commissions::draft(['id' => 0, 'project_number' => '', 'name' => '', 'salesperson_id' => $project['salesperson_id'],
			'contract_price_cents' => $project['contract_price_cents']], $o + ['sold_design_id' => (int) $d['id']]);
		$commission = Commissions::total($draft + Commissions::compute($draft));

		return ['project' => $project, 'modules' => $modules, 'commission' => $commission, 'inverters' => $inverters, 'batteries' => $batteries,
			'arrays' => $rows, 'payments' => $payments, 'materials' => $materials, 'sitework' => $sitework,
			'answers' => $answers, 'warranties' => $warranties, 'monthly' => $monthly, 'model' => $model,
			'calc' => $calc, 'warnings' => $warnings];
	}

	/**
	 * Create the project. $opts: number, name, signed (Y-m-d or null), contacts (contact ids).
	 * @return int the new project id
	 */
	public static function create(array $o, array $d, array $opts): int
	{
		$plan = self::plan($o, $d);
		return Db::transaction(static function () use ($o, $d, $opts, $plan) {
			$data = $plan['project'] + ['project_number' => $opts['number'], 'name' => $opts['name'], 'created_by' => Auth::id()];
			if ($data['municipality_id']) {
				$data = Projects::municipalityDefaults($data);
			}
			if ($data['notes']) {
				$data['notes_updated_by'] = Auth::id();
				$data['notes_updated_at'] = now_utc();
			}
			$id = Db::insert('projects', $data);
			self::geo($id, $data);
			self::equipment($id, $plan);
			self::arrays($id, $plan['arrays']);
			foreach ($opts['contacts'] as $cid) {
				Db::run('INSERT OR IGNORE INTO project_contacts (project_id, contact_id) VALUES (?, ?)', [$id, (int) $cid]);
			}
			Tasks::instantiate($id, (bool) $data['has_pv'], (bool) $data['has_batteries']);
			if ($opts['signed']) {
				Db::run("UPDATE project_tasks SET done_date = ? WHERE project_id = ? AND gate = 'start_clock'", [$opts['signed'], $id]);
			}
			self::payments($id, $plan['payments']);
			self::taskNotes($id, $plan, false);
			self::warranties($id, $plan['warranties']);

			self::markSold($o, $d, $id);
			Activity::event('project', $id, 'Created project ' . $data['project_number'] . ' ' . $data['name'] . ' from build sheet "' . $d['name'] . '" (opportunity ' . $o['name'] . ')');
			Activity::event('presales', (int) $o['id'], 'Marked Sold with "' . $d['name'] . '" and created project ' . $data['project_number'] . ' ' . $data['name']);
			self::commission($id, (int) $o['id']);
			return $id;
		});
	}

	/**
	 * Tie the sold build sheet to a project that already exists. Fills only what is blank on the
	 * project: fields, equipment and arrays (when it has none), Materials notes, warranty lines of a
	 * kind it doesn't have, and the commission record. Tasks, payments and the warranty program (blank
	 * there means "follow the SolarInsure question") are left as they are.
	 * @return string[] what was filled, for the log and the message
	 */
	public static function link(array $o, array $d, int $projectId): array
	{
		$plan = self::plan($o, $d);
		return Db::transaction(static function () use ($o, $d, $projectId, $plan) {
			$p = Db::one('SELECT * FROM projects WHERE id = ?', [$projectId]);
			$fill = [];
			$labels = [];
			$names = ['customer_id' => 'customer', 'salesperson_id' => 'salesperson', 'customer_type' => 'customer type', 'install_type' => 'install type',
				'racking' => 'racking', 'contract_price_cents' => 'price', 'est_annual_kwh' => 'est. annual kWh', 'est_monthly_kwh' => 'monthly estimate',
				'site_street' => 'street', 'site_city' => 'city', 'site_zip' => 'ZIP', 'utility_id' => 'utility', 'municipality_id' => 'municipality',
				'drive_url' => 'Drive folder', 'inverter_internet' => 'internet', 'quote_number' => 'proposal number', 'bill_name' => 'name on bill',
				'utility_account' => 'utility account', 'utility_meter' => 'meter', 'utility_tariff' => 'utility rate', 'service_address' => 'service address',
				'annual_usage_kwh' => 'yearly use', 'notes' => 'notes', 'design_id' => null];
			foreach ($names as $k => $label) {
				if (($p[$k] === null || $p[$k] === '') && $plan['project'][$k] !== null && $plan['project'][$k] !== '') {
					$fill[$k] = $plan['project'][$k];
					if ($label) {
						$labels[] = $label;
					}
				}
			}
			if (isset($fill['notes'])) {
				$fill['notes_updated_by'] = Auth::id();
				$fill['notes_updated_at'] = now_utc();
			}
			if ($fill) {
				Db::update('projects', $projectId, $fill + ['updated_at' => now_utc()]);
			}
			$eq = Projects::equipment($projectId);
			if (!$eq['modules'] && !$eq['inverters'] && !$eq['batteries']) {
				self::equipment($projectId, $plan);
				if ($plan['modules'] || $plan['inverters'] || $plan['batteries']) {
					$labels[] = 'equipment';
				}
			}
			if (!Projects::roofs($projectId) && $plan['arrays']) {
				self::arrays($projectId, $plan['arrays']);
				$labels[] = 'arrays';
			}
			if (self::taskNotes($projectId, $plan, true)) {
				$labels[] = 'Materials notes';
			}
			$have = array_column(Db::all('SELECT DISTINCT kind FROM project_warranties WHERE project_id = ?', [$projectId]), 'kind');
			$new = array_values(array_filter($plan['warranties'], static fn ($w) => !in_array($w['kind'], $have, true)));
			if ($new) {
				self::warranties($projectId, $new);
				$labels[] = 'warranty lines';
			}
			self::markSold($o, $d, $projectId);
			$what = $labels ? ' Filled blanks: ' . implode(', ', $labels) . '.' : '';
			Activity::event('project', $projectId, 'Linked to build sheet "' . $d['name'] . '" (opportunity ' . $o['name'] . ').' . $what);
			Activity::event('presales', (int) $o['id'], 'Marked Sold with "' . $d['name'] . '" and linked to project ' . $p['project_number'] . ' ' . $p['name']);
			if (self::commission($projectId, (int) $o['id'])) {
				$labels[] = 'commission record';
			}
			return $labels;
		});
	}

	/** Active projects a sold build sheet can be linked to: none from another opportunity, the customer's own first. */
	public static function linkable(array $o): array
	{
		return Db::all("SELECT p.id, p.project_number, p.name, c.name AS customer_name FROM projects p
			LEFT JOIN organizations c ON c.id = p.customer_id
			WHERE p.design_id IS NULL AND p.hold_state IS NOT 'cancelled' AND p.archived_at IS NULL AND p.is_legacy = 0
			  AND p.id NOT IN (SELECT project_id FROM presales_opportunities WHERE project_id IS NOT NULL AND id <> ?)
			ORDER BY (p.customer_id IS ? AND p.customer_id IS NOT NULL) DESC, p.project_number DESC", [(int) $o['id'], $o['customer_id'] ?: null]);
	}

	private static function markSold(array $o, array $d, int $projectId): void
	{
		Db::update('presales_opportunities', (int) $o['id'], ['status' => 'sold', 'sold_design_id' => (int) $d['id'], 'project_id' => $projectId,
			'lost_reason' => null, 'status_at' => now_utc(), 'updated_at' => now_utc()]);
		Db::run('UPDATE projects SET design_id = ? WHERE id = ? AND design_id IS NULL', [(int) $d['id'], $projectId]);
	}

	/** Township and county lookup, like a project entered by hand; the opportunity's municipality was confirmed there. */
	private static function geo(int $id, array $data): void
	{
		$address = Geo::address((string) $data['site_street'], (string) $data['site_city'], (string) $data['site_state'], (string) $data['site_zip']);
		if ($address === '') {
			return;
		}
		$lookup = Geo::lookup((string) $data['site_street'], (string) $data['site_city'], (string) $data['site_state'], (string) $data['site_zip']);
		if (!$lookup) {
			return;
		}
		$overridden = $data['municipality_id'] && Geo::mismatch(['municipality_id' => $data['municipality_id'], 'muni_confirmed_id' => null], $lookup);
		Db::update('projects', $id, Geo::columns($lookup, $address) + ['muni_confirmed_id' => $overridden ? $data['municipality_id'] : null]);
	}

	private static function equipment(int $id, array $plan): void
	{
		foreach (['modules' => 'project_modules', 'inverters' => 'project_inverters', 'batteries' => 'project_batteries'] as $k => $table) {
			foreach ($plan[$k] as $i => $row) {
				Db::insert($table, $row + ['project_id' => $id, 'sort_order' => $i]);
			}
		}
	}

	private static function arrays(int $id, array $rows): void
	{
		foreach ($rows as $i => $row) {
			Db::insert('project_roofs', $row + ['project_id' => $id, 'sort_order' => $i]);
		}
	}

	/** Replace the template's generic payment steps with the build sheet's schedule (labels only). */
	private static function payments(int $id, array $labels): void
	{
		if (!$labels) {
			return;
		}
		$owner = Db::value("SELECT owner_id FROM project_tasks WHERE project_id = ? AND phase = 'payments' AND owner_id IS NOT NULL LIMIT 1", [$id]);
		Db::run("DELETE FROM project_tasks WHERE project_id = ? AND phase = 'payments'", [$id]);
		foreach ($labels as $i => $label) {
			Db::insert('project_tasks', ['project_id' => $id, 'name' => $label, 'phase' => 'payments', 'sort_order' => ($i + 1) * 10,
				'needed' => 1, 'owner_id' => $owner]);
		}
	}

	/**
	 * Notes on the Materials sub-steps and answers to the questions the build sheet settles.
	 * With $blanksOnly (linking), only empty notes and unanswered questions are filled.
	 * @return int how many items changed
	 */
	private static function taskNotes(int $id, array $plan, bool $blanksOnly): int
	{
		$n = 0;
		$set = static function (array $t, array $upd) use ($id, &$n): void {
			if (isset($upd['note'])) {
				$upd['note_updated_by'] = Auth::id();
				$upd['note_updated_at'] = now_utc();
			}
			Db::update('project_tasks', (int) $t['id'], $upd + ['updated_at' => now_utc()]);
			$n++;
		};
		$materials = Db::one("SELECT id FROM project_tasks WHERE project_id = ? AND parent_id IS NULL AND name = 'Materials'", [$id]);
		if ($materials) {
			foreach (Db::all('SELECT * FROM project_tasks WHERE parent_id = ?', [(int) $materials['id']]) as $t) {
				$name = strtolower((string) $t['name']);
				foreach (self::MATERIALS as $key => $m) {
					$hit = array_filter($m['words'], static fn ($w) => str_contains($name, $w));
					if ($hit && isset($plan['materials'][$key]) && (!$blanksOnly || trim((string) $t['note']) === '')) {
						$set($t, ['note' => $plan['materials'][$key]]);
						break;
					}
				}
			}
		}
		$questions = ['solarinsure' => 'SolarInsure registration', 'solaredge' => 'SolarEdge extended warranty',
			'sitework' => 'Third-party sitework', 'rebate' => 'Utility rebate'];
		foreach ($questions as $key => $tplName) {
			if (!isset($plan['answers'][$key])) {
				continue;
			}
			$t = Db::one('SELECT t.* FROM project_tasks t JOIN task_templates tt ON tt.id = t.template_id
				WHERE t.project_id = ? AND t.parent_id IS NULL AND tt.name = ? ORDER BY t.id LIMIT 1', [$id, $tplName]);
			if (!$t || ($blanksOnly && $t['needed'] !== null)) {
				continue;
			}
			$a = $plan['answers'][$key];
			$upd = ['needed' => $a['needed']];
			if ($a['note'] !== null && trim((string) $t['note']) === '') {
				$upd['note'] = $a['note'];
			}
			$set($t, $upd);
		}
		return $n;
	}

	private static function warranties(int $id, array $rows): void
	{
		foreach ($rows as $w) {
			Db::insert('project_warranties', $w + ['project_id' => $id, 'source' => 'build_sheet', 'created_by' => Auth::id()]);
		}
	}

	/** The commission record, drafted from the sold build sheet, unless the project has one. Returns true when made. */
	private static function commission(int $projectId, int $oppId): bool
	{
		if (Commissions::forProject($projectId) || Db::value('SELECT 1 FROM commissions WHERE opportunity_id = ?', [$oppId])) {
			return false;
		}
		$p = Db::one('SELECT * FROM projects WHERE id = ?', [$projectId]);
		$o = Db::one('SELECT * FROM presales_opportunities WHERE id = ?', [$oppId]);
		$c = Commissions::draft($p, $o);
		$c += Commissions::compute($c);
		$c += ['status' => 'active', 'created_by' => Auth::id()];
		$cid = Db::insert('commissions', $c);
		Activity::event('commission', $cid, 'Commission set up from the sold build sheet: ' . Presales::money(Commissions::total($c)) . ' to pay');
		Activity::event('project', $projectId, 'Sales commission set up from the sold build sheet (' . Presales::money(Commissions::total($c)) . ')');
		return true;
	}
}
