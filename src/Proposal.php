<?php
declare(strict_types=1);

namespace App;

/**
 * A build sheet's customer proposal (version 1): everything the proposal page and its PDF show,
 * gathered from the build sheet, its opportunity, the production estimate, the savings
 * (App\Savings), the terms (App\ProposalTerms) and the Rates page. Internal cost, markup, margin
 * and commission never reach it.
 */
final class Proposal
{
	/** Equipment categories listed by brand, model and quantity; the rest is balance of system. */
	public const EQUIPMENT = ['modules' => 'Solar panels', 'inverters' => 'Inverters', 'mlpe' => 'Optimizers and microinverters',
		'batteries' => 'Batteries', 'monitoring' => 'Monitoring', 'racking' => 'Racking'];

	/**
	 * Monitoring internet as the customer reads it (keys of Projects::INVERTER_INTERNET):
	 * [what it is, who provides it].
	 */
	public const INTERNET = [
		'wifi'     => ['Customer provided Wi-Fi', 'Provided by the customer, reaching the inverter location'],
		'ethernet' => ['Customer provided Ethernet', 'Provided by the customer, wired to the inverter location'],
		'cell'     => ['Cellular connection', 'Included in this proposal'],
		'hotspot'  => ['Mobile hotspot', 'At the inverter location'],
		'none'     => ['No internet connection', 'The system will not report to online monitoring'],
	];

	/** Steps when the Rates page has none (one per line, "Title: what happens"). */
	public const NEXT_STEPS = "Confirm your interest: let your sales representative know you'd like to move forward.\n"
		. "Site visit: we visit to measure, take project photos and confirm the design.\n"
		. "Sign the contract: the final version of this proposal is sent to you for electronic signature.\n"
		. "Deposit: we send an invoice for the deposit, which starts your project.\n"
		. "Design and approvals: engineering plan sets, then the permit and the utility's interconnection approval.\n"
		. "Installation: we schedule and install your system.\n"
		. "Inspection and permission to operate: the inspection passes and the utility lets the system turn on.\n"
		. "Closeout: we set you up with an SREC broker so your system starts earning, and take care of the paperwork for any extended or third-party warranties, utility rebates and virtual net metering.";

	/** The build sheet's proposal number (stored as quote_number), given (year and the next 4-digit number) the first time it is needed. */
	public static function quoteNumber(array $d): string
	{
		if (!empty($d['quote_number'])) {
			return (string) $d['quote_number'];
		}
		$year = date('Y');
		for ($try = 0; $try < 5; $try++) {
			$last = (int) Db::value("SELECT MAX(CAST(SUBSTR(quote_number, 5) AS INTEGER)) FROM presales_designs WHERE quote_number LIKE ?", [$year . '%']);
			$n = $year . str_pad((string) max(1001, $last + 1), 4, '0', STR_PAD_LEFT);
			try {
				Db::run('UPDATE presales_designs SET quote_number = ? WHERE id = ? AND quote_number IS NULL', [$n, (int) $d['id']]);
				return (string) Db::value('SELECT quote_number FROM presales_designs WHERE id = ?', [(int) $d['id']]);
			} catch (\PDOException) {
				continue;   // another build sheet took the number at the same moment
			}
		}
		throw new \RuntimeException('Could not give the proposal a number.');
	}

	/** Proposal date and valid-until date (Y-m-d) as they will print. */
	public static function dates(array $d): array
	{
		$date = $d['proposal_date'] ?: date('Y-m-d');
		$until = $d['valid_until'] ?: date('Y-m-d', strtotime($date . ' +' . (int) Presales::num('proposal_valid_days') . ' days'));
		return [$date, $until];
	}

	/** A homeowner (not commercial or agricultural): no net present value or discounted payback on the proposal. */
	public static function residential(array $o): bool
	{
		return ($o['customer_type'] ?? 'residential') === 'residential' && empty($o['is_agricultural']);
	}

