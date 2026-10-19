<?php
declare(strict_types=1);

namespace App;

/**
 * A build sheet's savings: the customer's electric bill with and without the system, SRECs, and
 * the money over the analysis period (payback, net present value, return), from the production
 * estimate (App\Production), the opportunity's use and OpenEI rate, and the build sheet's price.
 *
 * Bills follow Pennsylvania net metering: the customer charge is always billed; a month's excess
 * production carries forward as kWh credits that cover later months; credit left at the end of the
 * net metering year (May 31, the PJM planning year) is paid at the Price to Compare. Each year runs
 * June to May. Production falls each year by the module's degradation (linear, as module
 * performance warranties state it); rates rise by the escalation; use stays the same.
 *
 * calculate() is the math alone (no database), so it can be checked against a spreadsheet or an
 * OpenSolar proposal; forDesign() gathers the inputs for a build sheet and says what is missing.
 */
final class Savings
{
	/**
	 * Typical monthly shares of a year's electricity use, January to December (percent), spread over
	 * the yearly use when the 12 months from the bill aren't entered. From NREL's End-Use Load
	 * Profiles for the U.S. Building Stock (typical-year TMY3 weather): the residential shapes average
	 * the monthly shares of 60 modeled single-family detached homes each (ResStock 2024 release 2) in
	 * south-central and southeast Pennsylvania counties, by heating fuel; small commercial averages the
	 * Pennsylvania small office, standalone retail and warehouse totals (ComStock 2021 release 1).
	 */
	public const PROFILES = [
		'res_gas'      => ['Residential, gas, oil or propane heat', [8.44, 7.47, 7.65, 7.14, 7.91, 9.49, 11.04, 10.34, 8.19, 7.20, 7.00, 8.14]],
		'res_electric' => ['Residential, electric heat or heat pump', [19.80, 14.90, 10.28, 5.66, 4.16, 4.36, 5.10, 4.70, 3.86, 4.79, 7.45, 14.93]],
		'commercial'   => ['Small commercial or farm', [10.18, 8.53, 8.34, 7.40, 7.77, 7.91, 8.65, 8.58, 7.51, 7.81, 8.01, 9.31]],
	];

	public const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

	/** Savings assumptions: key => [Rates page setting, label, unit, low, high]. A build sheet can change any of them. */
	public const ASSUMPTIONS = [
		'escalation'   => ['savings_escalation', 'Utility rate increase', '% a year', 0, 15],
		'srec_price'   => ['savings_srec_price', 'SREC price', '$ per MWh', 0, 500],
		'srec_decline' => ['savings_srec_decline', 'SREC price drop', '% a year', 0, 50],
		'srec_years'   => ['savings_srec_years', 'SRECs paid for', 'years', 0, 40],
		'years'        => ['savings_years', 'Analysis period', 'years', 1, 40],
		'discount'     => ['savings_discount', 'Discount rate', '% a year', 0, 25],
		'dep_fed_rate'   => ['savings_dep_fed_rate', 'Depreciation: federal tax rate', '%', 0, 60],
		'dep_state_rate' => ['savings_dep_state_rate', 'Depreciation: state tax rate', '%', 0, 20],
	];

	/** Depreciation for business customers: how the federal deduction is taken. */
	public const DEP_METHODS = ['bonus' => 'Bonus: 100% in year one', 'macrs' => '5-year MACRS'];

	/** 5-year MACRS, half-year convention: share of the basis deducted each year. */
	public const MACRS5 = [0.20, 0.32, 0.192, 0.1152, 0.1152, 0.0576];

	/**
	 * Whether the build sheet counts depreciation: its own choice, else on for commercial and farm
	 * customers (nonprofits, government and homes don't take it).
	 */
	public static function depreciationOn(array $o, array $d): bool
	{
		$own = json_decode((string) ($d['savings_inputs'] ?? ''), true) ?: [];
		if (in_array($own['dep'] ?? '', ['on', 'off'], true)) {
			return $own['dep'] === 'on';
		}
		return ($o['customer_type'] ?? '') === 'commercial' || !empty($o['is_agricultural']);
	}

	/** The federal method: the build sheet's choice, else the Rates page. */
	public static function depreciationMethod(array $d): string
	{
		$own = json_decode((string) ($d['savings_inputs'] ?? ''), true) ?: [];
		$m = (string) ($own['dep_method'] ?? '');
		return isset(self::DEP_METHODS[$m]) ? $m : (isset(self::DEP_METHODS[Presales::setting('savings_dep_method')]) ? Presales::setting('savings_dep_method') : 'bonus');
	}

