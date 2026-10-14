<?php
use App\Csrf;
use App\Presales;
use App\Projects;

/**
 * The build sheet editor. Numbers come from App\Pricing; presales.js re-runs the calculation
 * as fields change (POST .../calc, nothing saved) and Save stores the build sheet.
 *
 * @var array $o               opportunity
 * @var array $d               design row
 * @var array $lines           saved lines (or as typed after a failed save)
 * @var array $arrays
 * @var array $calc            Pricing::calculate() + warranty_summary
 * @var array $flags           Designs::priceFlags()
 * @var array $catalog         active items by category
 * @var array $commissionRates
 * @var array $templates       payment schedules
 * @var array $depositOptions  allowed deposits for this customer type and size
 * @var string $depositClass   residential | commercial (Non-Residential)
 * @var array $siblings        the opportunity's build sheets
 */
$base = '/presales/' . (int) $o['id'] . '/designs/' . (int) $d['id'];
$sel = static fn ($a, $b) => (string) $a === (string) $b && (string) $a !== '' ? 'selected' : '';
$m = static fn ($v) => Presales::money($v);
$pf = static fn ($v, $dec = 4) => Presales::field($v === null ? null : (float) $v, $dec);
$deg = static fn (float $v, int $dec = 1) => rtrim(rtrim(number_format($v, $dec), '0'), '.');
$steep = (float) $d['steep_deg'];
$classLabel = Presales::CLASS_LABELS[$depositClass];

// Lines by category; the common categories always show a row, the rest open from a button
$byCat = [];
foreach ($lines as $i => $l) {
	if ($l['category'] !== 'extra') {
		$byCat[$l['category']][] = $i;
	}
}
$extras = array_values(array_filter($lines, static fn ($l) => $l['category'] === 'extra'));
$manualRules = array_map('intval', json_decode((string) ($d['contingency_manual'] ?? ''), true) ?: []);
$warrantyItems = $catalog['warranty'] ?? [];
$commissionKnown = false;
foreach ($commissionRates as $cr) {
	$commissionKnown = $commissionKnown || abs((float) $cr['rate'] - (float) $d['commission_rate']) < 0.000001;
}
// Compass (azimuth, north up) and the tilt drawn as a roof line; red past the steep threshold
$orient = static function (?int $az, ?float $tilt, string $mount) use ($steep, $deg): string {
	$h = '<svg class="compass" viewBox="0 0 30 30" width="30" height="30" aria-hidden="true"><circle cx="15" cy="15" r="13"/>'
		. '<text x="15" y="8.5">N</text>';
	if ($az !== null && $mount !== 'tracker') {
		$h .= '<line class="compass-needle" x1="15" y1="15" x2="' . round(15 + 11 * sin(deg2rad($az)), 2) . '" y2="' . round(15 - 11 * cos(deg2rad($az)), 2) . '"/>';
	}
	$h .= '</svg>';
	$t = $tilt === null ? 0.0 : max(0.0, min(90.0, $tilt));
	$cls = $tilt !== null && $tilt > $steep ? ' is-steep' : '';
	$h .= '<svg class="pitch' . $cls . '" viewBox="0 0 44 30" width="40" height="27" aria-hidden="true">'
		. '<line class="pitch-base" x1="2" y1="26" x2="42" y2="26"/>'
		. '<line class="pitch-roof" x1="4" y1="26" x2="' . round(4 + 36 * cos(deg2rad($t)), 2) . '" y2="' . round(max(1, 26 - 36 * sin(deg2rad($t))), 2) . '"/></svg>';
	$label = [];
	if ($mount === 'tracker') {
		$label[] = 'tracks';
	} else {
		if ($az !== null) {
			$label[] = $az . '° ' . Projects::compass($az);
		}
		if ($tilt !== null) {
			$label[] = $deg($tilt) . '° (' . $deg(12 * tan(deg2rad(min($tilt, 89)))) . '/12)';
		}
	}
	return $h . '<span class="pitch-label">' . e(implode(' · ', $label)) . '</span>';
};
$lineFlag = static function (?array $f): string {
	if (!$f) {
		return '';
	}
	$h = '';
	if ($f['retired']) {
		$h .= '<span class="chip chip-red">Retired from the catalog</span>';
	} elseif ($f['changed']) {
		$h .= '<span class="chip chip-red" title="The catalog price is different now. This line keeps the price it was built with.">Catalog price changed</span>';
	}
	if ($f['stale'] && !$f['retired']) {
		$date = $f['catalog']['price_updated_at'] ?? null;
		$h .= '<span class="chip chip-orange" title="The catalog price was last checked longer ago than the stale setting allows; it may still be right.">Check price: ' . ($date ? e(fmt_date($date)) : 'never dated') . '</span>';
	}
	return $h;
};
$always = ['modules', 'inverters', 'mlpe', 'racking', 'bos', 'permits', 'interconnection'];
$folded = [];
?>
<div class="project-head">
	<div>
		<a href="/presales/<?= (int) $o['id'] ?>" class="back">&larr; <?= e($o['name']) ?></a>
		<div class="page-kicker">Build sheet</div>
		<h1><?= e($d['name']) ?></h1>
		<div class="badges">
			<span class="chip chip-blue"><?= e($classLabel) ?></span>
			<?php if ($o['customer_type'] && $o['customer_type'] !== 'residential' && $o['customer_type'] !== 'commercial'): ?><span class="chip"><?= e(Projects::CUSTOMER_TYPES[$o['customer_type']] ?? '') ?></span><?php endif; ?>
			<?php if ($o['is_agricultural']): ?><span class="chip">Agricultural</span><?php endif; ?>
		</div>
		<?php if (count($siblings) > 1): ?>
			<nav class="tabs design-tabs" aria-label="Build sheets">
				<?php foreach ($siblings as $s): ?>
					<a href="/presales/<?= (int) $o['id'] ?>/designs/<?= (int) $s['id'] ?>" class="tab <?= (int) $s['id'] === (int) $d['id'] ? 'active' : '' ?>"><?= e($s['name']) ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
	</div>
	<div class="head-actions">
		<a href="<?= $base ?>/summary" class="btn btn-secondary" title="Everything on one sheet, to read or print (saved values)">Summary</a>
		<form method="post" action="<?= $base ?>/duplicate">
			<?= Csrf::field() ?>
			<button class="btn btn-ghost" title="Make a copy to try another design; this one stays as it is">Copy</button>
		</form>
		<form method="post" action="<?= $base ?>/delete" data-confirm="Delete this build sheet? This can't be undone.">
			<?= Csrf::field() ?>
			<button class="btn btn-ghost">Delete</button>
		</form>
	</div>
