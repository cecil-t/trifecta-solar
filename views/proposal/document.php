<?php
use App\Production;
use App\ProposalCharts as Chart;
use App\Savings;

/**
 * The customer proposal for one build sheet (App\Proposal::data), as HTML that WeasyPrint prints to
 * PDF (paged: running header and footer, page numbers). The salesperson sees it through
 * views/proposal/preview.php.
 *
 * @var string     $assets  '' (paths relative to public/)
 * Plus everything from App\Proposal::data().
 */
$m2 = static fn (?float $v): string => $v === null ? '' : ($v < 0 ? '-$' : '$') . number_format(abs($v), 2);
$m0 = static fn (?float $v): string => $v === null ? '' : ($v < 0 ? '-$' : '$') . number_format(abs($v));
$n0 = static fn (?float $v): string => $v === null ? '' : number_format($v);
$p1 = static fn (?float $v, int $dec = 1): string => $v === null ? '' : rtrim(rtrim(number_format($v, $dec), '0'), '.') . '%';
$longDate = static fn (string $ymd): string => date('F j, Y', (int) strtotime($ymd));
$r = $savings['result'];
$a = $savings['assumptions'];
$usage = $savings['usage'];
$kwDc = (float) $calc['dc_watts'] / 1000;
$annual = $prod ? (float) $prod[$model]['annual_kwh'] : null;
$otherAnnual = $prod ? (float) $prod[$other]['annual_kwh'] : null;
$price = (float) $calc['price'];
$hasInc = $r && $r['incentives'];
$netCost = $r ? $r['net_cost'] : $price;
$site = trim(implode(', ', array_filter([$o['site_street'], $o['site_city']])) . ' ' . trim(($o['site_state'] ?? '') . ' ' . ($o['site_zip'] ?? '')));
$salesLine = $sales ? implode(' · ', array_filter([$sales['name'], $sales['phone'] ?? '', $sales['email'] ?? ''])) : '';
$termsVersion = $terms['version']['id'] ?? null;
$rate = json_decode((string) ($o['rate_json'] ?? ''), true);
$facts = is_array($rate) && $rate ? Savings::rateFacts($rate) : null;
$rateSum = is_array($rate) && $rate ? App\UtilityRates::summary($rate) : null;
$trend = json_decode((string) ($o['rate_trend'] ?? ''), true);
$utility = $o['utility_id'] ? App\Db::value('SELECT name FROM utilities WHERE id = ?', [(int) $o['utility_id']]) : null;
$star = $hasInc ? '<sup>*</sup>' : '';
$incNote = $hasInc ? '<p class="footnote"><sup>*</sup> Includes the incentives listed under Price and Payments.</p>' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>Proposal <?= e($quote) ?> - <?= e($customerName) ?></title>
	<link rel="stylesheet" href="assets/css/proposal.css">
</head>
<body class="proposal">

<div class="run-head"><img src="<?= e($assets) ?>assets/img/logo-horizontal.svg" alt="Trifecta Solar"><span>Proposal for <?= e($customerName) ?> - <?= e($d['name']) ?></span></div>
<div class="run-foot"><?= e($salesLine) ?><?= $salesLine ? ' &nbsp;|&nbsp; ' : '' ?>Proposal <?= e($quote) ?><?= $termsVersion ? ' &nbsp;|&nbsp; Terms v' . (int) $termsVersion : '' ?></div>