	/**
	 * Depreciation tax savings by year (index 0 = year one). Federal: the price less half of the tax
	 * credits (the basis reduction for the ITC), bonus or 5-year MACRS, at the federal rate. State
	 * (Pennsylvania doesn't follow bonus depreciation): the full price over 5-year MACRS at the state
	 * rate.
	 *
	 * @return array{fed: list<float>, state: list<float>, basis_fed: float, basis_state: float}
	 */
	public static function depreciation(float $price, float $credits, string $method, float $fedRate, float $stateRate): array
	{
		$basisFed = max(0.0, $price - $credits / 2);
		$fed = array_map(static fn ($s) => $basisFed * $s * $fedRate / 100, $method === 'macrs' ? self::MACRS5 : [1.0]);
		$state = array_map(static fn ($s) => $price * $s * $stateRate / 100, self::MACRS5);
		return ['fed' => $fed, 'state' => $state, 'basis_fed' => $basisFed, 'basis_state' => $price];
	}

	/** Monthly shares of a profile as fractions that add up to 1. */
	public static function profile(string $key): array
	{
		$p = self::PROFILES[$key][1] ?? self::PROFILES['res_gas'][1];
		$sum = array_sum($p);
		return array_map(static fn ($v) => $v / $sum, $p);
	}

	/** The opportunity's profile: as picked, else by customer type (residential: non-electric heat; others: small commercial). */
	public static function profileKey(array $o): string
	{
		$k = (string) ($o['usage_profile'] ?? '');
		if (isset(self::PROFILES[$k])) {
			return $k;
		}
		return ($o['customer_type'] ?? 'residential') === 'residential' && empty($o['is_agricultural']) ? 'res_gas' : 'commercial';
	}

	/** The 12 months typed from the bill, or null. */
	public static function billMonths(array $o): ?array
	{
		$m = json_decode((string) ($o['monthly_usage'] ?? ''), true);
		if (!is_array($m) || count($m) !== 12) {
			return null;
		}
		$m = array_map(static fn ($v) => is_numeric($v) ? max(0.0, (float) $v) : null, array_values($m));
		return in_array(null, $m, true) || array_sum($m) <= 0 ? null : $m;
	}

	/**
	 * Monthly use, January to December: the bill's 12 months, else the yearly use spread by the profile.
	 *
	 * @return array{kwh: list<float>, annual: float, source: string, profile: string}|null
	 */
	public static function usage(array $o): ?array
	{
		$profile = self::profileKey($o);
		$bill = self::billMonths($o);
		if ($bill) {
			return ['kwh' => $bill, 'annual' => array_sum($bill), 'source' => 'bill', 'profile' => $profile];
		}
		$annual = (float) ($o['annual_usage_kwh'] ?? 0);
		if ($annual <= 0) {
			return null;
		}
		return ['kwh' => array_map(static fn ($s) => $annual * $s, self::profile($profile)), 'annual' => $annual, 'source' => 'profile', 'profile' => $profile];
	}

	/** The savings assumptions for a build sheet: the Rates page values with the build sheet's own changes on top. */
	public static function assumptions(array $d): array
	{
		$own = json_decode((string) ($d['savings_inputs'] ?? ''), true);
		$own = is_array($own) ? $own : [];
		$out = ['changed' => []];
		foreach (self::ASSUMPTIONS as $k => [$setting]) {
			$default = Presales::num($setting);
			if (isset($own[$k]) && is_numeric($own[$k])) {
				$out[$k] = (float) $own[$k];
				if (abs($out[$k] - $default) > 0.000001) {
					$out['changed'][] = $k;
				}
			} else {
				$out[$k] = $default;
			}
			$out['default_' . $k] = $default;
		}
		return $out;
	}