</div>

<?php if ($flags['changed'] || $flags['retired']): ?>
	<div class="flash flash-error design-banner">
		<div><strong>Catalog price changed:</strong> <?= (int) ($flags['changed'] + $flags['retired']) ?> line<?= $flags['changed'] + $flags['retired'] > 1 ? 's are' : ' is' ?> priced differently in the catalog now. This build sheet keeps the prices it was built with.</div>
		<form method="post" action="<?= $base ?>/reprice">
			<?= Csrf::field() ?>
			<button class="btn btn-secondary btn-small" title="Makes a copy priced from today's catalog; this one stays as it is">Copy at today's prices</button>
		</form>
	</div>
<?php endif; ?>
<?php if ($flags['stale']): ?>
	<div class="flash flash-warn design-banner">
		<div><strong>Check price:</strong> <?= (int) $flags['stale'] ?> line<?= $flags['stale'] > 1 ? 's use catalog prices' : ' uses a catalog price' ?> last checked more than <?= (int) Presales::num('stale_days') ?> days ago. Confirm or update <?= $flags['stale'] > 1 ? 'them' : 'it' ?> in the <a href="/presales/catalog?show=stale">catalog</a> before quoting.</div>
	</div>
<?php endif; ?>

<form method="post" action="<?= $base ?>" id="design-form" class="design-layout" data-calc-url="<?= $base ?>/calc" data-steep="<?= e((string) $steep) ?>" novalidate>
	<?= Csrf::field() ?>

	<aside class="design-summary" aria-label="Totals">
		<div class="card design-totals">
			<div class="dt-price-label">Final retail price</div>
			<div class="dt-price">
				<span data-v="price"><?= e($m($calc['price'])) ?></span>
				<button type="button" class="copy-btn" data-copy-from="price-plain" title="Copy the price" aria-label="Copy the price"><svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><rect x="5" y="5" width="9" height="9" rx="1.5"/><path d="M3 11V3.5A1.5 1.5 0 0 1 4.5 2H11"/></svg></button>
				<input type="hidden" id="price-plain" value="<?= e(number_format($calc['price'], 0, '.', '')) ?>" data-v-value="price_plain">
			</div>
			<div class="dt-metrics">
				<div><span data-v="dc_kw"><?= e(number_format($calc['dc_kw'], 2)) ?> kW</span><small>DC</small></div>
				<div><span data-v="ac_kw"><?= e(number_format($calc['ac_kw'], 2)) ?> kW</span><small>AC</small></div>
				<div><span data-v="ratio"><?= $calc['ratio'] === null ? '-' : e(number_format($calc['ratio'], 2)) ?></span><small>DC/AC</small></div>
				<div><span data-v="ppw"><?= $calc['price_per_watt'] === null ? '-' : e(Presales::perWatt($calc['price_per_watt'])) ?></span><small>Retail $/W</small></div>
			</div>
			<table class="dt-table">
				<tbody>
				<tr><th>Equipment and materials</th><td data-v="equipment_cost"><?= e($m($calc['equipment_cost'])) ?></td></tr>
				<tr class="dt-sub"><th>includes sales tax</th><td data-v="tax_total"><?= e($m($calc['tax_total'])) ?></td></tr>
				<tr><th>Warranty program</th><td data-v="warranty_cost"><?= e($m($calc['warranty_cost'])) ?></td></tr>
				<tr><th>Labor</th><td data-v="labor"><?= e($m($calc['labor'])) ?></td></tr>
				<tr><th>Site visit</th><td data-v="site_visit"><?= e($m($calc['site_visit'])) ?></td></tr>
				<tr><th>Travel (lodging, per diem)</th><td data-v="expenses"><?= e($m($calc['overnight'] + $calc['per_diem'])) ?></td></tr>
				<tr><th>Contingency <span class="muted" data-v="contingency_pct"><?= $calc['contingency_pct'] === null ? '' : e(Presales::pct($calc['contingency_pct'])) ?></span></th><td data-v="contingency"><?= e($m($calc['contingency'])) ?></td></tr>
				<tr class="dt-total"><th>Internal cost</th><td data-v="cost_total"><?= e($m($calc['cost_total'])) ?></td></tr>
				<tr class="dt-sub"><th>Cost $/W</th><td data-v="cpw"><?= $calc['cost_per_watt'] === null ? '-' : e(Presales::perWatt($calc['cost_per_watt'])) ?></td></tr>
				<tr><th>Markup</th><td data-v="markup_amount"><?= e($m($calc['markup_amount'])) ?></td></tr>
				<tr><th>Commission</th><td data-v="commission"><?= e($m($calc['commission'])) ?></td></tr>
				<tr><th>Extras without markup</th><td data-v="extras_total"><?= e($m($calc['extras_total'])) ?></td></tr>
				<tr><th>Rounded up</th><td data-v="rounding"><?= e($m($calc['rounding'])) ?></td></tr>
				<tr class="dt-total"><th>Retail price</th><td data-v="price"><?= e($m($calc['price'])) ?></td></tr>
				</tbody>
			</table>
			<div class="dt-margin">
				<div class="dt-markup">Markup <strong data-v="markup_pct"><?= e(Presales::pct((float) $d['markup'])) ?></strong></div>
				<div class="dt-margin-main"><span data-v="gross_margin_pct"><?= $calc['gross_margin_pct'] === null ? '-' : e(Presales::pct($calc['gross_margin_pct'])) ?></span> <small>gross margin</small> <span class="dt-margin-amt" data-v="gross_margin"><?= e($m($calc['gross_margin'])) ?></span></div>
				<div class="small">If the contingency isn't used: <strong data-v="margin_if_unused_pct"><?= $calc['margin_if_unused_pct'] === null ? '-' : e(Presales::pct($calc['margin_if_unused_pct'])) ?></strong></div>
				<div class="small" title="Commission plus the site visit">Salesperson total: <strong data-v="to_salesperson"><?= e($m($calc['to_salesperson'])) ?></strong></div>
				<div class="small">Domestic content: <strong data-v="domestic_short"><?= $calc['domestic_content_pct'] === null ? 'unknown' : e(Presales::pct($calc['domestic_content_pct'])) ?></strong></div>
				<p class="hint mt-xs dt-explain">Gross margin = (retail price - extras without markup) - internal cost (including contingency) - commission, as a share of the retail price less extras. Extras are pass-through, so they are left out.</p>
			</div>
			<p class="dt-error" id="calc-error" hidden></p>
			<button class="btn btn-primary btn-block">Save build sheet</button>
			<p class="hint mt-xs center" id="calc-status">Totals update as you type. Save to keep changes.</p>
		</div>
	</aside>

	<div class="design-main">
		<section class="card band">
			<h2>Setup</h2>
			<div class="grid-form">
				<label class="span-2">Build sheet name
					<input type="text" name="name" value="<?= e($d['name']) ?>" maxlength="80">
				</label>
				<label>Install type
					<select name="install_type" id="install-type">
						<option value="">Choose</option>
						<?php foreach (Projects::INSTALL_TYPES as $k => $label): ?>
							<option value="<?= $k ?>" <?= $sel($d['install_type'], $k) ?>><?= e($label) ?></option>
						<?php endforeach; ?>
					</select>
					<small class="hint">Limits racking and foundations to ones that fit.</small>
				</label>
				<label>Internet
					<select name="inverter_internet">
						<option value="">Choose</option>
						<?php foreach (Projects::INVERTER_INTERNET as $k => $label): ?>
							<option value="<?= $k ?>" <?= $sel($d['inverter_internet'], $k) ?>><?= e($label) ?></option>
						<?php endforeach; ?>
					</select>
					<small class="hint">A cellular router or modem is a Monitoring line.</small>
				</label>
				<label class="span-2">Warranty program
					<select name="warranty_item_id">
						<option value="">None</option>
						<?php foreach ($warrantyItems as $w): ?>
							<option value="<?= (int) $w['id'] ?>" <?= $sel($d['warranty_item_id'], $w['id']) ?>><?= e($w['name']) ?> (<?= e(Presales::unit((float) $w['price'])) ?>/W)</option>
						<?php endforeach; ?>
					</select>
					<?php if (!empty($flags['warranty']['changed'])): ?><small class="hint text-red">The catalog price for this program changed since this was built.</small><?php endif; ?>
					<small class="hint">SolarInsure programs add each battery's SolarInsure cost.</small>
				</label>
				<label>Markup on cost (%)
					<input type="text" name="markup" value="<?= e($pf($d['markup'] * 100, 2)) ?>" inputmode="decimal">
				</label>
				<label>Commission
					<select name="commission_rate">
						<?php if (!$commissionKnown): ?><option value="<?= e($pf($d['commission_rate'] * 100, 2)) ?>" selected><?= e($pf($d['commission_rate'] * 100, 2)) ?>% (saved)</option><?php endif; ?>
						<?php foreach ($commissionRates as $cr): ?>
							<option value="<?= e($pf($cr['rate'] * 100, 2)) ?>" <?= abs((float) $cr['rate'] - (float) $d['commission_rate']) < 0.000001 ? 'selected' : '' ?>><?= e($pf($cr['rate'] * 100, 2)) ?>%<?= (float) $cr['step_down'] > 0 ? ' (+' . e($m((float) $cr['step_down'])) . ')' : '' ?></option>
						<?php endforeach; ?>
					</select>
					<small class="hint">Applied as <?= e(Presales::pct((float) $d['commission_adjusted'], 2)) ?> of the marked-up cost, as the old sheet did.</small>
				</label>
				<div class="checkfield">
					<span class="field-label">Tax exempt</span>
					<label class="boxwrap"><input type="checkbox" name="tax_exempt" value="1" id="tax-exempt" <?= $d['tax_exempt'] ? 'checked' : '' ?>> Customer is tax exempt</label>
					<small class="hint">Major equipment goes untaxed; BOS is still taxed at the rate.</small>
				</div>
				<label>Sales tax (%)
					<input type="text" name="tax_rate" id="tax-rate" value="<?= e($pf($d['tax_rate'] * 100, 3)) ?>" inputmode="decimal" <?= $d['tax_exempt'] ? 'readonly' : '' ?>>
				</label>
				<label class="span-2">Tax note
					<input type="text" name="tax_note" id="tax-note" value="<?= e((string) $d['tax_note']) ?>" placeholder="PA Tax" maxlength="120">
					<small class="hint">Say why when the rate isn't the usual one (another state, Philadelphia...).</small>
				</label>
			</div>
		</section>

		<section class="card band">
			<h2>Equipment and materials</h2>
			<div class="flash flash-warn design-checks" id="design-checks" <?= $calc['checks'] ? '' : 'hidden' ?>>
				<ul><?php foreach ($calc['checks'] as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul>
			</div>
			<p class="hint">Each line keeps the price it was picked at. Per-watt and per-module quantities fill in on their own. Retail is the line's share of the final price. <?= e($classLabel) ?> job: only <?= e(strtolower($classLabel)) ?> BOS, structural, permit and interconnection items are offered.</p>
			<?php foreach (Presales::CATEGORIES as $cat => [$catLabel, $unitLabel]): if ($cat === 'warranty') { continue; } $rows = $byCat[$cat] ?? [null]; $items = $catalog[$cat] ?? [];
				$fold = !isset($byCat[$cat]) && !in_array($cat, $always, true);
				if ($fold) { $folded[$cat] = $catLabel; }
				$ct = $calc['categories'][$cat] ?? null; ?>
				<div class="pl-block" data-cat="<?= e($cat) ?>" <?= $fold ? 'hidden' : '' ?>>
					<div class="pl-head"><span class="pl-cat"><?= e($catLabel) ?></span><span>Qty<?= $unitLabel === 'ft' ? ' (ft)' : '' ?></span><span class="num">Unit</span><span class="num">Tax</span><span class="num">Cost</span><span class="num">Retail</span><span class="num">Retail $/W</span><span></span></div>
					<div class="pl-rows" data-rows>
						<?php foreach ($rows as $k => $idx): $l = $idx === null ? null : $lines[$idx]; $c = $idx === null ? null : ($calc['lines'][$idx] ?? null); $fl = $idx === null ? null : ($flags['lines'][$idx] ?? null);
							$isCustom = $l && !$l['catalog_item_id'];
							$itemId = $l['catalog_item_id'] ?? '';
							$current = null;
							foreach ($items as $it) { if ((int) $it['id'] === (int) $itemId) { $current = $it; } }
							$rule = $l['qty_rule'] ?? ($current['qty_rule'] ?? 'manual');
							$extCost = $l['ext_warranty_cost'] ?? null;
							$notes = $current['notes'] ?? ($fl['catalog']['notes'] ?? null);
							$key = $l ? 'l' . (int) ($l['id'] ?? $idx) : 'n-' . $cat . '-' . $k;
						?>
							<div class="pl-row<?= $fl && ($fl['changed'] || $fl['retired']) ? ' has-changed' : ($fl && $fl['stale'] ? ' has-flag' : '') ?>" data-key="<?= e($key) ?>">
								<input type="hidden" name="lines[<?= e($cat) ?>][<?= $k ?>][id]" value="<?= $l ? (int) ($l['id'] ?? 0) : '' ?>">
								<input type="hidden" name="lines[<?= e($cat) ?>][<?= $k ?>][key]" value="<?= e($key) ?>" class="pl-key">
								<div class="pl-item">
									<select name="lines[<?= e($cat) ?>][<?= $k ?>][item]" class="pl-select" aria-label="<?= e($catLabel) ?> item">
										<option value="">Choose</option>
										<?php if ($l && $l['catalog_item_id'] && !$current): ?>
											<option value="<?= (int) $l['catalog_item_id'] ?>" selected data-rule="<?= e($rule) ?>"><?= e($l['name']) ?> (retired)</option>
										<?php endif; ?>
										<?php foreach ($items as $it):
											$isSel = (int) $it['id'] === (int) $itemId;
											if (!$isSel && $it['segment'] !== null && $it['segment'] !== $depositClass) { continue; }
											$stale = Presales::isStale($it['price_updated_at']); ?>
											<option value="<?= (int) $it['id'] ?>" <?= $isSel ? 'selected' : '' ?> data-rule="<?= e($it['qty_rule']) ?>"<?= $it['mounts'] ? ' data-mounts="' . e($it['mounts']) . '"' : '' ?><?= $it['ext_warranty_cost'] !== null ? ' data-ext="' . e(Presales::money((float) $it['ext_warranty_cost'])) . ($it['ext_warranty_years'] ? ', ' . (int) $it['ext_warranty_years'] . ' yr' : '') . '"' : '' ?> data-notes="<?= e((string) $it['notes']) ?>"><?= e($it['name']) ?> &middot; <?= e(Presales::unit((float) $it['price'] + (float) $it['shipping'])) ?><?= $it['qty_rule'] === 'dc_watts' ? '/W' : ($unitLabel === 'ft' ? '/ft' : '') ?><?= $stale ? ' (check price)' : '' ?></option>
										<?php endforeach; ?>
										<option value="custom" <?= $isCustom ? 'selected' : '' ?> data-rule="manual">Custom line...</option>
									</select>
									<div class="pl-custom" <?= $isCustom ? '' : 'hidden' ?>>
										<input type="text" name="lines[<?= e($cat) ?>][<?= $k ?>][name]" value="<?= e($isCustom ? $l['name'] : '') ?>" placeholder="Description" aria-label="Custom line description">
										<input type="text" name="lines[<?= e($cat) ?>][<?= $k ?>][price]" value="<?= e($isCustom ? $pf((float) $l['price']) : '') ?>" placeholder="Unit cost" inputmode="decimal" aria-label="Unit cost" class="input-short">
										<?php if ($cat === 'modules'): ?><input type="text" name="lines[<?= e($cat) ?>][<?= $k ?>][watts]" value="<?= e($isCustom ? $pf($l['watts'] === null ? null : (float) $l['watts']) : '') ?>" placeholder="Watts" inputmode="decimal" aria-label="Watts" class="input-short"><?php endif; ?>
										<select name="lines[<?= e($cat) ?>][<?= $k ?>][tax_class]" aria-label="Tax">
											<?php foreach (Presales::TAX_CLASSES as $tk => $tl): ?>
												<option value="<?= $tk ?>" <?= $sel($isCustom ? $l['tax_class'] : ($cat === 'permits' || $cat === 'interconnection' || $cat === 'structural' ? 'none' : 'bos'), $tk) ?>><?= e($tl) ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<label class="pl-ext" <?= $extCost !== null || ($current && $current['ext_warranty_cost'] !== null) ? '' : 'hidden' ?>>
										<input type="checkbox" name="lines[<?= e($cat) ?>][<?= $k ?>][ext]" value="1" <?= !empty($l['ext_warranty']) ? 'checked' : '' ?>>
										Extended warranty <span class="pl-ext-label"><?= $extCost !== null ? '(+' . e($m((float) $extCost)) . ($l['ext_warranty_years'] ? ', ' . (int) $l['ext_warranty_years'] . ' yr' : '') . ')' : '' ?></span>
									</label>
									<details class="pl-notes" <?= $notes ? '' : 'hidden' ?>>
										<summary>Details</summary>
										<p class="pl-notes-text"><?= nl2br(e((string) $notes)) ?></p>
									</details>
									<span class="pl-flags"><?= $lineFlag($fl) ?></span>
								</div>
								<div class="pl-qty">
									<input type="text" name="lines[<?= e($cat) ?>][<?= $k ?>][qty]" value="<?= e($l && $l['qty'] !== null ? $pf((float) $l['qty'], 2) : '') ?>" inputmode="decimal" aria-label="Quantity" class="pl-qty-input" <?= $rule === 'manual' ? '' : 'hidden' ?>>
									<span class="pl-qty-auto muted" <?= $rule === 'manual' ? 'hidden' : '' ?> title="Automatic quantity"><span data-l="qty"><?= $c ? e(Presales::field($c['qty_resolved'], 2)) : '' ?></span> <?= $rule === 'dc_watts' ? 'W' : 'auto' ?></span>
								</div>
								<span class="num" data-l="unit"><?= $c ? e(Presales::unit($c['unit_total'])) : '' ?></span>
								<span class="num muted" data-l="tax"><?= $c ? ($c['tax'] > 0 ? e($m($c['tax'])) : '-') : '' ?></span>
								<span class="num" data-l="cost"><?= $c ? e($m($c['cost'])) : '' ?></span>
								<span class="num" data-l="retail"><?= $c ? e($m($c['retail'])) : '' ?></span>
								<span class="num" data-l="rpw"><?= $c && $c['retail_per_watt'] !== null ? e(Presales::perWatt($c['retail_per_watt'])) : '' ?></span>
								<button type="button" class="btn btn-ghost btn-small row-del" title="Remove">&times;</button>
							</div>
						<?php endforeach; ?>
						<button type="button" class="btn btn-ghost btn-small row-add">+ <?= e($catLabel) ?> line</button>
					</div>
					<div class="pl-total" data-cat-total="<?= e($cat) ?>" <?= $ct ? '' : 'hidden' ?>>
						<span><?= e($catLabel) ?> total</span><span></span><span></span>
						<span class="num muted" data-t="tax"><?= $ct ? ($ct['tax'] > 0 ? e($m($ct['tax'])) : '-') : '' ?></span>
						<span class="num" data-t="cost"><?= $ct ? e($m($ct['cost'])) : '' ?></span>
						<span class="num" data-t="retail"><?= $ct ? e($m($ct['retail'])) : '' ?></span>
						<span></span><span></span>
					</div>
				</div>
			<?php endforeach; ?>
			<?php if ($folded): ?>
				<div class="pl-more">
					<span class="field-label">Add a line for</span>
					<?php foreach ($folded as $cat => $label): ?>
						<button type="button" class="btn btn-ghost btn-small" data-show-cat="<?= e($cat) ?>">+ <?= e($label) ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<dl class="kv content-flags">
				<dt>Domestic content</dt><dd data-v="domestic"><?= e(App\Controllers\DesignController::domesticText($calc)) ?></dd>
				<dt>FEOC</dt><dd data-v="feoc"><?= e(App\Controllers\DesignController::feocText($calc)) ?></dd>
			</dl>
			<p class="hint">From the Domestic content % and FEOC fields on catalog items. A flag, not a tax opinion: whether the job qualifies for the credit or the domestic content bonus also depends on the project type and dates.</p>
		</section>

		<section class="card band">
			<div class="card-head card-head-plain band-head">
				<h2>Arrays</h2>
				<span class="small" id="arrays-count"><?= $arrays ? (int) $calc['arrays_panels'] . ' panels on arrays, ' . (int) $calc['panels'] . ' modules' : '' ?></span>
			</div>
			<div class="eq eq-arrays" data-rows>
				<div class="eq-head"><span>Array / face</span><span>Mount</span><span>Roof material</span><span>Azimuth</span><span>Tilt</span><span>Panels</span><span>Orientation</span><span></span></div>
				<?php foreach ($arrays ?: [['mount' => $d['install_type'] === 'ground' ? 'ground' : ($d['install_type'] === 'tracker' ? 'tracker' : 'roof')]] as $i => $a): $tilt = isset($a['tilt']) && $a['tilt'] !== null ? (float) $a['tilt'] : null; $az = isset($a['azimuth']) && $a['azimuth'] !== null && $a['azimuth'] !== '' ? (int) $a['azimuth'] : null; ?>
					<div class="eq-row array-row<?= $tilt !== null && $tilt > $steep ? ' is-steep' : '' ?>" data-mount="<?= e($a['mount'] ?? 'roof') ?>">
						<input type="text" name="arrays[<?= $i ?>][name]" value="<?= e($a['name'] ?? '') ?>" placeholder="e.g. South roof, Barn" aria-label="Array or face">
						<select name="arrays[<?= $i ?>][mount]" aria-label="Mount" class="array-mount">
							<?php foreach (Presales::MOUNTS as $k => $label): ?>
								<option value="<?= $k ?>" <?= $sel($a['mount'] ?? 'roof', $k) ?>><?= e($label) ?></option>
							<?php endforeach; ?>
						</select>
						<select name="arrays[<?= $i ?>][material]" aria-label="Roof material" class="array-roof-only">
							<option value="">Material</option>
							<?php foreach (Projects::ROOF_MATERIALS as $k => $label): ?>
								<option value="<?= $k ?>" <?= $sel($a['material'] ?? '', $k) ?>><?= e($label) ?></option>
							<?php endforeach; ?>
						</select>
						<input type="number" name="arrays[<?= $i ?>][azimuth]" value="<?= e($a['azimuth'] ?? '') ?>" min="0" max="359" placeholder="180" aria-label="Azimuth in degrees" class="array-fixed-only array-az">
						<input type="text" name="arrays[<?= $i ?>][tilt]" value="<?= e($tilt === null ? '' : $deg($tilt)) ?>" placeholder="6/12" aria-label="Tilt in degrees or pitch" class="array-fixed-only array-tilt">
						<input type="number" name="arrays[<?= $i ?>][panels]" value="<?= e($a['panels'] ?? '') ?>" min="0" placeholder="0" aria-label="Panels on this array">
						<span class="array-pitch"><?= $orient($az, $tilt, (string) ($a['mount'] ?? 'roof')) ?></span>
						<button type="button" class="btn btn-ghost btn-small row-del" title="Remove">&times;</button>
					</div>
				<?php endforeach; ?>
				<button type="button" class="btn btn-ghost btn-small row-add">+ Array</button>
			</div>
			<p class="hint mt-xs">Azimuth is degrees from true north (180 = south). Tilt takes degrees or a roof pitch like 6/12; over <?= e($deg($steep)) ?>° shows red. Trackers have no fixed tilt or azimuth. <span class="text-red" id="panels-mismatch" <?= $calc['panels_mismatch'] ? '' : 'hidden' ?>>Panels on arrays don't match the module count.</span></p>
		</section>

		<section class="card band">
			<h2>Labor, site visit and travel</h2>
			<div class="grid-form">
				<div class="checkfield">
					<span class="field-label">Labor priced</span>
					<div class="boxrow">
						<label class="boxwrap"><input type="radio" name="labor_mode" value="per_watt" <?= $d['labor_mode'] !== 'total' ? 'checked' : '' ?>> Per watt</label>
						<label class="boxwrap"><input type="radio" name="labor_mode" value="total" <?= $d['labor_mode'] === 'total' ? 'checked' : '' ?>> Total $</label>
					</div>
				</div>
				<label>Labor price
					<input type="text" name="labor_value" value="<?= e($pf((float) $d['labor_value'])) ?>" inputmode="decimal" placeholder="0.00">
					<small class="hint"><span data-v="labor"><?= e($m($calc['labor'])) ?></span> &middot; <span data-v="labor_pw"><?= $calc['dc_watts'] > 0 ? e(Presales::perWatt($calc['labor'] / $calc['dc_watts'])) : '-' ?></span>/W &middot; <span data-v="man_hours"><?= $calc['man_hours'] === null ? '-' : e(number_format($calc['man_hours'], 1)) . ' man-hours' ?></span></small>
				</label>
				<label>Labor rate ($/man-hour)
					<input type="text" name="labor_rate" value="<?= e($pf((float) $d['labor_rate'], 2)) ?>" inputmode="decimal">
					<?php if (Presales::setting('labor_guideline') !== ''): ?><small class="hint"><?= e(Presales::setting('labor_guideline')) ?></small><?php endif; ?>
				</label>
				<label>Site visit
					<input type="text" name="site_visit" value="<?= e($pf((float) $d['site_visit'], 2)) ?>" inputmode="decimal">
					<small class="hint">Paid to the salesperson on top of commission.</small>
				</label>
				<label>Nights away
					<input type="number" name="trip_nights" value="<?= (int) $d['trip_nights'] ?>" min="0" inputmode="numeric">
				</label>
				<label>Crew traveling
					<input type="number" name="trip_crew" value="<?= (int) $d['trip_crew'] ?>" min="0" inputmode="numeric">
				</label>
				<label>Lodging ($/person/night)
					<input type="text" name="lodging_rate" value="<?= e($pf((float) $d['lodging_rate'], 2)) ?>" inputmode="decimal">
					<small class="hint">Lodging <strong data-v="overnight"><?= e($m($calc['overnight'])) ?></strong></small>
				</label>
				<label>Per diem ($/person/day)
					<input type="text" name="per_diem_rate" value="<?= e($pf((float) $d['per_diem_rate'], 2)) ?>" inputmode="decimal">
					<small class="hint">Per diem <strong data-v="per_diem"><?= e($m($calc['per_diem'])) ?></strong>, counted for nights + 1 days</small>
				</label>
			</div>
		</section>

		<section class="card band">
			<h2>Extras without markup</h2>
			<p class="hint">Pass-through amounts shown to the customer at cost, such as a utility transformer upgrade. No markup, no commission, no tax, and left out of the margin.</p>
			<div class="eq eq-extras" data-rows>
				<?php foreach ($extras ?: [[]] as $i => $x): ?>
					<div class="eq-row">
						<input type="text" name="extras[<?= $i ?>][name]" value="<?= e($x['name'] ?? '') ?>" placeholder="Description" aria-label="Extra description">
						<input type="text" name="extras[<?= $i ?>][amount]" value="<?= e(isset($x['price']) ? $pf((float) $x['price'], 2) : '') ?>" placeholder="Amount" inputmode="decimal" aria-label="Amount">
						<button type="button" class="btn btn-ghost btn-small row-del" title="Remove">&times;</button>
					</div>
				<?php endforeach; ?>
				<button type="button" class="btn btn-ghost btn-small row-add">+ Extra</button>
			</div>
		</section>

		<section class="card band">
			<h2>Contingency</h2>
			<p class="hint">A cost of work, figured on internal cost and then marked up like any other cost, so the margin holds if it gets spent and grows if it doesn't. Rules switch on from the build sheet itself; some can be checked by hand.</p>
			<table class="table table-rules">
				<tbody>
				<?php foreach ($calc['contingency_rules'] as $r): ?>
					<tr data-rule="<?= (int) $r['id'] ?>">
						<td class="check-col">
							<input type="checkbox" name="contingency_manual[]" value="<?= (int) $r['id'] ?>" aria-label="<?= e($r['name']) ?>"
								<?= $r['on'] ? 'checked' : '' ?> <?= $r['auto'] || !$r['manual_ok'] ? 'disabled' : '' ?> data-manual-ok="<?= $r['manual_ok'] ? '1' : '' ?>" data-was="<?= in_array((int) $r['id'], $manualRules, true) ? '1' : '' ?>">
						</td>
						<td><strong><?= e($r['name']) ?></strong><div class="muted small"><?= e((string) $r['reason']) ?></div></td>
						<td class="num nowrap">+<?= e($deg((float) $r['pct'], 2)) ?>%</td>
						<td class="small rule-why"><?= e($r['why'] ?? ($r['manual_ok'] ? 'Check if it applies' : '')) ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if (!$calc['contingency_rules']): ?><tr><td class="muted">No contingency rules yet (Rates page).</td></tr><?php endif; ?>
				</tbody>
			</table>
			<dl class="kv mt-xs">
				<dt>Rules add up to</dt><dd><span data-v="contingency_rule_pct"><?= e($deg($calc['contingency_rule_pct'], 2)) ?>%</span>, capped at <?= e($deg((float) $d['contingency_cap'], 2)) ?>% <span class="chip chip-orange" id="cont-capped" <?= $calc['contingency_capped'] ? '' : 'hidden' ?>>Capped</span></dd>
				<dt>By the rules</dt><dd><span data-v="contingency_rule_amount"><?= e($m($calc['contingency_rule_amount'])) ?></span> <span class="muted small">(at least <?= e($m((float) $d['contingency_floor'])) ?>)</span> <span class="chip chip-blue" id="cont-floor" <?= $calc['contingency_floor_applied'] ? '' : 'hidden' ?>>Minimum applies</span></dd>
				<dt>On this build sheet</dt><dd><strong data-v="contingency"><?= e($m($calc['contingency'])) ?></strong> (<span data-v="contingency_pct"><?= $calc['contingency_pct'] === null ? '-' : e(Presales::pct($calc['contingency_pct'])) ?></span> of cost)</dd>
			</dl>
			<div class="grid-form mt-xs">
				<label>Override (%)
					<input type="text" name="contingency_override" value="<?= e($d['contingency_override'] === null ? '' : $pf((float) $d['contingency_override'], 2)) ?>" inputmode="decimal" placeholder="Blank = rules">
				</label>
				<label class="span-2">Reason for the override
					<input type="text" name="contingency_reason" value="<?= e((string) $d['contingency_reason']) ?>" maxlength="200" placeholder="Required before an override counts">
				</label>
				<div class="self-end"><span class="chip chip-red" id="cont-overridden" <?= $calc['contingency_overridden'] ? '' : 'hidden' ?>>Overridden</span></div>
			</div>
		</section>

		<section class="card band">
			<h2>Payment schedule</h2>
			<div class="grid-form">
				<label>Deposit
					<select name="deposit" id="deposit-select">
						<?php $depSel = $d['deposit'] === null ? ($depositOptions[0] ?? null) : (float) $d['deposit']; $inList = false; ?>
						<?php foreach ($depositOptions as $a): $inList = $inList || abs($a - (float) $depSel) < 0.01; ?>
							<option value="<?= e($pf($a, 2)) ?>" <?= abs($a - (float) $depSel) < 0.01 ? 'selected' : '' ?>><?= e($m($a)) ?></option>
						<?php endforeach; ?>
						<?php if (!$inList && $depSel !== null): ?><option value="<?= e($pf((float) $depSel, 2)) ?>" selected><?= e($m((float) $depSel)) ?> (not allowed for this size)</option><?php endif; ?>
						<?php if (!$depositOptions && $depSel === null): ?><option value="">No deposit tiers set</option><?php endif; ?>
					</select>
					<small class="hint">Fixed by the <?= e($classLabel) ?> tiers and system size.</small>
				</label>
				<label class="span-2">Schedule
					<select name="payment_template_id">
						<option value=""><?= e($d['payment_template_name'] ?: 'Choose') ?><?= $d['payment_template_name'] ? ' (current)' : '' ?></option>
						<?php foreach ($templates as $t): ?>
							<option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option>
						<?php endforeach; ?>
					</select>
					<small class="hint">Percentages are of the balance after the deposit. Dollar amounts are what go into OpenSolar.</small>
				</label>
			</div>
			<div class="table-wrap mt-xs"><table class="table table-payments">
				<thead><tr><th>Payment</th><th class="num">Amount</th><th class="num">% of balance</th><th class="num">% of total</th></tr></thead>
				<tbody id="payments-body">
				<?php foreach ($calc['payments'] as $p): ?>
					<tr><td><?= e($p['label']) ?></td><td class="num"><?= e($m($p['amount'])) ?></td><td class="num"><?= $p['pct_balance'] === null ? '' : e(Presales::pct($p['pct_balance'], 0)) ?></td><td class="num"><?= $p['pct_total'] === null ? '' : e(Presales::pct($p['pct_total'])) ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<p class="hint">Each payment rounds up to $100 except the last, which takes what is left, so they always add up to the price.</p>
		</section>

		<section class="card band">
			<div class="card-head card-head-plain band-head">
				<h2>Warranty summary</h2>
				<button type="button" class="btn btn-secondary btn-small" data-copy-from="warranty-summary">Copy</button>
			</div>
			<textarea id="warranty-summary" class="warranty-summary" rows="14" readonly aria-label="Warranty summary"><?= e($calc['warranty_summary']) ?></textarea>
			<p class="hint">Plain text for the OpenSolar proposal. Manufacturer wording comes from each catalog item; the Trifecta and SolarInsure wording from the Rates page.</p>
		</section>

		<section class="card band">
			<h2>Notes</h2>
			<textarea name="notes" rows="3" data-autogrow aria-label="Build sheet notes"><?= e((string) $d['notes']) ?></textarea>
		</section>

		<div class="form-actions">
			<button class="btn btn-primary">Save build sheet</button>
			<a href="/presales/<?= (int) $o['id'] ?>" class="btn btn-ghost">Back without saving</a>
		</div>
	</div>

	<div class="design-pricebar">
		<div><strong data-v="price"><?= e($m($calc['price'])) ?></strong> <span class="muted small"><span data-v="gross_margin_pct"><?= $calc['gross_margin_pct'] === null ? '-' : e(Presales::pct($calc['gross_margin_pct'])) ?></span> margin</span></div>
		<button class="btn btn-primary btn-small">Save</button>
	</div>
</form>
<script src="<?= asset('assets/js/presales.js') ?>"></script>
