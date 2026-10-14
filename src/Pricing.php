<?php
declare(strict_types=1);

namespace App;

/**
 * The build sheet math, in one place. Pure: takes a design row, its lines, arrays and the
 * contingency rules, returns every figure the page shows. Nothing is rounded except the final
 * price (up to the next $100) and the payment milestones; displays round for reading only.
 *
 * Order of operations (same as the old Master Buildsheet, with its known slips fixed):
 *   line cost        = max(unit x qty + setup, minimum) + tax (6% on BOS always, on major
 *                      equipment unless the job is tax exempt; "tax included" and fees untaxed)
 *   base cost        = lines + warranty program + labor + overnight + per diem + site visit
 *   contingency      = base cost x rule % (capped), at least the minimum, or an override with a reason
 *   marked up        = (base cost + contingency) x (1 + markup)
 *   commission       = marked up rounded up to $100, x the adjusted commission %, + step-down amount
 *   final price      = marked up + commission + extras without markup, rounded up to $100
 *   gross margin     = final price - extras - all cost (incl. contingency) - commission
 */
final class Pricing
{
	public const EQUIPMENT_CATS = ['modules', 'inverters', 'mlpe', 'batteries', 'racking'];

	public static function ceilTo(float $v, float $step): float
	{
		if ($step <= 0) {
			return $v;
		}
		// tolerate float noise just above a round number (24700.0000001 stays 24700)
		return ceil(round($v / $step, 6)) * $step;
	}

