<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Db;
use App\Designs;
use App\Presales;
use App\Production;
use App\Savings;
use App\Trench;
use App\View;

/** One build sheet: the editor, its live calculation, copy, delete and "copy at today's prices". */
final class DesignController
{
	public function show(int $id, int $designId): void
	{
		$oppId = $id;
		$id = $designId;
		[$o, $d] = $this->find($oppId, $id);
		$failed = $_SESSION['design_failed'][$id] ?? null;
		unset($_SESSION['design_failed'][$id]);
		if ($failed) {
			// Show what was typed after a failed save, read leniently
			[$f, $lines, $arrays, $trench] = Designs::fromInput($d, $failed, false);
			$d = array_merge($d, $f);
		} else {
			$lines = Designs::lines($id);
			$arrays = Designs::arrays($id);
			$trench = Designs::trench($id);
		}
		$calc = Designs::calc($d, $lines, $arrays, $trench);
		$depositClass = Presales::depositClass($o['customer_type'], (bool) $o['is_agricultural']);
		$prodIn = Production::inputs($o, $lines, $arrays, $calc);
		$saved = Designs::calc($d, Designs::lines($id), Designs::arrays($id));
		View::render('presales/design', [
			'prodIn' => $prodIn, 'prod' => Production::saved($d),
			'sav' => Savings::forDesign($o, $d, Designs::lines($id), $saved),
			'contacts' => $o['customer_id'] ? Db::all("SELECT * FROM contacts WHERE owner_type = 'organization' AND owner_id = ? AND is_active = 1 ORDER BY is_primary DESC, name", [(int) $o['customer_id']]) : [],
			'title' => $d['name'] . ' - ' . $o['name'],
			'o' => $o, 'd' => $d, 'lines' => $lines, 'arrays' => $arrays, 'calc' => $calc,
			'flags' => self::flags($lines, $d, $calc),
			'trenchView' => self::trenchView($calc),
			'catalog' => Presales::catalogByCategory(),
			'commissionRates' => Presales::commissionRates(),
			'templates' => Presales::paymentTemplates(),
			'depositOptions' => Presales::depositOptions($depositClass, $calc['dc_kw']),
			'depositClass' => $depositClass,
			'siblings' => Db::all('SELECT id, name FROM presales_designs WHERE opportunity_id = ? ORDER BY sort_order, id', [$oppId]),
			'who' => Db::one('SELECT cu.name AS created_name, uu.name AS updated_name FROM presales_designs d LEFT JOIN users cu ON cu.id = d.created_by LEFT JOIN users uu ON uu.id = d.updated_by WHERE d.id = ?', [$designId]),
		]);
	}