<!-- 1. Cover -->
<section class="page cover">
	<div class="cover-logo"><img src="<?= e($assets) ?>assets/img/logo-stacked.svg" alt="Trifecta Solar"></div>
	<h1 class="cover-title">Solar Energy System Proposal</h1>
	<div class="cover-grid">
		<div>
			<div class="label">Prepared for</div>
			<div class="cover-name"><?= e($customerName) ?></div>
			<?php if ($attn): ?><div>Attn: <?= e($attn) ?></div><?php endif; ?>
			<?php if ($site !== ''): ?><div><?= e($site) ?></div><?php endif; ?>
		</div>
		<div>
			<table class="kv">
				<tr><th>Proposal #</th><td><strong><?= e($quote) ?></strong></td></tr>
				<tr><th>System</th><td><?= e($d['name']) ?></td></tr>
				<tr><th>Date</th><td><?= e($longDate($date)) ?></td></tr>
				<tr><th>Valid until</th><td><?= e($longDate($until)) ?></td></tr>
				<?php if (!empty($d['tax_exempt'])): ?><tr><th>Sales tax</th><td>Tax exempt</td></tr><?php endif; ?>
			</table>
		</div>
	</div>
	<div class="letter">
		<p>Dear <?= e($greeting) ?>,</p>
		<?php foreach ($cover as $para): ?><p><?= nl2br(e($para), false) ?></p><?php endforeach; ?>
		<p>Best regards,<br><?= e($sales['name'] ?? 'Trifecta Solar') ?><br>Trifecta Solar</p>
	</div>
	<?php
	// Contents, with page numbers WeasyPrint fills in (target-counter in proposal.css)
	$toc = [['p-summary', 'System Summary'], ['p-performance', 'System Performance'], ['p-savings', 'Electric Bill Savings']];
	if ($r) {
		$toc[] = ['p-roi', 'Return on Investment'];
		$toc[] = ['p-projection', (int) $a['years'] . '-Year Projection'];
	}
	array_push($toc, ['p-price', 'Price and Payments'], ['p-next', 'Next Steps'], ['p-clarifications', 'Clarifications'], ['p-terms', 'Terms and Signatures']);
	?>
	<nav class="toc" aria-label="Contents">
		<div class="label">Contents</div>
		<ol><?php foreach ($toc as [$id, $label]): ?><li><a href="#<?= $id ?>"><?= e($label) ?></a></li><?php endforeach; ?></ol>
	</nav>
	<div class="cover-foot">
		<?php if ($sales): ?>
			<div class="card-person">
				<div class="label">Your sales representative</div>
				<strong><?= e($sales['name']) ?></strong>
				<?php if (!empty($sales['phone'])): ?><div><?= e($sales['phone']) ?></div><?php endif; ?>
				<?php if (!empty($sales['email'])): ?><div><?= e($sales['email']) ?></div><?php endif; ?>
			</div>
		<?php endif; ?>
		<div class="company">
			<strong><?= e($company['legal']) ?></strong>
			<?php if ($company['address'] !== ''): ?><div><?= nl2br(e($company['address']), false) ?></div><?php endif; ?>
			<div><?= e(implode(' · ', array_filter([$company['phone'], $company['email'], $company['website']]))) ?></div>
			<?php if ($company['hic'] !== ''): ?><div><?= e(preg_match('/^\d/', $company['hic']) ? 'PA HIC #' . $company['hic'] : 'HIC ' . $company['hic']) ?></div><?php endif; ?>
		</div>
	</div>
</section>

<!-- 2. System summary -->
<section class="page">
	<h1 id="p-summary"><?= e($d['name']) ?></h1>
	<div class="tiles">
		<div class="tile tile-orange"><span class="t-num"><?= $r && $r['offset_pct'] !== null ? e($p1($r['offset_pct'], 0)) : '-' ?></span><span class="t-label">Energy from solar</span><span class="t-sub">of your yearly use, year one</span></div>
		<div class="tile"><span class="t-num"><?= e($m0($price)) ?></span><span class="t-label">Total system price</span><span class="t-sub"><?= e(number_format($kwDc, 2)) ?> kW DC<?= !empty($d['tax_exempt']) ? ', tax exempt' : '' ?></span></div>
		<div class="tile tile-green"><span class="t-num"><?= e($m0($netCost)) ?><?= $star ?></span><span class="t-label">Net cost</span><span class="t-sub"><?= $hasInc ? 'after incentives' : 'no incentives apply' ?></span></div>
		<div class="tile"><span class="t-num"><?= $r && $r['payback_years'] !== null ? e(number_format($r['payback_years'], 1)) . ' <small>years</small>' : '-' ?></span><span class="t-label">Payback</span><span class="t-sub">when savings repay the net cost</span></div>
	</div>
	<?php if (!empty($drawing)): ?>
		<figure class="aerial">
			<div class="aerial-frame"><?= $drawing['svg'] /* built by App\Layout::drawing from numbers and base64 tiles only */ ?></div>
			<figcaption><?= e($drawing['attribution'] ?? '') ?><?= $drawing['trackers'] ? '<span class="sweep-key">Dashed red circle: the space each tracker sweeps as it turns.</span>' : '' ?></figcaption>
		</figure>
	<?php else: ?>
		<div class="aerial-placeholder"><span>Site layout drawing</span><small>The aerial view with the panel layout goes here.</small></div>
	<?php endif; ?>
	<div class="two-col">
		<div>
			<h2>Equipment</h2>
			<table class="list">
				<?php foreach ($equipment as $q): ?>
					<tr><td class="qty"><?= $q['qty'] !== null ? (int) $q['qty'] . ' &times;' : '' ?></td><td><?= e($q['name']) ?><?= $q['watts'] ? ' <span class="muted">(' . e($n0($q['watts'])) . ' W)</span>' : '' ?><div class="muted small"><?= e($q['category']) ?></div></td></tr>
				<?php endforeach; ?>
				<tr><td></td><td class="muted small">Balance of system wiring and electrical, permits, engineering, interconnection and installation are included.</td></tr>
			</table>
		</div>
		<div>
			<h2>Warranties</h2>
			<table class="list">
				<?php foreach ($warranties as $w): ?><tr><td><strong><?= e($w['label']) ?></strong><div class="small"><?= e($w['text']) ?></div></td></tr><?php endforeach; ?>
			</table>
		</div>
	</div>
	<?php if (!empty($d['customer_notes'])): ?>
		<div class="notes"><h2>Notes</h2><p><?= nl2br(e($d['customer_notes']), false) ?></p></div>
	<?php endif; ?>
	<?= $incNote ?>