	/**
	 * @param array $d       presales_designs row (or the same keys from a form)
	 * @param array $lines   presales_design_lines rows (category 'extra' = without markup)
	 * @param array $arrays  presales_design_arrays rows
	 * @param array $rules   presales_contingency_rules rows (active)
	 */
	public static function calculate(array $d, array $lines, array $arrays, array $rules, float $roundTo = 100): array
	{
		$exempt = !empty($d['tax_exempt']);
		$taxRate = (float) ($d['tax_rate'] ?? 0);
		$solarInsure = !empty($d['warranty_is_solarinsure']);

		// System size from the module lines
		$dcWatts = 0.0;
		$panels = 0;
		foreach ($lines as $l) {
			if ($l['category'] === 'modules') {
				$q = max(0, (int) round((float) ($l['qty'] ?? 0)));
				$panels += $q;
				$dcWatts += $q * (float) ($l['watts'] ?? 0);
			}
		}

		$out = [];
		$extras = [];
		$acKw = 0.0;
		$kwh = 0.0;
		$batteryKw = 0.0;
		$equipCost = 0.0;
		$taxTotal = 0.0;
		$hasSitework = false;
		$hasEquipmentRental = false;
		foreach ($lines as $i => $l) {
			$cat = $l['category'];
			if ($cat === 'extra') {
				$amt = (float) ($l['price'] ?? 0) * (float) (($l['qty'] ?? null) === null || $l['qty'] === '' ? 1 : $l['qty']);
				$extras[] = $l + ['amount' => $amt];
				continue;
			}
			$rule = $l['qty_rule'] ?? 'manual';
			$qty = match ($rule) {
				'dc_watts'     => $dcWatts,
				'modules'      => (float) $panels,
				'module_pairs' => (float) (int) ceil($panels / 2),
				default        => max(0.0, (float) ($l['qty'] ?? 0)),
			};
			$unit = (float) ($l['price'] ?? 0) + (float) ($l['shipping'] ?? 0)
				+ (!empty($l['ext_warranty']) ? (float) ($l['ext_warranty_cost'] ?? 0) : 0)
				+ ($solarInsure ? (float) ($l['siw_cost'] ?? 0) : 0);
			$subtotal = 0.0;
			$minApplied = false;
			if ($qty > 0) {
				$subtotal = $unit * $qty + (float) ($l['setup_cost'] ?? 0);
				$min = $l['min_total'] ?? null;
				if ($min !== null && $min !== '' && $subtotal < (float) $min) {
					$subtotal = (float) $min;
					$minApplied = true;
				}
			}
			$class = $l['tax_class'] ?? 'bos';
			$taxable = $class === 'bos' || ($class === 'major' && !$exempt);
			$tax = $taxable ? $subtotal * $taxRate : 0.0;
			$cost = $subtotal + $tax;
			$equipCost += $cost;
			$taxTotal += $tax;
			if (in_array($cat, ['inverters', 'mlpe'], true)) {
				$acKw += $qty * (float) ($l['ac_kw'] ?? 0);
			}
			if ($cat === 'batteries') {
				$kwh += $qty * (float) ($l['kwh'] ?? 0);
				$batteryKw += $qty * (float) ($l['kw'] ?? 0);
			}
			if ($subtotal > 0 && in_array($cat, ['sitework', 'foundation'], true)) {
				$hasSitework = true;
			}
			if ($subtotal > 0 && $cat === 'equipment') {
				$hasEquipmentRental = true;
			}
			$out[$i] = $l + [
				'qty_resolved' => $qty, 'unit_total' => $unit, 'subtotal' => $subtotal, 'min_applied' => $minApplied,
				'taxable' => $taxable, 'tax' => $tax, 'cost' => $cost,
			];
		}

		// Warranty program (per DC watt, no tax)
		$warrantyCost = (float) ($d['warranty_price'] ?? 0) * $dcWatts;

		// Labor
		$laborValue = (float) ($d['labor_value'] ?? 0);
		$labor = ($d['labor_mode'] ?? 'per_watt') === 'total' ? $laborValue : $laborValue * $dcWatts;
		$laborRate = (float) ($d['labor_rate'] ?? 0);
		$manHours = $laborRate > 0 ? $labor / $laborRate : null;
		$overnight = (float) ($d['overnight'] ?? 0);
		$perDiem = (float) ($d['per_diem'] ?? 0);
		$siteVisit = (float) ($d['site_visit'] ?? 0);

		$baseCost = $equipCost + $warrantyCost + $labor + $overnight + $perDiem + $siteVisit;

		// Contingency
		$installType = (string) ($d['install_type'] ?? '');
		$steep = (float) ($d['steep_deg'] ?? 30);
		$mounts = array_column($arrays, 'mount');
		$steepArrays = [];
		foreach ($arrays as $k => $a) {
			if (($a['mount'] ?? '') !== 'tracker' && $a['tilt'] !== null && $a['tilt'] !== '' && (float) $a['tilt'] > $steep) {
				$steepArrays[] = $k;
			}
		}
		$manual = array_map('intval', is_array($d['contingency_manual'] ?? null) ? $d['contingency_manual'] : (json_decode((string) ($d['contingency_manual'] ?? ''), true) ?: []));
		$groundMount = in_array($installType, ['ground', 'tracker'], true) || array_intersect($mounts, ['ground', 'pole', 'tracker']);
		$poleTracker = $installType === 'tracker' || array_intersect($mounts, ['pole', 'tracker']);
		$ruleRows = [];
		$rulePct = 0.0;
		foreach ($rules as $r) {
			$why = null;
			switch ($r['trigger']) {
				case 'always':
					$why = 'Always on';
					break;
				case 'ground_work':
					$why = $hasSitework ? 'Trenching, boring or foundation lines' : ($groundMount ? 'Ground, pole or tracker mount' : null);
					break;
				case 'pole_tracker':
					$why = $poleTracker ? 'Pole or tracker mount' : null;
					break;
				case 'difficult_access':
					$why = $steepArrays ? 'Array steeper than ' . rtrim(rtrim(number_format($steep, 1), '0'), '.') . '°'
						: ($hasEquipmentRental ? 'Equipment rental (crane or lift)' : null);
					break;
			}
			$auto = $why !== null;
			$on = $auto || in_array((int) $r['id'], $manual, true);
			if ($on) {
				$rulePct += (float) $r['pct'];
			}
			$ruleRows[] = $r + ['on' => $on, 'auto' => $auto, 'why' => $why, 'manual_ok' => in_array($r['trigger'], ['manual', 'difficult_access'], true)];
		}
		$cap = (float) ($d['contingency_cap'] ?? 0);
		$appliedPct = $cap > 0 ? min($rulePct, $cap) : $rulePct;
		$ruleAmount = $baseCost * $appliedPct / 100;
		$floor = (float) ($d['contingency_floor'] ?? 0);
		$floorApplied = $baseCost > 0 && $ruleAmount < $floor;
		$contingency = $baseCost > 0 ? max($ruleAmount, $floor) : 0.0;
		$override = $d['contingency_override'] ?? null;
		$overridden = $override !== null && $override !== '' && trim((string) ($d['contingency_reason'] ?? '')) !== '';
		if ($overridden) {
			$contingency = $baseCost * (float) $override / 100;
		}
		$costTotal = $baseCost + $contingency;

		// Markup, commission, price
		$markup = (float) ($d['markup'] ?? 0);
		$markedUp = $costTotal * (1 + $markup);
		$commissionBase = self::ceilTo($markedUp, $roundTo);
		$commission = (float) ($d['commission_rate'] ?? 0) > 0
			? $commissionBase * (float) ($d['commission_adjusted'] ?? 0) + (float) ($d['commission_step_down'] ?? 0)
			: 0.0;
		$extrasTotal = array_sum(array_column($extras, 'amount'));
		$preRound = $markedUp + $commission + $extrasTotal;
		$price = $preRound > 0 ? self::ceilTo($preRound, $roundTo) : 0.0;
		$netRevenue = $price - $extrasTotal;
		$grossMargin = $netRevenue - $costTotal - $commission;

		// Retail share per line: every cost line carries its share of contingency, markup,
		// commission and rounding, so the retail column adds up to the price less extras.
		$factor = $costTotal > 0 ? $netRevenue / $costTotal : 0.0;
		foreach ($out as $i => $l) {
			$out[$i]['retail'] = $l['cost'] * $factor;
			$out[$i]['retail_per_watt'] = $dcWatts > 0 ? $l['cost'] * $factor / $dcWatts : null;
			$out[$i]['cost_per_watt'] = $dcWatts > 0 ? $l['cost'] / $dcWatts : null;
		}

		// Payments: fixed deposit, then milestones as % of the balance; each rounds up to $100
		// except the last, which takes what is left so the total is exact.
		$deposit = (float) ($d['deposit'] ?? 0);
		$milestones = is_array($d['payment_milestones'] ?? null) ? $d['payment_milestones'] : (json_decode((string) ($d['payment_milestones'] ?? ''), true) ?: []);
		$payments = [];
		if ($price > 0) {
			$payments[] = ['label' => 'Deposit', 'amount' => $deposit, 'pct_balance' => null, 'pct_total' => $price > 0 ? $deposit / $price : null];
			$balance = $price - $deposit;
			$left = $balance;
			$n = count($milestones);
			foreach (array_values($milestones) as $k => $m) {
				$amt = $k === $n - 1 ? $left : min($left, self::ceilTo($balance * (float) $m['pct'] / 100, $roundTo));
				$left -= $amt;
				$payments[] = ['label' => (string) $m['label'], 'amount' => $amt, 'pct_balance' => (float) $m['pct'] / 100, 'pct_total' => $amt / $price];
			}
		}
		$milestonePct = array_sum(array_map(static fn ($m) => (float) $m['pct'], $milestones));

		$arraysPanels = array_sum(array_map(static fn ($a) => (int) ($a['panels'] ?? 0), $arrays));

		// Subtotals per category (cost, tax, retail)
		$cats = [];
		foreach ($out as $l) {
			$c = &$cats[$l['category']];
			$c ??= ['subtotal' => 0.0, 'tax' => 0.0, 'cost' => 0.0, 'retail' => 0.0];
			$c['subtotal'] += $l['subtotal'];
			$c['tax'] += $l['tax'];
			$c['cost'] += $l['cost'];
			$c['retail'] += $l['retail'];
			unset($c);
		}

		// Domestic content and FEOC, over the major equipment on the build sheet. Domestic content is
		// cost-weighted across the lines that have a percentage; lines without one are counted.
		$dcCost = 0.0;
		$dcUs = 0.0;
		$dcUnknown = [];
		$feocNo = [];
		$feocUnknown = [];
		foreach ($out as $l) {
			if (!in_array($l['category'], self::EQUIPMENT_CATS, true) || $l['subtotal'] <= 0) {
				continue;
			}
			if ($l['domestic_content_pct'] ?? null) {
				$dcCost += $l['subtotal'];
				$dcUs += $l['subtotal'] * (float) $l['domestic_content_pct'] / 100;
			} elseif (($l['domestic_content_pct'] ?? null) === null || $l['domestic_content_pct'] === '') {
				$dcUnknown[] = $l['name'];
			} else {
				$dcCost += $l['subtotal'];
			}
			$feoc = $l['feoc_ok'] ?? null;
			if ($feoc === null || $feoc === '') {
				$feocUnknown[] = $l['name'];
			} elseif (!(int) $feoc) {
				$feocNo[] = $l['name'];
			}
		}

		return [
			'lines' => $out,
			'extras' => $extras,
			'dc_watts' => $dcWatts,
			'dc_kw' => $dcWatts / 1000,
			'ac_kw' => $acKw,
			'ratio' => $acKw > 0 ? ($dcWatts / 1000) / $acKw : null,
			'panels' => $panels,
			'storage_kwh' => $kwh,
			'storage_kw' => $batteryKw,
			'equipment_cost' => $equipCost,
			'tax_total' => $taxTotal,
			'warranty_cost' => $warrantyCost,
			'labor' => $labor,
			'man_hours' => $manHours,
			'overnight' => $overnight,
			'per_diem' => $perDiem,
			'site_visit' => $siteVisit,
			'base_cost' => $baseCost,
			'contingency' => $contingency,
			'contingency_pct' => $baseCost > 0 ? $contingency / $baseCost : null,
			'contingency_rules' => $ruleRows,
			'contingency_rule_pct' => $rulePct,
			'contingency_applied_pct' => $appliedPct,
			'contingency_capped' => $cap > 0 && $rulePct > $cap,
			'contingency_floor_applied' => $floorApplied && !$overridden,
			'contingency_rule_amount' => max($ruleAmount, $baseCost > 0 ? $floor : 0),
			'contingency_overridden' => $overridden,
			'cost_total' => $costTotal,
			'marked_up' => $markedUp,
			'markup_amount' => $markedUp - $costTotal,
			'commission_base' => $commissionBase,
			'commission' => $commission,
			'to_salesperson' => $commission + $siteVisit,
			'extras_total' => $extrasTotal,
			'pre_round' => $preRound,
			'rounding' => $price - $preRound,
			'price' => $price,
			'net_revenue' => $netRevenue,
			'gross_margin' => $grossMargin,
			'gross_margin_pct' => $netRevenue > 0 ? $grossMargin / $netRevenue : null,
			'margin_if_unused_pct' => $netRevenue > 0 ? ($grossMargin + $contingency) / $netRevenue : null,
			'effective_markup' => $costTotal > 0 ? ($netRevenue - $commission) / $costTotal - 1 : null,
			'price_per_watt' => $dcWatts > 0 ? $price / $dcWatts : null,
			'cost_per_watt' => $dcWatts > 0 ? $costTotal / $dcWatts : null,
			'payments' => $payments,
			'milestone_pct' => $milestonePct,
			'steep_arrays' => $steepArrays,
			'arrays_panels' => $arraysPanels,
			'panels_mismatch' => $arraysPanels > 0 && $panels > 0 && $arraysPanels !== $panels,
			'categories' => $cats,
			'domestic_content_pct' => $dcCost > 0 ? $dcUs / $dcCost : null,
			'domestic_unknown' => $dcUnknown,
			'feoc_no' => $feocNo,
			'feoc_unknown' => $feocUnknown,
		];
	}

