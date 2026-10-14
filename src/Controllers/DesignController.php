<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Activity;
use App\Db;
use App\Designs;
use App\Presales;
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
			[$f, $lines, $arrays] = Designs::fromInput($d, $failed, false);
			$d = array_merge($d, $f);
		} else {
			$lines = Designs::lines($id);
			$arrays = Designs::arrays($id);
		}
		$calc = Designs::calc($d, $lines, $arrays);
		$depositClass = Presales::depositClass($o['customer_type'], (bool) $o['is_agricultural']);
		View::render('presales/design', [
			'title' => $d['name'] . ' - ' . $o['name'],
			'o' => $o, 'd' => $d, 'lines' => $lines, 'arrays' => $arrays, 'calc' => $calc,
			'flags' => Designs::priceFlags($lines, $d),
			'catalog' => Presales::catalogByCategory(),
			'commissionRates' => Presales::commissionRates(),
			'templates' => Presales::paymentTemplates(),
			'depositOptions' => Presales::depositOptions($depositClass, $calc['dc_kw']),
			'depositClass' => $depositClass,
			'siblings' => Db::all('SELECT id, name FROM presales_designs WHERE opportunity_id = ? ORDER BY sort_order, id', [$oppId]),
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
			'flags' => Designs::priceFlags($lines, $d), 'printNotes' => $notes,
			'depositClass' => Presales::depositClass($o['customer_type'], (bool) $o['is_agricultural']),
		], 'print_layout');
	}

	public function save(int $id, int $designId): void
	{
		$oppId = $id;
		$id = $designId;
		[, $d] = $this->find($oppId, $id);
		try {
			[$f, $lines, $arrays] = Designs::fromInput($d, $_POST);
			$merged = array_merge($d, $f);
			$calc = Designs::calc($merged, $lines, $arrays);
			$this->checkDeposit($oppId, $merged, $calc);
			Designs::save($d, $f, $lines, $arrays, $calc);
			$changes = [];
			if (abs((float) $d['price_total'] - $calc['price']) > 0.5) {
				$changes[] = 'price ' . Presales::money((float) $d['price_total']) . ' to ' . Presales::money($calc['price']);
			}
			Activity::event('presales', $oppId, 'Build sheet "' . $f['name'] . '" saved' . ($changes ? ': ' . implode(', ', $changes) : ''));
		} catch (\InvalidArgumentException $e) {
			$_SESSION['design_failed'][$id] = $_POST;
			flash('error', $e->getMessage() . ' Nothing was saved.');
			redirect('/presales/' . $oppId . '/designs/' . $id);
		}
		flash('success', 'Build sheet saved. Final price ' . Presales::money($calc['price']) . '.');
		redirect('/presales/' . $oppId . '/designs/' . $id);
	}

	/** Live calculation for the editor: reads the form as it stands, saves nothing, returns JSON. */
	public function calc(int $id, int $designId): void
	{
		$oppId = $id;
		$id = $designId;
		[$o, $d] = $this->find($oppId, $id);
		header('Content-Type: application/json');
		try {
			[$f, $lines, $arrays] = Designs::fromInput($d, $_POST, false);
		} catch (\InvalidArgumentException $e) {
			echo json_encode(['error' => $e->getMessage()]);
			return;
		}
		$merged = array_merge($d, $f);
		$calc = Designs::calc($merged, $lines, $arrays);
		$flags = Designs::priceFlags($lines, $merged);
		$depositOptions = Presales::depositOptions(Presales::depositClass($o['customer_type'], (bool) $o['is_agricultural']), $calc['dc_kw']);
		echo json_encode(self::payload($calc, $flags, $lines, $depositOptions, $merged));
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
				'unit' => $c ? Presales::unit($c['unit_total']) : '',
				'subtotal' => $c ? $m($c['subtotal']) : '',
				'tax' => $c && $c['tax'] > 0 ? $m($c['tax']) : '',
				'cost' => $c ? $m($c['cost']) : '',
				'retail' => $c ? $m($c['retail']) : '',
				'rpw' => $c && $c['retail_per_watt'] !== null ? Presales::perWatt($c['retail_per_watt']) : '',
				'min' => $c ? $c['min_applied'] : false,
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
				'extras_total' => $m($c['extras_total']),
				'rounding' => $m($c['rounding']),
				'gross_margin' => $m($c['gross_margin']),
				'gross_margin_pct' => $c['gross_margin_pct'] === null ? '-' : Presales::pct($c['gross_margin_pct']),
				'margin_if_unused_pct' => $c['margin_if_unused_pct'] === null ? '-' : Presales::pct($c['margin_if_unused_pct']),
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
			'cats' => array_map(static fn ($t) => ['cost' => $m($t['cost']), 'tax' => $t['tax'] > 0 ? $m($t['tax']) : '-', 'retail' => $m($t['retail'])], $c['categories']),
		];
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
		if (!$o) {
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