</section>

<!-- 3. System performance -->
<section class="page">
	<h1 id="p-performance">System Performance</h1>
	<?php if ($prod): ?>
		<div class="tiles tiles-3">
			<div class="tile tile-orange"><span class="t-num"><?= e($n0($annual)) ?> <small>kWh</small></span><span class="t-label">Year-one production</span><span class="t-sub"><?= e(Production::MODELS[$model]) ?></span></div>
			<div class="tile tile-quiet"><span class="t-num"><?= e($n0($otherAnnual)) ?> <small>kWh</small></span><span class="t-label"><?= e(Production::MODEL_SHORT[$other]) ?> check</span><span class="t-sub"><?= $other === 'pvwatts' ? 'PVWatts v8, a simpler estimate' : 'SAM, a fuller estimate' ?></span></div>
			<div class="tile tile-quiet"><span class="t-num"><?= $usage ? e($n0($usage['annual'])) . ' <small>kWh</small>' : '-' ?></span><span class="t-label">Your yearly use</span><span class="t-sub"><?= $usage && $usage['source'] === 'bill' ? 'from your bills' : 'your yearly total; months estimated' ?></span></div>
		</div>
		<h2>Production by month, year one</h2>
		<?= Chart::production($prod[$model]['monthly_kwh'], $usage['kwh'] ?? null, ($usage['source'] ?? '') !== 'bill') ?>
		<p class="legend"><span class="key key-green"></span> Solar production <?php if ($usage): ?><span class="key key-blue<?= $usage['source'] !== 'bill' ? ' is-dashed' : '' ?>"></span> Your use<?= $usage['source'] !== 'bill' ? ', estimated: your yearly use spread by a typical ' . e(strtolower(Savings::PROFILES[$usage['profile']][0])) . ' pattern' : ', from your bills' ?><?php endif; ?></p>
		<p class="small muted">Year one is the first full year the system runs. Production falls a little each year after that (<?= e($p1($r['degradation']['value'] ?? App\Presales::num('degradation_default'), 2)) ?> a year, from the panel's performance warranty), which the savings pages include.</p>
	<?php else: ?>
		<p class="missing">The production estimate hasn't been run for this system yet.</p>
	<?php endif; ?>

	<h2>Arrays</h2>
	<table class="arrays">
		<thead><tr><th>Array</th><th>Panels</th><th>Direction</th><th>Tilt</th><th>Shade</th></tr></thead>
		<tbody>
		<?php foreach ($arrays as $i => $ar): $tracker = $ar['mount'] === 'tracker'; $az = $ar['azimuth'] !== null ? (int) $ar['azimuth'] : null; ?>
			<tr>
				<td><strong><?= e($ar['name'] ?: 'Array ' . ($i + 1)) ?></strong><div class="small muted"><?= e(App\Presales::MOUNTS[$ar['mount']] ?? '') ?> mount</div></td>
				<td><?= (int) $ar['panels'] ?></td>
				<td><div class="orient-cell"><?= Chart::compass($az, $tracker) ?><span><?= $tracker ? 'Follows the sun' : ($az !== null ? $az . '° ' . e(App\Projects::compass($az)) : '') ?></span></div></td>
				<td><div class="orient-cell"><?= $tracker ? '' : Chart::pitch($ar['tilt'] !== null ? (float) $ar['tilt'] : null) ?><span><?= $tracker ? 'Tracks' : ($ar['tilt'] !== null ? e(rtrim(rtrim(number_format((float) $ar['tilt'], 1), '0'), '.')) . '°' : '') ?></span></div></td>
				<td><?= (float) ($ar['shading_pct'] ?? 0) > 0 ? e($p1((float) $ar['shading_pct'])) : 'None' ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ($prod): $set = $prod['settings'] ?? []; ?>
		<h2>Assumptions</h2>
		<table class="kv kv-wide">
			<?php $ds = (string) ($prod['weather']['dataset'] ?? ''); $sam = Production::samVersion($prod); ?>
			<tr><th>Weather</th><td>A typical weather year for this site from the National Solar Radiation Database (NSRDB), built from many years of satellite measurements<?= $ds !== '' ? ' (' . e(strtoupper(str_replace('-', ' ', $ds))) . ($ds === App\SolarResource::DATASET ? ', the newest release' : '') . ')' : '' ?></td></tr>
			<tr><th>Model</th><td><?= $model === 'detailed' ? 'The System Advisor Model (SAM' . ($sam ? ' ' . e($sam) : '') . ') detailed model' : 'PVWatts v8' ?>, with the panel and inverter makers' certified (CEC) performance data. <?= $model === 'detailed' ? 'PVWatts v8, a simpler model, is shown as a check.' : 'The SAM detailed model is shown as a check.' ?></td></tr>
			<tr><th>Source</th><td>SAM, PVWatts and the NSRDB are from the U.S. Department of Energy's National Laboratory of the Rockies (formerly NREL)</td></tr>
			<tr><th>System losses</th><td><?= e($p1((float) ($prod['other_loss_percent'] ?? 0), 2)) ?> (wiring, soiling, mismatch, connections and availability)<?= ($prod['mlpe'] ?? '') === 'optimizers' ? '; optimizers ' . e($p1((float) ($set['optimizer_loss'] ?? 0), 2)) : '' ?>; inverter losses from its CEC data</td></tr>
			<tr><th>Shading</th><td><?= (float) max(array_map(static fn ($x) => (float) ($x['shading_pct'] ?? 0), $arrays ?: [[]])) > 0 ? 'Per array, as listed above' : 'None expected' ?></td></tr>
			<tr><th>Snow</th><td>Not modeled</td></tr>
			<tr><th>Size</th><td><?= e(number_format((float) $prod['kw_dc'], 2)) ?> kW DC, <?= e(number_format((float) $prod['kw_ac'], 2)) ?> kW AC</td></tr>
		</table>
	<?php endif; ?>
</section>

<!-- 4. Electric bill savings -->
<section class="page">
	<h1 id="p-savings">Electric Bill Savings</h1>
	<?php if ($r): $y1 = $r['first_year']; ?>
		<div class="tiles tiles-3">
			<div class="tile tile-green"><span class="t-num"><?= e($m0($y1['savings'])) ?></span><span class="t-label">First-year savings</span><span class="t-sub">bill savings <?= e($m0($y1['bill_savings'])) ?> + SRECs <?= e($m0($y1['srec'])) ?></span></div>
			<div class="tile"><span class="t-num"><?= e($m0($r['lifetime_savings'])) ?></span><span class="t-label">Savings over <?= (int) $a['years'] ?> years</span><span class="t-sub">bills and SRECs, before the system's cost</span></div>
			<div class="tile tile-quiet"><span class="t-num"><?= e($m0($y1['bill_without'])) ?> &rarr; <?= $y1['bill_with'] - $y1['payout'] < 0 ? e($m0($y1['payout'] - $y1['bill_with'])) . ' <small>credit</small>' : e($m0($y1['bill_with'] - $y1['payout'])) ?></span><span class="t-label">Yearly electric bill</span><span class="t-sub">without solar &rarr; with solar, year one</span></div>
		</div>
		<h2>Year one with solar, June to May</h2>
		<p class="small">Pennsylvania's net metering year runs June 1 to May 31, following the PJM grid's planning year, so this year starts in June.</p>
		<?= Chart::bills($r['months']) ?>
		<p class="legend"><span class="key key-blue"></span> Bill without solar <span class="key key-green"></span> Bill with solar</p>
		<table class="data data-tight">
			<thead><tr><th>Month</th><th class="num">Use (kWh)</th><th class="num">Solar (kWh)</th><th class="num">Bill without solar</th><th class="num">Bill with solar</th><th class="num">Savings</th><th class="num">Credit banked (kWh)</th></tr></thead>
			<tbody>
			<?php foreach ($r['months'] as $mo): ?>
				<tr><td><?= e($mo['label']) ?></td><td class="num"><?= e($n0($mo['usage'])) ?></td><td class="num"><?= e($n0($mo['solar'])) ?></td><td class="num"><?= e($m0($mo['bill_without'])) ?></td>
					<td class="num"><?= e($m0($mo['bill_with'])) ?><?= $mo['payout'] > 0 ? '<div class="small">true-up credit ' . e($m0(-$mo['payout'])) . '</div>' : '' ?></td><td class="num"><?= e($m0($mo['savings'])) ?></td><td class="num"><?= e($n0($mo['banked'])) ?></td></tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot><tr><th>Year</th><th class="num"><?= e($n0($y1['usage'])) ?></th><th class="num"><?= e($n0($y1['solar'])) ?></th><th class="num"><?= e($m0($y1['bill_without'])) ?></th><th class="num"><?= e($m0($y1['bill_with'] - $y1['payout'])) ?></th><th class="num"><?= e($m0($y1['bill_savings'])) ?></th><th></th></tr></tfoot>
		</table>
		<p class="small">Net metering: the customer charge is billed every month. Extra solar is banked as kWh credit for later months; credit left at the end of the net metering year (May 31) is paid at the Price to Compare. Monthly use is <?= $usage['source'] === 'bill' ? 'from your bills' : 'estimated from your yearly use' ?>.</p>
		<div class="two-col compact">
			<div>
				<h2>Your utility rate</h2>
				<table class="kv">
					<tr><th>Utility</th><td><?= e((string) ($utility ?: ($rateSum['utility'] ?? ''))) ?></td></tr>
					<tr><th>Rate</th><td><?= e((string) ($o['utility_tariff'] ?: ($rateSum['name'] ?? ''))) ?></td></tr>
					<?php if ($facts): ?><tr><th>Customer charge</th><td><?= e($m2($facts['fixed'][0])) ?> a month</td></tr><?php endif; ?>
					<?php if ($rateSum && $rateSum['energy_min'] !== null): ?><tr><th>Energy price</th><td><?= e(number_format($rateSum['energy_min'] * 100, 2)) ?><?= $rateSum['energy_max'] !== $rateSum['energy_min'] ? ' to ' . e(number_format($rateSum['energy_max'] * 100, 2)) : '' ?> cents per kWh</td></tr><?php endif; ?>
					<?php if ($r['ptc']['value'] !== null): ?><tr><th>Price to Compare</th><td><?= e(number_format($r['ptc']['value'] * 100, 2)) ?> cents per kWh (true-up credit)</td></tr><?php endif; ?>
					<tr><th>Yearly increase</th><td><?= e($p1($a['escalation'], 2)) ?> assumed<?php
						$hist = [];
						foreach ([3, 5, 10] as $yy) {
							// Only the periods that back the assumed rate
							if (isset($trend['y' . $yy]['pct']) && $trend['y' . $yy]['pct'] !== null && (float) $trend['y' . $yy]['pct'] >= (float) $a['escalation'] - 0.05) {
								$hist[] = $yy . ' yr ' . sprintf('%+.1f%%', (float) $trend['y' . $yy]['pct']);
							}
						}
						echo $hist ? '<div class="small">This rate\'s actual increase: ' . e(implode(', ', $hist)) . ' a year</div>' : '';
					?></td></tr>
				</table>
			</div>
			<div>
				<h2>SRECs</h2>
				<table class="kv">
					<tr><th>Price</th><td><?= e($m2($a['srec_price'])) ?> per SREC (1 MWh)</td></tr>
					<tr><th>Yearly change</th><td><?= $a['srec_decline'] > 0 ? '-' . e($p1($a['srec_decline'], 2)) : 'None' ?></td></tr>
					<tr><th>Paid for</th><td><?= (int) $a['srec_years'] ?> years</td></tr>
					<tr><th>Year one</th><td><?= e(number_format($y1['solar'] / 1000, 1)) ?> SRECs, <?= e($m0($y1['srec'])) ?></td></tr>
					<tr><th>Total</th><td><?= e($m0($r['lifetime_srec'])) ?></td></tr>
				</table>
				<p class="small">Pennsylvania solar renewable energy credits: every 1,000 kWh produced earns one, sold through an SREC broker at market prices.</p>
			</div>
		</div>
		<?php if ($savings['notes']): ?><p class="small muted"><?= e(implode(' ', $savings['notes'])) ?></p><?php endif; ?>
	<?php else: ?>
		<p class="missing">Savings will show once the build sheet has: <?= e(implode(' ', $savings['missing'])) ?></p>
	<?php endif; ?>
</section>

<?php if ($r): ?>
<!-- 5. Return on investment -->
<section class="page">
	<h1 id="p-roi">Return on Investment</h1>
	<div class="equation">
		<div><span class="t-num"><?= e($m0($r['lifetime_savings'])) ?></span><span class="t-label">Savings over <?= (int) $a['years'] ?> years</span></div>
		<?php if ($r['depreciation']): ?>
			<div class="op">+</div>
			<div><span class="t-num"><?= e($m0($r['lifetime_dep_fed'] + $r['lifetime_dep_state'])) ?></span><span class="t-label">Depreciation tax savings</span></div>
		<?php endif; ?>
		<div class="op">&minus;</div>
		<div><span class="t-num"><?= e($m0($r['net_cost'])) ?><?= $star ?></span><span class="t-label">Net cost</span></div>
		<div class="op">=</div>
		<div><span class="t-num t-green"><?= e($m0($r['net_savings'])) ?></span><span class="t-label">Net savings</span></div>
	</div>
	<h2>Cumulative cash flow</h2>
	<?= Chart::cashflow($r['years'], $r['price'] - $r['incentives_upfront'], $r['payback_years']) ?>
	<p class="legend"><span class="key key-blue"></span> Still paying for itself <span class="key key-green"></span> Paid for, savings ahead</p>
	<table class="stats">
		<tr><th>Payback</th><td class="num"><?= e(Savings::yearsText($r['payback_years'])) ?></td><td>When the savings (bills and SRECs<?= $r['depreciation'] ? ', plus depreciation' : '' ?>) add up to the net cost.</td></tr>
		<?php if (!$residential): ?>
		<tr><th>Discounted payback</th><td class="num"><?= e(Savings::yearsText($r['discounted_payback_years'])) ?></td><td>The same, counting each future year's savings in today's dollars (a <?= e($p1($a['discount'], 2)) ?> yearly discount rate), so it comes later.</td></tr>
		<tr><th>Net present value</th><td class="num"><?= e($m0($r['npv'])) ?></td><td>All <?= (int) $a['years'] ?> years of savings in today's dollars, minus the net cost.</td></tr>
		<?php endif; ?>
		<tr><th>Total return</th><td class="num"><?= e($p1($r['roi_pct'], 0)) ?></td><td>Net savings over <?= (int) $a['years'] ?> years as a share of the money put in.</td></tr>
		<tr><th>Annual rate of return</th><td class="num"><?= $r['irr'] !== null ? e($p1($r['irr'], 1)) : '-' ?></td><td>The yearly interest rate that would grow the money put in as much as the system does.</td></tr>
		<tr><th>Cost of solar power</th><td class="num"><?= $r['cost_per_kwh'] !== null ? e(number_format($r['cost_per_kwh'] * 100, 1)) . ' cents/kWh' : '-' ?></td><td>The net cost divided by all the kWh it makes in <?= (int) $a['years'] ?> years.</td></tr>
	</table>
	<?= $incNote ?>
</section>

<!-- 6. Projection -->
<section class="page">
	<h1 id="p-projection"><?= (int) $a['years'] ?>-Year Projection</h1>
	<table class="data data-tight data-proj<?= $r['depreciation'] ? ' is-wide' : '' ?>">
		<thead><tr><th>Year</th><th class="num">Solar (kWh)</th><th class="num">Bill without solar</th><th class="num">Bill with solar</th><th class="num">Bill savings</th><th class="num">SRECs</th><?= $r['incentives_year1'] > 0 ? '<th class="num">Incentives</th>' : '' ?><?= $r['depreciation'] ? '<th class="num">Federal depreciation</th><th class="num">State depreciation</th>' : '' ?><th class="num">Cash flow</th><th class="num">Cumulative</th></tr></thead>
		<tbody>
			<tr><td>Start</td><td></td><td></td><td></td><td></td><td></td><?= $r['incentives_year1'] > 0 ? '<td></td>' : '' ?><?= $r['depreciation'] ? '<td></td><td></td>' : '' ?><td class="num"><?= e($m0(-($r['price'] - $r['incentives_upfront']))) ?></td><td class="num"><?= e($m0(-($r['price'] - $r['incentives_upfront']))) ?></td></tr>
			<?php foreach ($r['years'] as $yr): ?>
				<tr><td><?= (int) $yr['year'] ?></td><td class="num"><?= e($n0($yr['solar'])) ?></td><td class="num"><?= e($m0($yr['bill_without'])) ?></td><td class="num"><?= e($m0($yr['bill_with'] - $yr['payout'])) ?></td><td class="num"><?= e($m0($yr['bill_savings'])) ?></td><td class="num"><?= e($m0($yr['srec'])) ?></td><?= $r['incentives_year1'] > 0 ? '<td class="num">' . ($yr['incentives'] > 0 ? e($m0($yr['incentives'])) : '') . '</td>' : '' ?><?= $r['depreciation'] ? '<td class="num">' . ($yr['dep_fed'] > 0 ? e($m0($yr['dep_fed'])) : '') . '</td><td class="num">' . ($yr['dep_state'] > 0 ? e($m0($yr['dep_state'])) : '') . '</td>' : '' ?><td class="num"><?= e($m0($yr['cash_flow'])) ?></td><td class="num<?= $yr['cumulative'] < 0 ? ' neg' : '' ?>"><?= e($m0($yr['cumulative'])) ?></td></tr>
			<?php endforeach; ?>
		</tbody>
		<tfoot><tr><th>Total</th><th class="num"><?= e($n0($r['lifetime_solar_kwh'])) ?></th><th></th><th></th><th class="num"><?= e($m0($r['lifetime_bill_savings'])) ?></th><th class="num"><?= e($m0($r['lifetime_srec'])) ?></th><?= $r['incentives_year1'] > 0 ? '<th></th>' : '' ?><?= $r['depreciation'] ? '<th class="num">' . e($m0($r['lifetime_dep_fed'])) . '</th><th class="num">' . e($m0($r['lifetime_dep_state'])) . '</th>' : '' ?><th></th><th class="num"><?= e($m0($r['net_savings'])) ?></th></tr></tfoot>
	</table>
	<p class="small">Bill with solar is net of the yearly true-up credit. <?= $r['depreciation'] ? 'Depreciation is the tax saved by deducting the system: federal ' . e(lcfirst(App\Savings::DEP_METHODS[$r['dep']['method']])) . ' at a ' . e($p1($a['dep_fed_rate'], 2)) . ' tax rate on the price less half the tax credits; state 5-year MACRS at ' . e($p1($a['dep_state_rate'], 2)) . '. Ask your tax advisor what applies to you. ' : '' ?>Assumes rates rise <?= e($p1($a['escalation'], 2)) ?> a year, production falls <?= e($p1($r['degradation']['value'], 2)) ?> a year and your use stays the same. Estimates, not a guarantee of savings.</p>
</section>
<?php endif; ?>

<!-- 7. Price and payments -->
<section class="page">
	<h1 id="p-price">Price and Payments</h1>
	<p class="lead"><?= e(number_format($kwDc, 2)) ?> kW DC solar energy system: <?= e(implode(', ', array_map(static fn ($q) => ($q['qty'] !== null ? $q['qty'] . ' x ' : '') . $q['name'], array_filter($equipment, static fn ($q) => in_array($q['category'], ['Solar panels', 'Inverters', 'Optimizers and microinverters', 'Batteries'], true))))) ?>.</p>
	<div class="two-col">
		<div>
			<h2>Cash price</h2>
			<table class="kv kv-money">
				<tr><th>Total system price<?php if (!empty($d['tax_exempt'])): ?><div class="small">Priced as tax exempt (requires your exemption certificate)</div><?php endif; ?></th><td class="num"><strong><?= e($m2($price)) ?></strong></td></tr>
				<tr><th>Price per watt</th><td class="num"><?= $kwDc > 0 ? e($m2($price / ($kwDc * 1000))) : '' ?></td></tr>
				<?php foreach ($r['incentives'] ?? [] as $x): ?>
					<tr><th><?= e($x['label']) ?><div class="small"><?= $x['timing'] === 'year1' ? 'Tax credit, claimed for year one' : 'Reduces the cost up front' ?></div></th><td class="num">&minus;<?= e($m2($x['amount'])) ?></td></tr>
				<?php endforeach; ?>
				<tr class="total"><th>Net cost</th><td class="num"><?= e($m2($netCost)) ?></td></tr>
			</table>
			<?php if (!$hasInc): ?><p class="small">No incentives are included in this proposal.</p><?php else: ?><p class="small">Incentives depend on the program's rules and your eligibility; ask your tax advisor about tax credits.</p><?php endif; ?>
		</div>
		<div>
			<h2>Financing</h2>
			<?php if ($loan): ?>
				<table class="kv kv-money">
					<tr><th>Down payment</th><td class="num"><?= e($m2($loan['down'])) ?></td></tr>
					<tr><th>Amount financed</th><td class="num"><?= e($m2($loan['principal'])) ?></td></tr>
					<tr><th>Rate and term</th><td class="num"><?= e($p1($loan['rate'], 2)) ?> APR, <?= (int) $loan['years'] ?> years</td></tr>
					<tr class="total"><th>Monthly payment</th><td class="num"><?= e($m2($loan['payment'])) ?></td></tr>
					<tr><th>Total interest</th><td class="num"><?= e($m2($loan['interest'])) ?></td></tr>
				</table>
				<p class="small">An estimate with the rate and term shown. Your lender's terms, fees and approval decide the actual payment. Trifecta Solar is not a lender.</p>
			<?php else: ?>
				<p class="small">Paying with a loan? Ask us for an estimate with your lender's rate and term.</p>
			<?php endif; ?>
		</div>
	</div>
	<h2>Payment schedule</h2>
	<table class="data">
		<thead><tr><th>Payment to Trifecta Solar</th><th class="num">Amount</th></tr></thead>
		<tbody>
		<?php foreach ($calc['payments'] as $p): ?><tr><td><?= e($p['label']) ?></td><td class="num"><?= e($m2((float) $p['amount'])) ?></td></tr><?php endforeach; ?>
		</tbody>
		<tfoot><tr><th>Total</th><th class="num"><?= e($m2($price)) ?></th></tr></tfoot>
	</table>
	<p class="small">The deposit can be paid by credit card; later payments by check, wire transfer or ACH. This proposal is valid until <?= e($longDate($until)) ?>.</p>
</section>

<!-- 8. Next steps -->
<section class="page">
	<h1 id="p-next">Next Steps</h1>
	<ol class="steps">
		<?php foreach ($steps as $i => $s): ?>
			<li><span class="step-num"><?= $i + 1 ?></span><div><strong><?= e($s['title']) ?></strong><?= $s['text'] !== '' ? '<div>' . e($s['text']) . '</div>' : '' ?></div></li>
		<?php endforeach; ?>
	</ol>
	<?php if ($sales): ?>
		<div class="card-person card-wide">
			<div class="label">Questions? Your sales representative</div>
			<strong><?= e($sales['name']) ?></strong>
			<div><?= e(implode(' · ', array_filter([$sales['phone'] ?? '', $sales['email'] ?? '']))) ?></div>
		</div>
	<?php endif; ?>
</section>

<!-- 9. Clarifications -->
<section class="page terms">
	<h1 id="p-clarifications">Clarifications</h1>
	<?php foreach ($terms['intro'] as $b): ?><div class="block"><?= $b['heading'] ? '<h3>' . $b['heading'] . '</h3>' : '' ?><?= $b['html'] ?></div><?php endforeach; ?>
	<?php if ($terms['clarification']): ?>
		<ul class="clar">
			<?php foreach ($terms['clarification'] as $b): ?><li><?= $b['heading'] ? '<strong>' . $b['heading'] . '</strong> ' : '' ?><?= preg_replace('#^<p>(.*)</p>$#s', '$1', $b['html']) ?></li><?php endforeach; ?>
		</ul>
	<?php elseif (!$terms['version']): ?>
		<p class="missing">The proposal terms haven't been entered yet.</p>
	<?php endif; ?>
</section>

<!-- 10. Terms and signatures -->
<section class="page terms">
	<h1 id="p-terms">General Terms &amp; Conditions</h1>
	<?php foreach ($terms['section'] as $b): ?><div class="block"><?= $b['heading'] ? '<h3>' . $b['heading'] . '</h3>' : '' ?><?= $b['html'] ?></div><?php endforeach; ?>
	<?php if ($terms['authorization']): ?>
		<h2>Final Authorizations and Consents</h2>
		<?php foreach ($terms['authorization'] as $b): ?><div class="block"><?= $b['heading'] ? '<h3>' . $b['heading'] . '</h3>' : '' ?><?= $b['html'] ?></div><?php endforeach; ?>
	<?php endif; ?>
	<div class="signatures">
		<p class="small">By signing, the Customer accepts this proposal (<?= e($quote) ?>, <?= e($d['name']) ?>, <?= e($m2($price)) ?>) and the terms above.</p>
		<div class="sig-row"><div class="sig"><span>Customer signature</span></div><div class="sig sig-date"><span>Date</span></div></div>
		<div class="sig-row"><div class="sig"><span>Printed name<?= $residential ? '' : ' and title' ?></span></div><div class="sig sig-date"></div></div>
		<div class="sig-row"><div class="sig"><span>Trifecta Solar representative</span></div><div class="sig sig-date"><span>Date</span></div></div>
	</div>
</section>

<?php if ($terms['addendum']): ?>
<section class="page terms">
	<?php foreach ($terms['addendum'] as $b): ?><div class="block"><?= $b['heading'] ? '<h2>' . $b['heading'] . '</h2>' : '' ?><?= $b['html'] ?></div><?php endforeach; ?>
	<div class="signatures">
		<div class="sig-row"><div class="sig"><span>Customer signature</span></div><div class="sig sig-date"><span>Date</span></div></div>
	</div>
</section>
<?php endif; ?>
</body>
</html>