	/** Everything the proposal shows, plus warnings for the salesperson (not printed). */
	public static function data(array $o, array $d, bool $withDrawing = true): array
	{
		$lines = Designs::lines((int) $d['id']);
		$arrays = Designs::arrays((int) $d['id']);
		$calc = Designs::calc($d, $lines, $arrays);
		$prod = Production::saved($d);
		$prodIn = Production::inputs($o, $lines, $arrays, $calc);
		$savings = Savings::forDesign($o, $d, $lines, $calc);
		$model = $savings['model'];
		$other = $model === 'detailed' ? 'pvwatts' : 'detailed';
		$warnings = [];
		if (!$prod) {
			$warnings[] = 'The production estimate hasn\'t run, so production and savings are missing.';
		} elseif ($prodIn['key'] !== $d['production_key']) {
			$warnings[] = 'The build sheet changed since the production estimate ran. Run it again before sending.';
		}
		foreach ($savings['missing'] as $m) {
			$warnings[] = 'Savings need: ' . $m;
		}
		if ($savings['result'] && $savings['result']['ptc']['value'] === null) {
			$warnings[] = 'No Price to Compare (on the opportunity, from the picked OpenEI rate, or the Rates page), so credit left at the May 31 true-up counts as $0.';
		}
		foreach ($calc['checks'] ?? [] as $m) {
			$warnings[] = 'Build sheet: ' . $m;
		}

		$customer = $o['customer_id'] ? Db::one('SELECT * FROM organizations WHERE id = ?', [(int) $o['customer_id']]) : null;
		$contact = $d['contact_id'] ? Db::one('SELECT * FROM contacts WHERE id = ?', [(int) $d['contact_id']]) : null;
		if (!$contact && $customer) {
			$contacts = Db::all("SELECT * FROM contacts WHERE owner_type = 'organization' AND owner_id = ? AND is_active = 1 ORDER BY is_primary DESC, name", [(int) $customer['id']]);
			$contact = count($contacts) === 1 || ($contacts && $contacts[0]['is_primary']) ? $contacts[0] : null;
		}
		$sales = $o['salesperson_id'] ? Db::one('SELECT id, name, email, phone FROM users WHERE id = ?', [(int) $o['salesperson_id']]) : null;
		if (!$sales) {
			$warnings[] = 'The opportunity has no salesperson.';
		}
		$residential = self::residential($o);
		$customerName = (string) ($customer['name'] ?? $o['name']);
		// A person: the customer is the person. A business: the business, to the attention of the contact.
		$attn = $contact && strcasecmp(trim($contact['name']), trim($customerName)) !== 0 ? $contact['name'] : null;
		$first = trim((string) strtok((string) ($attn ?? $customerName), ' '));
		$greeting = $attn ? $first : ($residential ? $first : $customerName);

		[$date, $until] = self::dates($d);
		$quote = self::quoteNumber($d);

		// Equipment, by brand and model, with quantities
		$equipment = [];
		$warranties = [];
		foreach ($calc['lines'] as $l) {
			$cat = $l['category'];
			if (!isset(self::EQUIPMENT[$cat]) || ($l['qty_resolved'] ?? 0) <= 0) {
				continue;
			}
			$qty = (float) $l['qty_resolved'];
			$equipment[] = ['category' => self::EQUIPMENT[$cat], 'name' => $l['name'],
				'qty' => in_array($cat, ['racking', 'monitoring'], true) && $l['qty_rule'] === 'dc_watts' ? null : (int) round($qty),
				'watts' => $cat === 'modules' ? (float) $l['watts'] : null];
			$text = trim((string) ($l['warranty_text'] ?? ''));
			if ($text !== '' || in_array($cat, ['modules', 'inverters', 'batteries'], true)) {
				$warranties[] = ['label' => self::EQUIPMENT[$cat] . ': ' . $l['name'], 'text' => ($text !== '' ? $text : 'Manufacturer\'s standard warranty')
					. (!empty($l['ext_warranty']) && !empty($l['ext_warranty_years']) ? '; extended to ' . (int) $l['ext_warranty_years'] . ' years' : '')];
			}
		}
		$trifectaText = trim(Presales::setting('trifecta_warranty_text')) ?: 'Trifecta Solar labor and workmanship: 10 years.';
		$warranties[] = ['label' => 'Trifecta Solar', 'text' => $trifectaText];
		if (!empty($d['warranty_is_solarinsure'])) {
			$warranties[] = ['label' => 'SolarInsure', 'text' => trim(Presales::setting('solarinsure_text')) ?: 'SolarInsure 30-year parts and labor warranty included.'];
		}

		// Production guarantee, on the PVWatts estimate
		$guarantee = null;
		$deg = $savings['result']['degradation']['value'] ?? Presales::num('degradation_default');
		if (!empty($d['guarantee_on'])) {
			if ($prod) {
				$years = max(1, (int) Presales::num('guarantee_years'));
				$annual = (float) $prod['pvwatts']['annual_kwh'];
				$est = 0.0;
				for ($y = 1; $y <= $years; $y++) {
					$est += $annual * max(0.0, 1 - $deg / 100 * ($y - 1));
				}
				$pct = Presales::num('guarantee_pct');
				$guarantee = ['years' => $years, 'pct' => $pct, 'rate' => Presales::num('guarantee_rate'), 'annual' => $annual,
					'estimate' => $est, 'kwh' => $est * $pct / 100];
			} else {
				$warnings[] = 'The production guarantee needs the production estimate.';
			}
		}

		$deposit = null;
		foreach ($calc['payments'] as $p) {
			$deposit ??= (float) $p['amount'];
		}
		$internet = self::INTERNET[(string) ($d['inverter_internet'] ?? '')] ?? null;
		if (!$internet) {
			$warnings[] = 'Internet for monitoring isn\'t chosen on the build sheet (Setup), so the proposal doesn\'t say who provides it.';
		}

		$fmt = static fn (float $v): string => number_format($v);
		$vars = [
			'internet' => $internet ? $internet[0] : '',
			'company' => Presales::setting('company_legal_name') ?: 'Trifecta Solar LLC',
			'hic' => Presales::setting('company_hic'),
			'insurance' => Presales::setting('insurance_text'),
			'customer' => $customerName,
			'proposal_number' => $quote,
			'quote_number' => $quote,
			'deposit' => $deposit !== null ? '$' . number_format($deposit, 2) : '',
			'price' => '$' . number_format((float) $calc['price'], 2),
			'guarantee_pct' => $guarantee ? rtrim(rtrim(number_format($guarantee['pct'], 2), '0'), '.') : '',
			'guarantee_years' => $guarantee ? (string) $guarantee['years'] : '',
			'guarantee_rate' => $guarantee ? '$' . number_format($guarantee['rate'], 2) : '',
			'guarantee_kwh' => $guarantee ? $fmt(round($guarantee['kwh'])) : '',
			'guarantee_estimate_kwh' => $guarantee ? $fmt(round($guarantee['estimate'])) : '',
			'pvwatts_annual' => $prod ? $fmt((float) $prod['pvwatts']['annual_kwh']) : '',
			'degradation' => rtrim(rtrim(number_format((float) $deg, 2), '0'), '.'),
		];
		$terms = ProposalTerms::forProposal(ProposalTerms::facts($o, $d, $calc), $vars);
		if (!$terms['version']) {
			$warnings[] = 'No proposal terms yet (Sales > Proposal terms, or bin/console import:terms).';
		}
		if ($terms['unknown']) {
			$warnings[] = 'The terms use placeholders this proposal can\'t fill: {' . implode('}, {', $terms['unknown']) . '}.';
		}
		if (Presales::setting('company_hic') === '') {
			$warnings[] = 'The PA HIC registration isn\'t set (Rates page, Proposal).';
		}
		$ins = Presales::setting('insurance_expires');
		if ($ins !== '' && $ins < date('Y-m-d')) {
			$warnings[] = 'The insurance policies on the Rates page ended ' . date('m/d/Y', (int) strtotime($ins)) . '. Update the insurance sentence and date.';
		}
		if ($until < date('Y-m-d')) {
			$warnings[] = 'This proposal\'s valid-until date has passed.';
		}

		// The site layout drawing: satellite tiles fetched now (never stored), panels on top
		$layout = Layout::decode($d['layout_json'] ?? null);
		$drawing = null;
		if (!$layout || !Layout::panels($layout)) {
			$warnings[] = 'No site layout drawn yet, so the System Summary page shows an empty box.';
		} elseif ($withDrawing) {
			$drawing = Layout::drawing($layout, Layout::PROPOSAL_ASPECT, static function (int $z, int $x, int $y): ?array {
				$t = GoogleMaps::tile($z, $x, $y);
				return $t['ok'] ? [$t['bytes'], $t['type']] : null;
			});
			if ($drawing && GoogleMaps::configured()) {
				[$bx, $by, $bw, $bh] = $drawing['box'];
				[$n, $w] = Layout::latLon($layout['origin'], $bx, $by);
				[$s, $e] = Layout::latLon($layout['origin'], $bx + $bw, $by + $bh);
				$vp = GoogleMaps::tileViewport($drawing['zoom'], $n, $s, $e, $w);
				$drawing['attribution'] = (string) ($vp['copyright'] ?? '');   // the Google Maps logo is on the drawing
			}
			$drawing['trackers'] = (bool) Layout::sweeps($layout);
		}
		if ($layout && ($out = Layout::panelsOutsideView($layout))) {
			$warnings[] = $out . ' ' . ($out === 1 ? 'panel is' : 'panels are') . ' outside the saved proposal view, so the System Summary aerial cuts '
				. ($out === 1 ? 'it' : 'them') . ' off. Set the proposal view again on the site layout page.';
		}
		if ($layout && ($out = Layout::measuresOutsideView($layout))) {
			$warnings[] = $out . ' ' . ($out === 1 ? 'measurement' : 'measurements') . ' marked Show on proposal ' . ($out === 1 ? 'runs' : 'run')
				. ' outside the saved proposal view, so the System Summary aerial cuts ' . ($out === 1 ? 'it' : 'them') . ' off. Set the proposal view again on the site layout page.';
		}
		if ($layout && Layout::panels($layout) && Layout::panels($layout) !== (int) round((float) $calc['panels'])) {
			$warnings[] = 'The site layout has ' . Layout::panels($layout) . ' panels but the build sheet has ' . (int) round((float) $calc['panels']) . ' modules.';
		}

		$loan = null;
		if ($d['finance_rate'] !== null && $d['finance_years']) {
			$down = (float) ($d['finance_down'] ?? 0);
			$loan = Savings::loan(max(0.0, (float) $calc['price'] - $down), (float) $d['finance_rate'], (int) $d['finance_years']);
			if ($loan) {
				$loan += ['down' => $down, 'rate' => (float) $d['finance_rate'], 'years' => (int) $d['finance_years']];
			}
		}

		$steps = [];
		foreach (preg_split('/\R/', trim(Presales::setting('next_steps')) ?: self::NEXT_STEPS) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			[$title, $text] = str_contains($line, ':') ? array_map('trim', explode(':', $line, 2)) : [$line, ''];
			$steps[] = ['title' => $title, 'text' => ucfirst($text)];
		}

		return [
			'o' => $o, 'd' => $d, 'calc' => $calc, 'lines' => $lines, 'arrays' => $arrays,
			'prod' => $prod, 'model' => $model, 'other' => $other, 'savings' => $savings,
			'arrayStats' => Layout::arrayStats($layout, $arrays, $lines),
			'arrayKwh' => $prod ? Production::arrayKwh($prod, $arrays, $model) : null,
			'customer' => $customer, 'customerName' => $customerName, 'attn' => $attn, 'greeting' => $greeting, 'contact' => $contact,
			'sales' => $sales, 'residential' => $residential,
			'quote' => $quote, 'date' => $date, 'until' => $until,
			'equipment' => $equipment, 'warranties' => $warranties, 'guarantee' => $guarantee, 'internet' => $internet,
			'terms' => $terms, 'loan' => $loan, 'steps' => $steps, 'drawing' => $drawing,
			'company' => [
				'name' => 'Trifecta Solar',
				'legal' => $vars['company'],
				'address' => Presales::setting('company_address'),
				'phone' => Presales::setting('company_phone'),
				'email' => Presales::setting('company_email'),
				'website' => Presales::setting('company_website'),
				'hic' => Presales::setting('company_hic'),
			],
			'cover' => array_values(array_filter(array_map('trim', preg_split("/\n\s*\n/", trim(Presales::setting('cover_letter')) ?: 'Thank you for the opportunity to present your Solar Energy System Proposal.')))),
			'warnings' => $warnings,
		];
	}
}