	/**
	 * A URDB rate made ready for monthly bills: each month's customer charge ($), and its energy
	 * periods with the share of the month's hours in each (use is taken as even across the hours)
	 * and the tiers ([max kWh a month or null, $ per kWh]).
	 */
	public static function rateFacts(array $rate): array
	{
		$notes = [];
		$fixed = (float) ($rate['fixedchargefirstmeter'] ?? 0);
		$units = strtolower(trim((string) ($rate['fixedchargeunits'] ?? '$/month')));
		$days = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
		$fixedMonths = [];
		foreach ($days as $m => $n) {
			$fixedMonths[$m] = match ($units) {
				'$/day' => $fixed * $n,
				'$/year' => $fixed / 12,
				default => $fixed,
			};
		}
		$structure = [];
		foreach ((array) ($rate['energyratestructure'] ?? []) as $p => $tiers) {
			$rows = [];
			foreach ((array) $tiers as $t) {
				if (!is_array($t) || !isset($t['rate'])) {
					continue;
				}
				$unit = strtolower((string) ($t['unit'] ?? 'kwh'));
				if ($unit !== 'kwh' && $unit !== '') {
					$notes['unit'] = 'Some energy tiers are not per monthly kWh (' . $t['unit'] . '); they are treated as monthly.';
				}
				$rows[] = [isset($t['max']) && is_numeric($t['max']) ? (float) $t['max'] : null, (float) $t['rate'] + (float) ($t['adj'] ?? 0)];
			}
			$structure[(int) $p] = $rows ?: [[null, 0.0]];
		}
		if (!$structure) {
			$structure = [[[null, 0.0]]];
			$notes['energy'] = 'The rate has no energy charges.';
		}
		$weekday = (array) ($rate['energyweekdayschedule'] ?? []);
		$weekend = (array) ($rate['energyweekendschedule'] ?? []);
		$months = [];
		$tou = false;
		for ($m = 0; $m < 12; $m++) {
			// Hours in each period over a typical month (2025's calendar)
			$count = [];
			for ($day = 1; $day <= $days[$m]; $day++) {
				$dow = (int) date('N', mktime(12, 0, 0, $m + 1, $day, 2025));
				$sched = $dow >= 6 ? ($weekend[$m] ?? $weekday[$m] ?? []) : ($weekday[$m] ?? []);
				for ($h = 0; $h < 24; $h++) {
					$p = (int) ($sched[$h] ?? 0);
					$p = isset($structure[$p]) ? $p : (int) array_key_first($structure);
					$count[$p] = ($count[$p] ?? 0) + 1;
				}
			}
			$total = array_sum($count);
			$tou = $tou || count($count) > 1;
			$periods = [];
			foreach ($count as $p => $hours) {
				$periods[] = ['share' => $hours / $total, 'tiers' => $structure[$p]];
			}
			$months[$m] = $periods;
		}
		if ($tou) {   // periods that change by the hour; a seasonal rate switches whole months
			$notes['tou'] = 'Time-of-use rate: use and credits are spread evenly over the hours, so the bills are an approximation.';
		}
		if (!empty($rate['demandratestructure']) || !empty($rate['flatdemandstructure'])) {
			$notes['demand'] = 'Demand charges are not included: they depend on the peak kW, which solar does not reliably lower.';
		}
		return ['fixed' => $fixedMonths, 'months' => $months, 'notes' => array_values($notes)];
	}

	/** Energy charges for a month's kWh under a rateFacts() month. */
	public static function energyCost(array $periods, float $kwh): float
	{
		$cost = 0.0;
		foreach ($periods as $p) {
			$part = $kwh * $p['share'];
			$prev = 0.0;
			$last = 0.0;
			foreach ($p['tiers'] as [$max, $price]) {
				$cap = $max ?? INF;
				$cost += max(0.0, min($part, $cap) - $prev) * $price;
				$last = $price;
				$prev = $cap;
				if ($part <= $cap) {
					break;
				}
			}
			// Use past the last tier's limit pays the last tier's price
			if (is_finite($prev) && $part > $prev) {
				$cost += ($part - $prev) * $last;
			}
		}
		return $cost;
	}

