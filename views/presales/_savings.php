<?php
use App\Designs;
use App\Presales;
use App\Production;
use App\Proposal;
use App\Savings;

/**
 * Build sheet: the savings (App\Savings, from the saved build sheet) and the proposal's fields.
 * Inside the build sheet form, so they save with it.
 *
 * @var array    $o         opportunity
 * @var array    $d         design row
 * @var array    $calc
 * @var array    $sav       Savings::forDesign()
 * @var array    $contacts  the customer's contacts
 * @var string   $base
 * @var callable $foldBtn
 */
$r = $sav['result'];
$a = $sav['assumptions'];
$own = json_decode((string) ($d['savings_inputs'] ?? ''), true) ?: [];
$m0 = static fn (?float $v): string => $v === null ? '' : ($v < 0 ? '-$' : '$') . number_format(abs($v));
$f = static fn ($v, int $dec = 4) => Presales::field($v === null || $v === '' ? null : (float) $v, $dec);
$inc = Savings::incentiveRows($d) ?: [[]];   // one blank row when there are none; "+ Incentive" adds more
[$pDate, $pUntil] = Proposal::dates($d);
$isAdmin = App\Auth::isAdmin();
$model = Savings::model($d);
?>
<section class="card band" id="sec-savings">
	<div class="card-head card-head-plain band-head">
		<h2><?= $foldBtn('sec-savings', 'Savings', false) ?>Savings</h2>
		<span class="small"><?= $r ? e($m0($r['first_year']['savings'])) . ' year one, payback ' . e($r['payback_years'] !== null ? number_format($r['payback_years'], 1) . ' years' : 'none') : 'Not ready' ?></span>
	</div>
	<?php if ($sav['missing']): ?>
		<p class="small"><strong>Savings need:</strong></p>
		<ul class="prod-missing"><?php foreach ($sav['missing'] as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul>
	<?php else: $y1 = $r['first_year']; ?>
		<div class="prod-tiles sav-tiles">
			<div class="prod-tile is-chosen"><span class="m-label">First-year savings</span><span class="m-num"><?= e($m0($y1['savings'])) ?></span><span class="muted small">bill <?= e($m0($y1['bill_savings'])) ?>, SRECs <?= e($m0($y1['srec'])) ?><?= $r['depreciation'] ? ', plus depreciation ' . e($m0($y1['dep_fed'] + $y1['dep_state'])) : '' ?></span></div>
			<div class="prod-tile"><span class="m-label">Payback</span><span class="m-num"><?= $r['payback_years'] !== null ? e(number_format($r['payback_years'], 1)) . ' <small>years</small>' : '-' ?></span><span class="muted small">discounted <?= $r['discounted_payback_years'] !== null ? e(number_format($r['discounted_payback_years'], 1)) . ' years' : '-' ?></span></div>
			<div class="prod-tile"><span class="m-label"><?= (int) $a['years'] ?>-year net savings</span><span class="m-num"><?= e($m0($r['net_savings'])) ?></span><span class="muted small">NPV <?= e($m0($r['npv'])) ?>, IRR <?= $r['irr'] !== null ? e(number_format($r['irr'], 1)) . '%' : '-' ?></span></div>
			<div class="prod-tile"><span class="m-label">Energy from solar</span><span class="m-num"><?= e(number_format((float) $r['offset_pct'])) ?>%</span><span class="muted small"><?= e(Production::MODELS[$sav['model']]) ?></span></div>
		</div>
		<p class="hint mt-sm">NPV and discounted payback show on the proposal only for business customers (commercial or agricultural)<?= Proposal::residential($o) ? '; this is a residential customer, so the proposal leaves them off' : '' ?>.</p>
		<?php if ($r['dep']): ?>
			<p class="hint">Depreciation (business customer): federal <?= e($m0($r['lifetime_dep_fed'])) ?> (<?= e(lcfirst(Savings::DEP_METHODS[$r['dep']['method']])) ?> on <?= e($m0($r['dep']['basis_fed'])) ?>, the price less half the tax credits, at <?= e($f($a['dep_fed_rate'], 2)) ?>%), state <?= e($m0($r['lifetime_dep_state'])) ?> (5-year MACRS on the price at <?= e($f($a['dep_state_rate'], 2)) ?>%). Counted in the payback, return and <?= (int) $a['years'] ?>-year figures.</p>
		<?php endif; ?>
		<div class="table-wrap mt-sm"><table class="table table-tight sav-table">
			<thead><tr><th>Year one</th><?php foreach ($r['months'] as $mo): ?><th class="num"><?= e($mo['label']) ?></th><?php endforeach; ?><th class="num">Year</th></tr></thead>
			<tbody>
				<tr><td>Use (kWh)</td><?php foreach ($r['months'] as $mo): ?><td class="num"><?= e(number_format($mo['usage'])) ?></td><?php endforeach; ?><td class="num"><?= e(number_format($y1['usage'])) ?></td></tr>
				<tr><td>Solar (kWh)</td><?php foreach ($r['months'] as $mo): ?><td class="num"><?= e(number_format($mo['solar'])) ?></td><?php endforeach; ?><td class="num"><?= e(number_format($y1['solar'])) ?></td></tr>
				<tr><td>Bill without</td><?php foreach ($r['months'] as $mo): ?><td class="num"><?= e($m0($mo['bill_without'])) ?></td><?php endforeach; ?><td class="num"><?= e($m0($y1['bill_without'])) ?></td></tr>
				<tr><td>Bill with</td><?php foreach ($r['months'] as $mo): ?><td class="num"><?= e($m0($mo['bill_with'] - $mo['payout'])) ?></td><?php endforeach; ?><td class="num"><?= e($m0($y1['bill_with'] - $y1['payout'])) ?></td></tr>
				<tr><td>Banked (kWh)</td><?php foreach ($r['months'] as $mo): ?><td class="num"><?= e(number_format($mo['banked'])) ?></td><?php endforeach; ?><td></td></tr>
			</tbody>
		</table></div>
		<p class="hint">From the saved build sheet. Utility rate <?= $r['rate_source'] === 'bill' ? 'from the bill' : 'from OpenEI' ?>. True-up at <?= $r['ptc']['value'] !== null ? e(number_format($r['ptc']['value'] * 100, 2)) . ' cents per kWh (' . e($r['ptc']['source']) . ')' : '$0 (no Price to Compare set)' ?>; degradation <?= e($f($r['degradation']['value'], 2)) ?>% a year (<?= e($r['degradation']['source']) ?>).<?= $sav['notes'] ? ' ' . e(implode(' ', $sav['notes'])) : '' ?></p>
	<?php endif; ?>

	<h3 class="mt-sm">Assumptions</h3>
	<p class="hint">Blank uses the Rates page value (shown). A value here changes this build sheet's proposal only.</p>
	<div class="sav-grid">
		<?php foreach (Savings::ASSUMPTIONS as $k => [, $label, $unit]): ?>
			<label><?= e($label) ?> (<?= e($unit) ?>)
				<input type="text" name="savings[<?= e($k) ?>]" value="<?= isset($own[$k]) ? e($f($own[$k], 2)) : '' ?>" placeholder="<?= e($f($a['default_' . $k], 2)) ?>" inputmode="decimal" class="<?= in_array($k, $a['changed'], true) ? 'is-changed' : '' ?>">
				<?php if ($k === 'dep_state_rate'): ?><small class="hint">The default is the personal income tax rate, which owners of LLCs, S corporations and sole proprietorships pay. A C corporation pays the corporate rate instead; type it here.</small><?php endif; ?>
			</label>
		<?php endforeach; ?>
		<label>Depreciation
			<select name="savings[dep]">
				<option value="" <?= !in_array($own['dep'] ?? '', ['on', 'off'], true) ? 'selected' : '' ?>>Automatic (<?= Savings::depreciationOn($o, ['savings_inputs' => null]) ? 'on: business customer' : 'off: not a business customer' ?>)</option>
				<option value="on" <?= ($own['dep'] ?? '') === 'on' ? 'selected' : '' ?>>Count it</option>
				<option value="off" <?= ($own['dep'] ?? '') === 'off' ? 'selected' : '' ?>>Don't count it</option>
			</select>
		</label>
		<?php $depDefault = Savings::depreciationMethod(['savings_inputs' => null]); ?>
		<label>Federal depreciation
			<select name="savings[dep_method]">
				<?php foreach (Savings::DEP_METHODS as $k => $label): ?><option value="<?= e($k) ?>" <?= Savings::depreciationMethod($d) === $k ? 'selected' : '' ?>><?= e($label) ?><?= $k === $depDefault ? ' (Rates page)' : '' ?></option><?php endforeach; ?>
			</select>
		</label>
	</div>

	<h3 class="mt-sm">Incentives</h3>
	<p class="hint">Utility rebates, grants (USDA REAP) and tax credits (commercial ITC, with any bonus). Each is listed on the Price and Payments page; the net cost, payback and savings figures include them. Residential jobs have no federal credit for systems placed in service after 2025.</p>
	<div class="sav-inc" data-rows>
		<div class="sav-inc-headrow"><span>Incentive</span><span>Amount</span><span>As</span><span>When</span><span></span></div>
		<?php foreach ($inc as $i => $x): ?>
			<div class="eq-row sav-inc-row">
			<input type="text" name="incentives[<?= $i ?>][label]" value="<?= e((string) ($x['label'] ?? '')) ?>" placeholder="e.g. Federal ITC (30%)" aria-label="Incentive name" maxlength="80">
			<input type="text" name="incentives[<?= $i ?>][value]" value="<?= isset($x['value']) ? e($f($x['value'], 2)) : '' ?>" inputmode="decimal" aria-label="Incentive amount">
			<select name="incentives[<?= $i ?>][kind]" aria-label="Dollars or percent of the price">
				<option value="amount" <?= ($x['kind'] ?? '') !== 'pct' ? 'selected' : '' ?>>Dollars</option>
				<option value="pct" <?= ($x['kind'] ?? '') === 'pct' ? 'selected' : '' ?>>% of price</option>
			</select>
			<select name="incentives[<?= $i ?>][timing]" aria-label="When it comes">
				<option value="upfront" <?= ($x['timing'] ?? '') !== 'year1' ? 'selected' : '' ?>>Up front (rebate, grant)</option>
				<option value="year1" <?= ($x['timing'] ?? '') === 'year1' ? 'selected' : '' ?>>Year one (tax credit)</option>
			</select>
			<button type="button" class="btn btn-ghost btn-small row-del" title="Remove" aria-label="Remove this incentive">&times;</button>
			</div>
		<?php endforeach; ?>
		<button type="button" class="btn btn-ghost btn-small row-add">+ Incentive</button>
	</div>
</section>

<section class="card band" id="sec-proposal">
	<div class="card-head card-head-plain band-head">
		<h2><?= $foldBtn('sec-proposal', 'Proposal', false) ?>Proposal</h2>
		<span class="small band-head-fill"><?= $d['quote_number'] ? 'Proposal ' . e($d['quote_number']) . ' &middot; ' : '' ?>valid until <?= e(date('m/d/Y', (int) strtotime($pUntil))) ?><?= $d['guarantee_on'] ? ' &middot; guarantee' : '' ?></span>
		<div class="prop-actions">
			<a href="<?= e($base) ?>/proposal" class="btn btn-secondary btn-small" data-save-then="proposal" title="The proposal as the customer gets it (saves the build sheet first)">Open proposal</a>
			<a href="<?= e($base) ?>/proposal/pdf" class="btn btn-secondary btn-small" data-pdf>PDF</a>
		</div>
	</div>
	<p class="hint">Opening the proposal saves the build sheet first.<?= $d['quote_number'] ? ' Proposal ' . e($d['quote_number']) . '.' : ' The proposal number is given the first time the proposal opens.' ?></p>
	<div class="grid-form">
		<label>Proposal date <input type="text" name="proposal_date" value="<?= $d['proposal_date'] ? e(date('m/d/Y', (int) strtotime($d['proposal_date']))) : '' ?>" placeholder="<?= e(date('m/d/Y')) ?> (today)"></label>
		<label>Valid until <input type="text" name="valid_until" value="<?= $d['valid_until'] ? e(date('m/d/Y', (int) strtotime($d['valid_until']))) : '' ?>" placeholder="<?= e(date('m/d/Y', (int) strtotime($pUntil))) ?>">
			<small class="hint">Blank: <?= (int) Presales::num('proposal_valid_days') ?> days after the proposal date. At most <?= (int) Presales::num('proposal_valid_max') ?>.</small>
		</label>
		<label>Addressed to
			<select name="contact_id">
				<option value="">The customer<?= count($contacts) === 1 ? ' (' . e($contacts[0]['name']) . ')' : '' ?></option>
				<?php foreach ($contacts as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $d['contact_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['role'] ? ' (' . e($c['role']) . ')' : '' ?></option><?php endforeach; ?>
			</select>
			<small class="hint">A business prints "Attn:" the contact.</small>
		</label>
		<label>Leads with
			<select name="production_model">
				<option value="">Rates page default (<?= e(Production::MODELS[Presales::setting('production_model')] ?? '') ?>)</option>
				<?php foreach (Production::MODELS as $k => $label): ?><option value="<?= e($k) ?>" <?= (string) $d['production_model'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
			</select>
			<small class="hint">The other estimate shows beside it for comparison.</small>
		</label>
		<label class="span-all">Notes for the customer
			<textarea name="customer_notes" rows="3" maxlength="<?= Designs::NOTES_MAX ?>" data-autogrow><?= e((string) $d['customer_notes']) ?></textarea>
			<small class="hint">The one place for customer notes; prints on the System Summary page. At most <?= Designs::NOTES_MAX ?> characters. The Notes field below stays internal.</small>
		</label>
	</div>
	<h3 class="mt-sm">Financing estimate</h3>
	<p class="hint">Optional: a monthly payment beside the cash price. Not part of the savings.</p>
	<div class="grid-form">
		<label>Down payment ($) <input type="text" name="finance_down" value="<?= e($f($d['finance_down'], 2)) ?>" inputmode="decimal"></label>
		<label>Rate (APR %) <input type="text" name="finance_rate" value="<?= e($f($d['finance_rate'], 3)) ?>" inputmode="decimal"></label>
		<label>Term (years) <input type="text" name="finance_years" value="<?= e((string) ($d['finance_years'] ?? '')) ?>" inputmode="numeric"></label>
	</div>
	<div class="guarantee-box mt-sm">
		<?php if ($isAdmin): ?>
			<input type="hidden" name="guarantee_present" value="1">
			<label class="boxwrap"><input type="checkbox" name="guarantee_on" value="1" <?= $d['guarantee_on'] ? 'checked' : '' ?>> Production guarantee: <?= e(Presales::setting('guarantee_pct')) ?>% of the PVWatts estimate over <?= e(Presales::setting('guarantee_years')) ?> years, $<?= e(Presales::setting('guarantee_rate')) ?> per kWh short</label>
		<?php else: ?>
			<span class="small">Production guarantee: <strong><?= $d['guarantee_on'] ? 'included' : 'not included' ?></strong>. Only an admin changes it.</span>
		<?php endif; ?>
	</div>
</section>