	/** Plain-text warranty summary for pasting into OpenSolar. */
	public static function warrantySummary(array $d, array $calcLines, string $trifectaText, string $solarInsureText): string
	{
		$label = ['modules' => 'Modules', 'inverters' => 'Inverter', 'mlpe' => 'Optimizers / microinverters', 'batteries' => 'Battery', 'racking' => 'Racking'];
		$out = ['WARRANTY SUMMARY', '', 'Manufacturer warranties:'];
		$any = false;
		foreach ($calcLines as $l) {
			if (!isset($label[$l['category']]) || ($l['qty_resolved'] ?? 0) <= 0) {
				continue;
			}
			$text = trim((string) ($l['warranty_text'] ?? ''));
			if ($text === '' && $l['category'] === 'racking') {
				continue;
			}
			$line = '- ' . $label[$l['category']] . ' (' . $l['name'] . '): ' . ($text !== '' ? $text : 'standard manufacturer warranty');
			if (!empty($l['ext_warranty']) && !empty($l['ext_warranty_years'])) {
				$line .= '. Extended manufacturer warranty to ' . (int) $l['ext_warranty_years'] . ' years included.';
			}
			$out[] = $line;
			$any = true;
		}
		if (!$any) {
			$out[] = '- None listed yet.';
		}
		$out[] = '';
		$out[] = $trifectaText !== '' ? $trifectaText : 'Trifecta Solar Labor and Workmanship: 10 years.';
		$out[] = '';
		$out[] = !empty($d['warranty_is_solarinsure'])
			? ($solarInsureText !== '' ? $solarInsureText : 'SolarInsure 30 Year Warranty: included.')
			: 'SolarInsure 30 Year Warranty: not included.';
		return implode("\n", $out);
	}
}