	/**
	 * The savings math.
	 *
	 * @param array $in production (12 kWh, Jan to Dec, year one), usage (12 kWh), rate (rateFacts()),
	 *                  ptc ($ per kWh for the true-up credit), degradation (% of year one lost each
	 *                  year), price, incentives (list of [label, amount, timing upfront|year1]),
	 *                  escalation, srec_price ($/MWh), srec_decline, srec_years, years, discount,
	 *                  trueup_month (1 to 12, default 5 = May; the year starts the month after)
	 */
	public static function calculate(array $in): array
	{
		$years = max(1, (int) $in['years']);
		$esc = (float) $in['escalation'] / 100;
		$disc = (float) $in['discount'] / 100;
		$deg = (float) $in['degradation'] / 100;
		$trueup = (int) ($in['trueup_month'] ?? 5) - 1;
		$order = [];
		for ($i = 1; $i <= 12; $i++) {
			$order[] = ($trueup + $i) % 12;
		}
		$rate = $in['rate'];
		$prod1 = array_map('floatval', $in['production']);
		$use = array_map('floatval', $in['usage']);
		$ptc = max(0.0, (float) $in['ptc']);

		$upfront = 0.0;
		$year1 = 0.0;
		$incentives = [];
		foreach ($in['incentives'] ?? [] as $x) {
			$amt = round((float) $x['amount'], 2);
			if ($amt <= 0) {
				continue;
			}
			$incentives[] = ['label' => (string) $x['label'], 'amount' => $amt, 'timing' => $x['timing'] === 'year1' ? 'year1' : 'upfront'];
			$x['timing'] === 'year1' ? $year1 += $amt : $upfront += $amt;
		}
		$price = (float) $in['price'];
		$dep = $in['depreciation'] ?? null;

		$rows = [];
		$months = [];
		$cum = -($price - $upfront);
		$dcum = $cum;
		$npv = $cum;
		$payback = null;
		$dpayback = null;
		$flows = [$cum];
		for ($y = 1; $y <= $years; $y++) {
			$f = max(0.0, 1 - $deg * ($y - 1));
			$mult = pow(1 + $esc, $y - 1);
			$bank = 0.0;
			$billWo = $billW = $payout = $solarY = 0.0;
			foreach ($order as $m) {
				$solar = $prod1[$m] * $f;
				$fixed = $rate['fixed'][$m] * $mult;
				$wo = $fixed + self::energyCost($rate['months'][$m], $use[$m]) * $mult;
				$net = $use[$m] - $solar;
				if ($net >= 0) {
					$draw = min($bank, $net);
					$bank -= $draw;
					$billed = $net - $draw;
				} else {
					$bank += -$net;
					$billed = 0.0;
				}
				$w = $fixed + self::energyCost($rate['months'][$m], $billed) * $mult;
				$pay = 0.0;
				if ($m === $trueup) {
					$pay = $bank * $ptc * $mult;
					$bank = 0.0;
				}
				$billWo += $wo;
				$billW += $w;
				$payout += $pay;
				$solarY += $solar;
				if ($y === 1) {
					$months[] = ['month' => $m, 'label' => self::MONTHS[$m], 'usage' => $use[$m], 'solar' => $solar, 'bill_without' => $wo,
						'bill_with' => $w, 'payout' => $pay, 'savings' => $wo - $w + $pay, 'banked' => $bank, 'billed_kwh' => $billed];
				}
			}
			$srecPrice = $y <= (int) $in['srec_years'] ? max(0.0, (float) $in['srec_price'] * pow(1 - (float) $in['srec_decline'] / 100, $y - 1)) : 0.0;
			$srec = $solarY / 1000 * $srecPrice;
			$billSavings = $billWo - $billW + $payout;
			$savings = $billSavings + $srec;
			$depFed = (float) ($dep['fed'][$y - 1] ?? 0);
			$depState = (float) ($dep['state'][$y - 1] ?? 0);
			$flow = $savings + ($y === 1 ? $year1 : 0.0) + $depFed + $depState;
			$before = $cum;
			$cum += $flow;
			$dflow = $flow / pow(1 + $disc, $y);
			$dbefore = $dcum;
			$dcum += $dflow;
			$npv += $dflow;
			if ($payback === null && $cum >= 0 && $flow > 0) {
				$payback = $y - 1 + (-$before) / $flow;
			}
			if ($dpayback === null && $dcum >= 0 && $dflow > 0) {
				$dpayback = $y - 1 + (-$dbefore) / $dflow;
			}
			$flows[] = $flow;
			$rows[] = ['year' => $y, 'usage' => array_sum($use), 'solar' => $solarY, 'bill_without' => $billWo, 'bill_with' => $billW,
				'payout' => $payout, 'bill_savings' => $billSavings, 'srec' => $srec, 'srec_price' => $srecPrice, 'savings' => $savings,
				'incentives' => $y === 1 ? $year1 : 0.0, 'dep_fed' => $depFed, 'dep_state' => $depState, 'cash_flow' => $flow, 'cumulative' => $cum, 'discounted_cumulative' => $dcum];
		}
		if ($payback !== null && $payback < 0) {
			$payback = 0.0;
		}
		if ($dpayback !== null && $dpayback < 0) {
			$dpayback = 0.0;
		}
		$netCost = $price - $upfront - $year1;
		$outlay = $price - $upfront;
		$lifetime = array_sum(array_column($rows, 'savings'));
		$depTotal = array_sum(array_column($rows, 'dep_fed')) + array_sum(array_column($rows, 'dep_state'));
		$solarLife = array_sum(array_column($rows, 'solar'));
		return [
			'price' => $price,
			'incentives' => $incentives,
			'incentives_upfront' => $upfront,
			'incentives_year1' => $year1,
			'net_cost' => $netCost,
			'offset_pct' => array_sum($use) > 0 ? array_sum($prod1) / array_sum($use) * 100 : null,
			'first_year' => $rows[0],
			'months' => $months,
			'years' => $rows,
			'lifetime_savings' => $lifetime,
			'lifetime_bill_savings' => array_sum(array_column($rows, 'bill_savings')),
			'lifetime_srec' => array_sum(array_column($rows, 'srec')),
			'net_savings' => $lifetime + $depTotal - $netCost,
			'lifetime_dep_fed' => array_sum(array_column($rows, 'dep_fed')),
			'lifetime_dep_state' => array_sum(array_column($rows, 'dep_state')),
			'depreciation' => $dep !== null,
			'payback_years' => $payback,
			'discounted_payback_years' => $dpayback,
			'npv' => $npv,
			'irr' => self::irr($flows),
			// Total return: what the system leaves after paying for itself, as a share of the money put in
			'roi_pct' => $outlay > 0 ? $cum / $outlay * 100 : null,
			'cost_per_kwh' => $solarLife > 0 ? $netCost / $solarLife : null,
			'lifetime_solar_kwh' => $solarLife,
		];
	}