	/** Everything on one printable sheet (saved values). */
	public function summary(int $id, int $designId): void
	{
		[$o, $d] = $this->find($id, $designId);
		$o = Db::one('SELECT o.*, c.name AS customer_name, u.name AS salesperson_name FROM presales_opportunities o
			LEFT JOIN organizations c ON c.id = o.customer_id LEFT JOIN users u ON u.id = o.salesperson_id WHERE o.id = ?', [$id]);
		$lines = Designs::lines($designId);
		$arrays = Designs::arrays($designId);
		$calc = Designs::calc($d, $lines, $arrays);
		$notes = [];
		foreach (Db::all('SELECT i.id, i.notes FROM catalog_items i JOIN presales_design_lines l ON l.catalog_item_id = i.id
			WHERE l.design_id = ? AND i.print_notes = 1 AND i.notes IS NOT NULL', [$designId]) as $r) {
			$notes[(int) $r['id']] = $r['notes'];
		}
		View::render('presales/summary', [
			'title' => $d['name'] . ' summary', 'o' => $o, 'd' => $d, 'lines' => $lines, 'arrays' => $arrays, 'calc' => $calc,
			'flags' => self::flags($lines, $d, $calc), 'printNotes' => $notes, 'trenchView' => self::trenchView($calc),
			'who' => Db::one('SELECT cu.name AS created_name, uu.name AS updated_name FROM presales_designs d LEFT JOIN users cu ON cu.id = d.created_by LEFT JOIN users uu ON uu.id = d.updated_by WHERE d.id = ?', [$designId]),
			'depositClass' => Presales::depositClass($o['customer_type'], (bool) $o['is_agricultural']),
		], 'print_layout');
	}

	public function save(int $id, int $designId): void
	{
		$calc = $this->persist($id, $designId);
		// Site layout, Proposal and Summary save first, then go on (those pages show no messages, except the layout)
		$then = ['layout' => '/layout', 'proposal' => '/proposal', 'summary' => '/summary'][(string) ($_POST['then'] ?? '')] ?? '';
		if ($then === '' || $then === '/layout') {
			flash('success', 'Build sheet saved. Final price ' . Presales::money($calc['price']) . '.');
		}
		redirect('/presales/' . $id . '/designs/' . $designId . $then);
	}

	/** Save the build sheet from the form; on a problem, keep what was typed and go back to it. */
	private function persist(int $oppId, int $id): array
	{
		[, $d] = $this->find($oppId, $id);
		try {
			[$f, $lines, $arrays, $trench] = Designs::fromInput($d, $_POST);
			$merged = array_merge($d, $f);
			$calc = Designs::calc($merged, $lines, $arrays, $trench);
			$this->checkDeposit($oppId, $merged, $calc);
			$before = [];
			foreach (Designs::lines($id) as $l) {
				$before[(int) $l['id']] = $l;
			}
			Designs::save($d, $f, $lines, $arrays, $calc, $trench);
			$changes = [];
			if (abs((float) $d['price_total'] - $calc['price']) > 0.5) {
				$changes[] = 'price ' . Presales::money((float) $d['price_total']) . ' to ' . Presales::money($calc['price']);
			}
			$changes = [...$changes, ...self::overrideChanges($before, $lines, $calc)];
			if ((int) $d['guarantee_on'] !== (int) $f['guarantee_on']) {
				$changes[] = 'production guarantee ' . ($f['guarantee_on'] ? 'turned on' : 'turned off');
			}
			Activity::event('presales', $oppId, 'Build sheet "' . $f['name'] . '" saved' . ($changes ? ': ' . implode(', ', $changes) : ''));
		} catch (\InvalidArgumentException $e) {
			$_SESSION['design_failed'][$id] = $_POST;
			flash('error', $e->getMessage() . ' Nothing was saved.');
			redirect('/presales/' . $oppId . '/designs/' . $id);
		}
		return $calc;
	}

	/** Save the build sheet, then run its production estimate (SAM detailed and PVWatts). */
	public function production(int $id, int $designId): void
	{
		$this->persist($id, $designId);
		[$o, $d] = $this->find($id, $designId);
		$lines = Designs::lines($designId);
		$arrays = Designs::arrays($designId);
		$in = Production::inputs($o, $lines, $arrays, Designs::calc($d, $lines, $arrays));
		$back = '/presales/' . $id . '/designs/' . $designId . '#sec-production';
		if ($in['missing']) {
			flash('error', 'Saved, but production can\'t run yet. Still needed: ' . implode(' ', $in['missing']));
			redirect($back);
		}
		set_time_limit(180);
		$r = Production::run($o, $d, $in);
		if (!$r['ok']) {
			flash('error', 'Saved, but the production estimate failed: ' . $r['error']);
			redirect($back);
		}
		Activity::event('presales', $id, 'Production estimate for "' . $d['name'] . '": SAM detailed ' . number_format((float) $r['detailed']['annual_kwh'])
			. ' kWh, PVWatts ' . number_format((float) $r['pvwatts']['annual_kwh']) . ' kWh a year');
		flash('success', 'Saved and ran the production estimate.');
		redirect($back);
	}

	/** Log lines for price overrides that were added, changed or removed in this save. */
	private static function overrideChanges(array $before, array $lines, array $calc): array
	{
		$out = [];
		$seen = [];
		foreach ($lines as $i => $l) {
			if ($l['category'] === 'extra') {
				continue;
			}
			$old = $l['id'] ? ($before[(int) $l['id']] ?? null) : null;
			if ($old) {
				$seen[(int) $l['id']] = true;
			}
			$was = $old && $old['price_override'] !== null ? [(float) $old['price_override'], (string) $old['override_reason']] : null;
			$now = !empty($calc['lines'][$i]['overridden']) ? [(float) $l['price_override'], (string) $l['override_reason']] : null;
			if ($now && $now !== $was) {
				$out[] = 'price override on ' . $l['name'] . ': ' . Presales::price($calc['lines'][$i]['catalog_unit'], $l['qty_rule']) . ' to '
					. Presales::price($calc['lines'][$i]['unit_total'], $l['qty_rule']) . ' (' . $now[1] . ')';
			} elseif ($was && !$now) {
				$out[] = 'price override removed on ' . $l['name'];
			}
		}
		foreach ($before as $id => $old) {
			if (!isset($seen[$id]) && $old['price_override'] !== null) {
				$out[] = 'line with a price override removed: ' . $old['name'];
			}
		}
		return $out;
	}

	/** Live calculation for the editor: reads the form as it stands, saves nothing, returns JSON. */
	public function calc(int $id, int $designId): void
	{
		$oppId = $id;
		$id = $designId;
		[$o, $d] = $this->find($oppId, $id);
		header('Content-Type: application/json');
		try {
			[$f, $lines, $arrays, $trench] = Designs::fromInput($d, $_POST, false);
		} catch (\InvalidArgumentException $e) {
			echo json_encode(['error' => $e->getMessage()]);
			return;
		}
		$merged = array_merge($d, $f);
		$calc = Designs::calc($merged, $lines, $arrays, $trench);
		$flags = self::flags($lines, $merged, $calc);
		$depositOptions = Presales::depositOptions(Presales::depositClass($o['customer_type'], (bool) $o['is_agricultural']), $calc['dc_kw']);
		$prod = Production::inputs($o, $lines, $arrays, $calc);
		echo json_encode(self::payload($calc, $flags, $lines, $depositOptions, $merged) + ['production' => [
			'missing' => $prod['missing'], 'stale' => !empty($d['production_json']) && $prod['key'] !== $d['production_key'],
		]]);
	}

	/** Values the editor shows, keyed for public/assets/js/presales.js. */
	public static function payload(array $calc, array $flags, array $lines, array $depositOptions, array $d): array
	{
		$m = static fn ($v) => Presales::money($v);
		$lineOut = [];
		foreach ($lines as $i => $l) {
			if ($l['category'] === 'extra') {
				continue;
			}
			$c = $calc['lines'][$i] ?? null;
			$fl = $flags['lines'][$i] ?? null;
			$lineOut[] = [
				'key' => (string) ($l['_key'] ?? ''),
				'cat' => $l['category'],
				'qty' => $c ? Presales::field($c['qty_resolved'], 2) : '',
				'unit' => $c ? Presales::price($c['unit_total'], $c['qty_rule']) : '',
				'subtotal' => $c ? $m($c['subtotal']) : '',
				'tax' => $c && $c['tax'] > 0 ? $m($c['tax']) : '',
				'cost' => $c ? $m($c['cost']) : '',
				'retail' => $c ? $m($c['retail']) : '',
				'rpw' => $c && $c['retail_per_watt'] !== null ? Presales::perWatt($c['retail_per_watt']) : '',
				'min' => $c ? $c['min_applied'] : false,
				'overridden' => $c ? $c['overridden'] : false,
				'override_pending' => $c ? $c['override_pending'] : false,
				'catalog_unit' => $c ? Presales::price($c['catalog_unit'], $c['qty_rule']) : '',
				'why' => $c && $c['overridden'] ? (string) $c['override_reason'] : '',
				'pass' => $c ? $c['pass'] : false,
				'pays' => $c ? $c['pays_sales'] : false,
				'changed' => $fl['changed'] ?? false,
				'stale' => $fl['stale'] ?? false,
				'retired' => $fl['retired'] ?? false,
				'price_date' => !empty($fl['catalog']['price_updated_at']) ? fmt_date($fl['catalog']['price_updated_at']) : '',
			];
		}
		$rules = [];
		foreach ($calc['contingency_rules'] as $r) {
			$rules[] = ['id' => (int) $r['id'], 'on' => $r['on'], 'auto' => $r['auto'], 'why' => $r['why'] ?? ''];
		}
		$payments = [];
		foreach ($calc['payments'] as $p) {
			$payments[] = ['label' => $p['label'], 'amount' => $m($p['amount']),
				'pct_balance' => $p['pct_balance'] === null ? '' : Presales::pct($p['pct_balance'], 0),
				'pct_total' => $p['pct_total'] === null ? '' : Presales::pct($p['pct_total'])];
		}
		$c = $calc;
		return [
			'v' => [
				'price' => $m($c['price']),
				'price_plain' => number_format($c['price'], 0, '.', ''),
				'dc_kw' => number_format($c['dc_kw'], 2) . ' kW',
				'ac_kw' => number_format($c['ac_kw'], 2) . ' kW',
				'ratio' => $c['ratio'] === null ? '-' : number_format($c['ratio'], 2),
				'panels' => (string) $c['panels'],
				'storage' => $c['storage_kwh'] > 0 ? number_format($c['storage_kwh'], 1) . ' kWh' : '-',
				'ppw' => $c['price_per_watt'] === null ? '-' : Presales::perWatt($c['price_per_watt']),
				'cpw' => $c['cost_per_watt'] === null ? '-' : Presales::perWatt($c['cost_per_watt']),
				'equipment_cost' => $m($c['equipment_cost']),
				'tax_total' => $m($c['tax_total']),
				'warranty_cost' => $m($c['warranty_cost']),
				'labor' => $m($c['labor']),
				'labor_pw' => $c['dc_watts'] > 0 ? Presales::perWatt($c['labor'] / $c['dc_watts']) : '-',
				'man_hours' => $c['man_hours'] === null ? '-' : number_format($c['man_hours'], 1) . ' man-hours',
				'site_visit' => $m($c['site_visit']),
				'expenses' => $m($c['overnight'] + $c['per_diem']),
				'overnight' => $m($c['overnight']),
				'per_diem' => $m($c['per_diem']),
				'domestic' => self::domesticText($c),
				'feoc' => self::feocText($c),
				'base_cost' => $m($c['base_cost']),
				'contingency' => $m($c['contingency']),
				'contingency_pct' => $c['contingency_pct'] === null ? '-' : Presales::pct($c['contingency_pct']),
				'contingency_rule_pct' => rtrim(rtrim(number_format($c['contingency_rule_pct'], 2), '0'), '.') . '%',
				'contingency_applied_pct' => rtrim(rtrim(number_format($c['contingency_applied_pct'], 2), '0'), '.') . '%',
				'contingency_rule_amount' => $m($c['contingency_rule_amount']),
				'cost_total' => $m($c['cost_total']),
				'markup_amount' => $m($c['markup_amount']),
				'commission' => $m($c['commission']),
				'to_salesperson' => $m($c['to_salesperson']),
				'payout_breakdown' => self::payoutText($c),
				'handoff_text' => $c['handoff_share'] > 0 ? self::handoffText($c, Db::one('SELECT salesperson_id, handoff_user_id FROM presales_opportunities WHERE id = ?', [(int) $d['opportunity_id']]) ?? []) : '',
				'bonus_text' => self::bonusText($c),
				'pass_total' => $m($c['pass_total']),
				'commissionable' => $m($c['commissionable']),
				'extras_total' => $m($c['extras_total']),
				'rounding' => $m($c['rounding']),
				'gross_margin' => $m($c['gross_margin']),
				'gross_margin_pct' => $c['gross_margin_pct'] === null ? '-' : Presales::pct($c['gross_margin_pct']),
				'margin_if_unused_pct' => $c['margin_if_unused_pct'] === null ? '-' : Presales::pct($c['margin_if_unused_pct']),
				'markup_pct' => Presales::pct((float) $d['markup']),
				'domestic_short' => $c['domestic_content_pct'] === null ? 'unknown' : Presales::pct($c['domestic_content_pct']),
				'effective_markup' => $c['effective_markup'] === null ? '-' : Presales::pct($c['effective_markup']),
				'net_revenue' => $m($c['net_revenue']),
				'milestone_pct' => rtrim(rtrim(number_format($c['milestone_pct'], 2), '0'), '.') . '%',
			],
			'state' => [
				'capped' => $c['contingency_capped'],
				'floor' => $c['contingency_floor_applied'],
				'overridden' => $c['contingency_overridden'],
				'mismatch' => $c['panels_mismatch'],
				'arrays_panels' => $c['arrays_panels'],
				'milestones_bad' => abs($c['milestone_pct'] - 100) > 0.001,
				'warranty_changed' => !empty($flags['warranty']['changed']),
				'changed' => $flags['changed'],
				'stale' => $flags['stale'],
			],
			'lines' => $lineOut,
			'rules' => $rules,
			'payments' => $payments,
			'steep' => $c['steep_arrays'],
			'deposit_options' => array_map(static fn ($a) => ['value' => Presales::field($a, 2), 'label' => $m($a)], $depositOptions),
			'warranty_summary' => $c['warranty_summary'],
			'checks' => $c['checks'],
			'cats' => array_map(static fn ($t) => ['cost' => $m($t['cost']), 'tax' => $t['tax'] > 0 ? $m($t['tax']) : '-', 'retail' => $m($t['retail'])], $c['categories']),
			'trench' => self::trenchView($c),
		];
	}

	/** Price flags for the lines, plus trench builder parts whose catalog price changed. */
	private static function flags(array $lines, array $d, array $calc): array
	{
		$flags = Designs::priceFlags($lines, $d);
		$flags['trench_changed'] = Designs::trenchChanged($calc);
		$flags['changed'] += count($flags['trench_changed']);
		return $flags;
	}

	/** What the trench builder shows: results per circuit and segment, the parts priced, warnings. */
	public static function trenchView(array $calc): array
	{
		$m = static fn ($v) => Presales::money($v);
		$t = $calc['trench'];
		$circuits = [];
		foreach ($t['circuits'] as $k => $c) {
			$circuits[$k] = [
				'text' => $c['text'],
				'conduit' => $c['conduit'],
				'drop' => $c['drop'] === null ? '' : number_format($c['drop'], 1) . '% drop',
				'length' => $c['length'] === null ? '' : number_format($c['length']) . ' ft of wire run',
				'warn' => $c['warn'],
			];
		}
		$segments = [];
		foreach ($t['segments'] as $k => $s) {
			$segments[$k] = implode('; ', array_map(static fn ($x) => $x['circuit'] . ': ' . Trench::conduitText($x['count'], $x['size'], $x['fill']), $s['conduits']));
		}
		$parts = [];
		$cost = 0.0;
		$retail = 0.0;
		foreach ($calc['lines'] as $l) {
			if (empty($l['trench'])) {
				continue;
			}
			$ft = $l['trench_unit'] === 'ft';
			$parts[] = ['name' => $l['name'], 'qty' => $ft ? number_format((float) $l['qty_resolved']) . ' ft' : (string) (int) $l['qty_resolved'],
				'unit' => Presales::unit($l['unit_total']) . ($ft ? '/ft' : ''), 'tax' => $l['tax'] > 0 ? $m($l['tax']) : '-', 'cost' => $m($l['cost']), 'retail' => $m($l['retail']),
				'missing' => in_array($l['trench_key'], $t['missing'], true)];
			$cost += $l['cost'];
			$retail += $l['retail'];
		}
		return ['circuits' => $circuits, 'segments' => $segments, 'parts' => $parts, 'cost' => $m($cost), 'retail' => $m($retail),
			'warnings' => $t['warnings']];
	}

	/** "Commission $1,500 + self-generated bonus $300 + Site visit $100": what the salesperson is paid. */
	public static function payoutText(array $c): string
	{
		if ($c['to_salesperson'] <= 0) {
			return '';
		}
		$parts = ['commission ' . Presales::money($c['commission'])];
		if ($c['self_gen'] > 0) {
			$parts[] = 'self-generated bonus ' . Presales::money($c['self_gen']);
		}
		$items = [];
		foreach ($c['lines'] as $l) {
			if (!empty($l['pays_sales']) && $l['cost'] > 0) {
				$items[] = $l['name'];
			}
		}
		$other = $c['cost_payout'] + $c['pass_payout'] + $c['site_visit'];
		if ($other > 0) {
			$parts[] = ($items ? implode(', ', $items) : 'site visit') . ' ' . Presales::money($other);
		}
		return ucfirst(implode(' + ', $parts));
	}

	/** "Split: 80% to Pat Example, 20% ($300) to Sam Example": the in-house handoff split. */
	public static function handoffText(array $c, array $o): string
	{
		if ($c['handoff_share'] <= 0) {
			return '';
		}
		$name = static fn ($id) => (string) Db::value('SELECT name FROM users WHERE id = ?', [(int) $id]);
		return 'Split: ' . Presales::pct(1 - $c['handoff_share'], 0) . ' to ' . ($name($o['salesperson_id'] ?? 0) ?: 'the salesperson')
			. ', ' . Presales::pct($c['handoff_share'], 0) . ' (' . Presales::money($c['handoff_amount']) . ') to '
			. ($name($o['handoff_user_id'] ?? 0) ?: 'the salesperson who handed it off');
	}

	/** Bonuses paid out of margin, not added to the price. */
	public static function bonusText(array $c): string
	{
		if ($c['self_gen'] > 0) {
			return 'Self-generated lead bonus ' . Presales::money($c['self_gen']) . ', paid from margin';
		}
		if ($c['referral_bonus'] > 0) {
			return 'Referral bonus ' . Presales::money($c['referral_bonus']) . ' to the referrer, paid from margin';
		}
		if (($c['lead_source'] ?? null) === 'self_gen' && empty($c['self_gen_allowed'])) {
			return 'Repeat customer: no self-generated bonus';
		}
		return '';
	}

	/** "Domestic content about 12% of major equipment cost (2 items unknown)". */
	public static function domesticText(array $c): string
	{
		$unknown = count($c['domestic_unknown']);
		if ($c['domestic_content_pct'] === null) {
			return $unknown ? 'Unknown: no domestic content % on ' . $unknown . ' major equipment item' . ($unknown > 1 ? 's' : '') : 'No major equipment yet';
		}
		return Presales::pct($c['domestic_content_pct']) . ' of major equipment cost' . ($unknown ? ' (' . $unknown . ' item' . ($unknown > 1 ? 's' : '') . ' unknown, left out)' : '');
	}

	public static function feocText(array $c): string
	{
		$no = count($c['feoc_no']);
		$unknown = count($c['feoc_unknown']);
		if (!$no && !$unknown) {
			return 'All major equipment marked FEOC compliant';
		}
		return trim(($no ? $no . ' not compliant' : '') . ($no && $unknown ? ', ' : '') . ($unknown ? $unknown . ' unknown' : ''));
	}

	/** The deposit must be one the Rates page allows for this customer type and size. */
	private function checkDeposit(int $oppId, array $d, array $calc): void
	{
		if ($d['deposit'] === null) {
			return;
		}
		$o = Db::one('SELECT customer_type, is_agricultural FROM presales_opportunities WHERE id = ?', [$oppId]);
		$class = Presales::depositClass($o['customer_type'] ?? null, (bool) ($o['is_agricultural'] ?? 0));
		$allowed = Presales::depositOptions($class, $calc['dc_kw']);
		if ($allowed && !in_array(round((float) $d['deposit'], 2), array_map(static fn ($a) => round($a, 2), $allowed), true)) {
			throw new \InvalidArgumentException('A ' . Presales::money((float) $d['deposit']) . ' deposit is not allowed for a ' . strtolower(Presales::CLASS_LABELS[$class]) . ' system of '
				. number_format($calc['dc_kw'], 1) . ' kW. Allowed: ' . implode(', ', array_map([Presales::class, 'money'], $allowed)) . '.');
		}
	}

	public function create(int $id): void
	{
		$oppId = $id;
		$this->opp($oppId);
		$id = Designs::create($oppId, Designs::defaultName($oppId));
		redirect('/presales/' . $oppId . '/designs/' . $id);
	}

	public function duplicate(int $id, int $designId): void
	{
		$oppId = $id;
		$id = $designId;
		[, $d] = $this->find($oppId, $id);
		$newId = Designs::duplicate($d, $this->copyName($oppId, $d['name']));
		flash('success', 'Copied. This is the new copy; the original is unchanged.');
		redirect('/presales/' . $oppId . '/designs/' . $newId);
	}

	/** Always keeps the original: makes a copy and brings the copy up to today's catalog prices. */
	public function reprice(int $id, int $designId): void
	{
		$oppId = $id;
		$id = $designId;
		[, $d] = $this->find($oppId, $id);
		$newId = Designs::duplicate($d, $this->copyName($oppId, $d['name'] . ' (' . date('m/d/Y') . ' prices)'));
		$n = Designs::reprice(Designs::find($newId));
		Activity::event('presales', $oppId, 'Build sheet "' . $d['name'] . '" copied at today\'s catalog prices (' . $n . ' lines)');
		flash('success', 'This copy uses today\'s catalog prices. The original build sheet is unchanged.');
		redirect('/presales/' . $oppId . '/designs/' . $newId);
	}

	public function delete(int $id, int $designId): void
	{
		$oppId = $id;
		$id = $designId;
		[$o, $d] = $this->find($oppId, $id);
		if ((int) $o['sold_design_id'] === $id) {
			flash('error', 'This build sheet is the one that sold, so it can\'t be deleted.');
			redirect('/presales/' . $oppId . '/designs/' . $id);
		}
		Db::run('DELETE FROM presales_designs WHERE id = ?', [$id]);
		Activity::event('presales', $oppId, 'Build sheet "' . $d['name'] . '" deleted');
		flash('success', 'Build sheet "' . $d['name'] . '" deleted.');
		redirect('/presales/' . $oppId);
	}

	private function copyName(int $oppId, string $base): string
	{
		$name = str_contains($base, '(') ? $base : $base . ' (copy)';
		$i = 2;
		$try = $name;
		while (Db::value('SELECT 1 FROM presales_designs WHERE opportunity_id = ? AND name = ?', [$oppId, $try])) {
			$try = $name . ' ' . $i++;
		}
		return $try;
	}

	private function opp(int $oppId): array
	{
		$o = Db::one('SELECT * FROM presales_opportunities WHERE id = ?', [$oppId]);
		if (!$o || !Presales::canSee($o)) {
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			exit;
		}
		return $o;
	}

	private function find(int $oppId, int $id): array
	{
		$o = $this->opp($oppId);
		$d = Db::one('SELECT * FROM presales_designs WHERE id = ? AND opportunity_id = ?', [$id, $oppId]);
		if (!$d) {
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			exit;
		}
		return [$o, $d];
	}
}