	/** Internal rate of return of yearly cash flows (year 0 first), or null when it has no answer. */
	public static function irr(array $flows): ?float
	{
		$npv = static function (float $r) use ($flows): float {
			$v = 0.0;
			foreach ($flows as $t => $c) {
				$v += $c / pow(1 + $r, $t);
			}
			return $v;
		};
		$lo = -0.9;
		$hi = 1.0;
		if ($npv($lo) * $npv($hi) > 0) {
			return null;
		}
		for ($i = 0; $i < 200; $i++) {
			$mid = ($lo + $hi) / 2;
			if ($npv($lo) * $npv($mid) <= 0) {
				$hi = $mid;
			} else {
				$lo = $mid;
			}
		}
		return ($lo + $hi) / 2 * 100;
	}

	/** A loan's monthly payment and total interest (an estimate for the proposal). */
	public static function loan(float $principal, float $aprPct, int $years): ?array
	{
		if ($principal <= 0 || $years <= 0) {
			return null;
		}
		$n = $years * 12;
		$r = $aprPct / 100 / 12;
		$pay = $r > 0 ? $principal * $r / (1 - pow(1 + $r, -$n)) : $principal / $n;
		return ['principal' => $principal, 'payment' => $pay, 'interest' => $pay * $n - $principal, 'months' => $n];
	}

	/** Years as "8 years, 4 months". */
	public static function yearsText(?float $y): string
	{
		if ($y === null) {
			return 'Not within the analysis period';
		}
		$whole = (int) floor($y);
		$months = (int) round(($y - $whole) * 12);
		if ($months === 12) {
			$whole++;
			$months = 0;
		}
		return $whole . ' year' . ($whole === 1 ? '' : 's') . ($months ? ', ' . $months . ' month' . ($months === 1 ? '' : 's') : '');
	}

	/** The build sheet's incentives as saved: [label, kind amount|pct, value, timing]. */
	public static function incentiveRows(array $d): array
	{
		$rows = json_decode((string) ($d['incentives'] ?? ''), true);
		return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
	}

	/** The Price to Compare for the true-up, $ per kWh, and where it came from. */
	public static function priceToCompare(array $o): array
	{
		$trend = json_decode((string) ($o['rate_trend'] ?? ''), true);
		if (is_array($trend) && isset($trend['generation']) && is_numeric($trend['generation']) && $trend['generation'] > 0) {
			return ['value' => (float) $trend['generation'] / 100, 'source' => 'the generation part of the picked rate'];
		}
		if (!empty($o['utility_id'])) {
			$u = Db::one('SELECT name, price_to_compare FROM utilities WHERE id = ?', [(int) $o['utility_id']]);
			if ($u && $u['price_to_compare'] !== null) {
				return ['value' => (float) $u['price_to_compare'] / 100, 'source' => $u['name'] . '\'s Price to Compare (Rates page)'];
			}
		}
		return ['value' => null, 'source' => null];
	}

	/** Module degradation for the build sheet: its module line, else the catalog item, else the Rates page default (% a year). */
	public static function degradation(array $lines): array
	{
		foreach ($lines as $l) {
			if (($l['category'] ?? '') !== 'modules') {
				continue;
			}
			$v = $l['degradation_annual_pct'] ?? null;
			if ($v === null && !empty($l['catalog_item_id'])) {
				$v = Db::value('SELECT degradation_annual_pct FROM catalog_items WHERE id = ?', [(int) $l['catalog_item_id']]);
			}
			if ($v !== null && $v !== '') {
				return ['value' => (float) $v, 'source' => $l['name']];
			}
			break;
		}
		return ['value' => Presales::num('degradation_default'), 'source' => 'Rates page default'];
	}

	/**
	 * Everything the savings need for a build sheet, what is missing, and the result when nothing is.
	 *
	 * @return array{missing: list<string>, notes: list<string>, result: ?array, assumptions: array, usage: ?array, model: string}
	 */
	public static function forDesign(array $o, array $d, array $lines, array $calc): array
	{
		$missing = [];
		$notes = [];
		$prod = Production::saved($d);
		$model = self::model($d);
		if (!$prod) {
			$missing[] = 'Run the production estimate.';
		}
		$usage = self::usage($o);
		if (!$usage) {
			$missing[] = 'The yearly use on the opportunity (or the 12 months from the bill).';
		}
		$rate = json_decode((string) ($o['rate_json'] ?? ''), true);
		if (!is_array($rate) || !$rate) {
			$missing[] = 'The utility rate (OpenEI) on the opportunity.';
		}
		$a = self::assumptions($d);
		if ($missing) {
			return ['missing' => $missing, 'notes' => $notes, 'result' => null, 'assumptions' => $a, 'usage' => $usage, 'model' => $model];
		}
		$facts = self::rateFacts($rate);
		$notes = $facts['notes'];
		$ptc = self::priceToCompare($o);
		$deg = self::degradation($lines);
		$price = (float) $calc['price'];
		$inc = [];
		foreach (self::incentiveRows($d) as $x) {
			$v = (float) ($x['value'] ?? 0);
			$inc[] = ['label' => (string) ($x['label'] ?? 'Incentive'), 'amount' => ($x['kind'] ?? 'amount') === 'pct' ? $price * $v / 100 : $v,
				'timing' => ($x['timing'] ?? 'upfront') === 'year1' ? 'year1' : 'upfront', 'pct' => ($x['kind'] ?? '') === 'pct' ? $v : null];
		}
		$depInfo = null;
		if (self::depreciationOn($o, $d)) {
			$credits = array_sum(array_map(static fn ($x) => $x['timing'] === 'year1' ? $x['amount'] : 0, $inc));
			$depInfo = self::depreciation($price, $credits, self::depreciationMethod($d), $a['dep_fed_rate'], $a['dep_state_rate'])
				+ ['method' => self::depreciationMethod($d)];
		}
		$r = self::calculate([
			'depreciation' => $depInfo,
			'production' => $prod[$model]['monthly_kwh'],
			'usage' => $usage['kwh'],
			'rate' => $facts,
			'ptc' => $ptc['value'] ?? 0,
			'degradation' => $deg['value'],
			'price' => $price,
			'incentives' => $inc,
		] + $a);
		$r['ptc'] = $ptc;
		$r['degradation'] = $deg;
		$r['incentive_rows'] = $inc;
		$r['dep'] = $depInfo;
		$r['production_annual'] = (float) $prod[$model]['annual_kwh'];
		return ['missing' => [], 'notes' => $notes, 'result' => $r, 'assumptions' => $a, 'usage' => $usage, 'model' => $model];
	}

	/** The production estimate the build sheet leads with: its own choice, else the Rates page default. */
	public static function model(array $d): string
	{
		$m = (string) ($d['production_model'] ?? '');
		if (!isset(Production::MODELS[$m])) {
			$m = Presales::setting('production_model');
		}
		return isset(Production::MODELS[$m]) ? $m : 'detailed';
	}
}
