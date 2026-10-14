<?php
use App\Presales;
use App\Projects;

/**
 * One-sheet view of a saved build sheet: everything picked, the totals and the payments.
 *
 * @var array $o           opportunity with customer_name, salesperson_name
 * @var array $d           design
 * @var array $lines
 * @var array $arrays
 * @var array $calc
 * @var array $flags
 * @var array $printNotes  catalog item id => notes marked "show on printed build sheets"
 * @var string $depositClass
 */
$m = static fn ($v) => Presales::money($v);
$deg = static fn (float $v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
$base = '/presales/' . (int) $o['id'] . '/designs/' . (int) $d['id'];
$siteAddr = trim(implode(', ', array_filter([$o['site_street'], $o['site_city'], trim(($o['site_state'] ?? '') . ' ' . ($o['site_zip'] ?? ''))])));
$byCat = [];
foreach ($lines as $i => $l) {
	if ($l['category'] !== 'extra' && isset($calc['lines'][$i]) && $calc['lines'][$i]['qty_resolved'] > 0) {
		$byCat[$l['category']][] = $i;
	}
}
?>
<div class="sheet-tools no-print">
	<a href="<?= $base ?>" class="back">&larr; Back to the build sheet</a>
	<div class="sheet-tools-actions">
		<button type="button" class="btn btn-primary btn-small" data-print>Print or save as PDF</button>
	</div>
</div>

<header class="sheet-head">
	<img src="<?= asset('assets/img/logo-horizontal.png') ?>" alt="Trifecta Solar" width="143" height="42">
	<div class="sheet-title">
		<div class="sheet-kicker">Build sheet &middot; internal</div>
		<h1><?= e($d['name']) ?></h1>
		<div class="muted small"><?= e($o['name']) ?> &middot; created <?= e(fmt_dt($d['created_at'], 'm/d/Y g:i A')) ?><?= !empty($who['created_name']) ? ' by ' . e($who['created_name']) : '' ?> &middot; updated <?= e(fmt_dt($d['updated_at'], 'm/d/Y g:i A')) ?><?= !empty($who['updated_name']) ? ' by ' . e($who['updated_name']) : '' ?></div>
	</div>
</header>

<?php if ($flags['changed'] || $flags['retired'] || $flags['stale']): ?>
	<p class="sheet-warn"><?= $flags['changed'] + $flags['retired'] ? (int) ($flags['changed'] + $flags['retired']) . ' line(s) priced differently in the catalog now. ' : '' ?><?= $flags['stale'] ? (int) $flags['stale'] . ' line(s) use prices due for a check.' : '' ?></p>
<?php endif; ?>

<?php foreach ($calc['checks'] as $msg): ?><p class="sheet-warn"><?= e($msg) ?></p><?php endforeach; ?>

<div class="sheet-grid sheet-grid-3">
	<section class="sheet-sec sheet-box sheet-box-blue">
		<h2>Customer</h2>
		<dl class="kv">
			<dt>Customer</dt><dd><?= e($o['customer_name'] ?? '') ?></dd>
			<dt>Site</dt><dd><?= e($siteAddr) ?></dd>
			<dt>Type</dt><dd><?= e(Presales::CLASS_LABELS[$depositClass]) ?><?= $o['is_agricultural'] ? ', agricultural' : '' ?></dd>
			<dt>Sales</dt><dd><?= e($o['salesperson_name'] ?? '') ?></dd>
			<?php if ($o['annual_usage_kwh']): ?><dt>Usage</dt><dd><?= number_format((int) $o['annual_usage_kwh']) ?> kWh/yr</dd><?php endif; ?>
		</dl>
	</section>
	<section class="sheet-sec sheet-box sheet-box-yellow">
		<h2>System</h2>
		<dl class="kv">
			<dt>Size</dt><dd><?= e(number_format($calc['dc_kw'], 2)) ?> kW DC &middot; <?= e(number_format($calc['ac_kw'], 2)) ?> kW AC<?= $calc['ratio'] !== null ? ' &middot; ' . e(number_format($calc['ratio'], 2)) . ' DC/AC' : '' ?></dd>
			<?php if ($calc['storage_kwh'] > 0): ?><dt>Storage</dt><dd><?= e(number_format($calc['storage_kwh'], 1)) ?> kWh</dd><?php endif; ?>
			<dt>Install</dt><dd><?= e(Projects::INSTALL_TYPES[$d['install_type']] ?? 'Not set') ?></dd>
			<dt>Internet</dt><dd><?= e(Projects::INVERTER_INTERNET[$d['inverter_internet']] ?? 'Not set') ?></dd>
			<dt>Warranty</dt><dd><?= e($d['warranty_name'] ?? 'None') ?></dd>
			<dt>Domestic</dt><dd><?= $calc['domestic_content_pct'] === null ? 'Unknown' : e(Presales::pct($calc['domestic_content_pct'])) . ' of major equipment' ?><?= $calc['domestic_unknown'] && $calc['domestic_content_pct'] !== null ? ' (' . count($calc['domestic_unknown']) . ' unknown)' : '' ?></dd>
			<dt>FEOC</dt><dd><?= e(App\Controllers\DesignController::feocText($calc)) ?></dd>
		</dl>
	</section>
	<section class="sheet-sec sheet-box sheet-box-green">
		<h2>Price</h2>
		<dl class="kv">
			<dt>Retail</dt><dd><strong><?= e($m($calc['price'])) ?></strong><?= $calc['price_per_watt'] !== null ? ' &middot; ' . e(Presales::perWatt($calc['price_per_watt'])) . '/W' : '' ?></dd>
			<dt>Cost</dt><dd><?= e($m($calc['cost_total'])) ?><?= $calc['cost_per_watt'] !== null ? ' &middot; ' . e(Presales::perWatt($calc['cost_per_watt'])) . '/W' : '' ?></dd>
			<dt>Margin</dt><dd><?= $calc['gross_margin_pct'] !== null ? e(Presales::pct($calc['gross_margin_pct'])) . ' (' . e($m($calc['gross_margin'])) . ')' : '-' ?></dd>
			<dt>Markup</dt><dd><?= e(Presales::pct((float) $d['markup'])) ?></dd>
			<dt>Tax</dt><dd><?= $d['tax_exempt'] ? 'Tax exempt (BOS taxed)' : e(Presales::pct((float) $d['tax_rate'], 2)) . ($d['tax_note'] ? ' ' . e($d['tax_note']) : '') ?></dd>
		</dl>
	</section>
</div>

<section class="sheet-sec sheet-wide">
	<h2>Equipment and materials</h2>
	<table class="sheet-table">
		<thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Tax</th><th class="num">Cost</th><th class="num">Retail</th></tr></thead>
		<?php foreach (Presales::CATEGORIES as $cat => [$label]): if (empty($byCat[$cat])) { continue; } $t = $calc['categories'][$cat]; ?>
			<tbody class="sheet-keep">
			<tr class="sheet-cat"><td colspan="3"><?= e($label) ?></td><td class="num"><?= $t['tax'] > 0 ? e($m($t['tax'])) : '-' ?></td><td class="num"><?= e($m($t['cost'])) ?></td><td class="num"><?= e($m($t['retail'])) ?></td></tr>
			<?php foreach ($byCat[$cat] as $i): $l = $lines[$i]; $c = $calc['lines'][$i]; $fl = $flags['lines'][$i] ?? null; ?>
				<tr class="sheet-item">
					<td>
						<?= e($l['name']) ?><?= !empty($l['ext_warranty']) ? ' <span class="muted">+ ext. warranty ' . (int) $l['ext_warranty_years'] . ' yr</span>' : '' ?>
						<?php if ($fl && ($fl['changed'] || $fl['retired'])): ?> <span class="chip chip-red">Catalog price changed</span><?php endif; ?>
						<?php if ($fl && $fl['stale'] && !$fl['retired']): ?> <span class="chip chip-orange">Check price</span><?php endif; ?>
						<?php if ($l['catalog_item_id'] && isset($printNotes[(int) $l['catalog_item_id']])): ?><div class="muted small"><?= nl2br(e($printNotes[(int) $l['catalog_item_id']])) ?></div><?php endif; ?>
					</td>
					<td class="num"><?= e(Presales::field($c['qty_resolved'], 2)) ?><?= $l['qty_rule'] === 'dc_watts' ? ' W' : '' ?></td>
					<td class="num"><?php if ($c['overridden']): ?><span class="unit-overridden"><?= e(Presales::price($c['unit_total'], $c['qty_rule'])) ?></span><div class="override-why"><?= e((string) $l['override_reason']) ?></div><?php else: ?><?= e(Presales::price($c['unit_total'], $c['qty_rule'])) ?><?php endif; ?></td>
					<td class="num"><?= $c['tax'] > 0 ? e($m($c['tax'])) : '-' ?></td>
					<td class="num"><?= e($m($c['cost'])) ?></td>
					<td class="num"><?= e($m($c['retail'])) ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		<?php endforeach; ?>
		<tfoot><tr><td>Equipment and materials</td><td></td><td></td><td class="num"><?= e($m($calc['tax_total'])) ?></td><td class="num"><?= e($m($calc['equipment_cost'])) ?></td><td class="num"><?= e($m(array_sum(array_column($calc['categories'], 'retail')))) ?></td></tr></tfoot>
	</table>
</section>

<div class="sheet-grid sheet-wide sheet-lower">
	<section class="sheet-sec sheet-box sheet-box-blue">
		<h2>Cost to price</h2>
		<table class="sheet-table sheet-compact">
			<tbody>
			<tr><td>Equipment and materials <span class="muted">(with tax)</span></td><td class="num"><?= e($m($calc['equipment_cost'])) ?></td></tr>
			<tr><td>Warranty program</td><td class="num"><?= e($m($calc['warranty_cost'])) ?></td></tr>
			<tr><td>Labor<?= $calc['man_hours'] !== null ? ' <span class="muted">(' . e(number_format($calc['man_hours'], 1)) . ' man-hours)</span>' : '' ?></td><td class="num"><?= e($m($calc['labor'])) ?></td></tr>
			<tr><td>Site visit</td><td class="num"><?= e($m($calc['site_visit'])) ?></td></tr>
			<?php if ($calc['overnight'] + $calc['per_diem'] > 0): ?><tr><td>Travel <span class="muted">(<?= (int) $d['trip_nights'] ?> nights, <?= (int) $d['trip_crew'] ?> crew)</span></td><td class="num"><?= e($m($calc['overnight'] + $calc['per_diem'])) ?></td></tr><?php endif; ?>
			<tr><td>Contingency <span class="muted">(<?= $calc['contingency_pct'] !== null ? e(Presales::pct($calc['contingency_pct'])) : '-' ?><?= $calc['contingency_overridden'] ? ', overridden: ' . e((string) $d['contingency_reason']) : '' ?>)</span></td><td class="num"><?= e($m($calc['contingency'])) ?></td></tr>
			<tr class="sheet-step"><td>Internal cost</td><td class="num"><?= e($m($calc['cost_total'])) ?></td></tr>
			<tr><td>Markup <span class="muted">(<?= e(Presales::pct((float) $d['markup'])) ?>)</span></td><td class="num"><?= e($m($calc['markup_amount'])) ?></td></tr>
			<tr><td>Commission <span class="muted">(<?= e(Presales::pct((float) $d['commission_rate'])) ?>)</span></td><td class="num"><?= e($m($calc['commission'])) ?></td></tr>
			<?php foreach ($calc['extras'] as $x): ?><tr><td>Extra, no markup: <?= e($x['name']) ?></td><td class="num"><?= e($m($x['amount'])) ?></td></tr><?php endforeach; ?>
			<tr><td>Rounded up</td><td class="num"><?= e($m($calc['rounding'])) ?></td></tr>
			<tr class="sheet-final"><td>Retail price</td><td class="num"><?= e($m($calc['price'])) ?></td></tr>
			</tbody>
		</table>
	</section>
	<div class="sheet-stack">
		<section class="sheet-sec sheet-box sheet-box-green">
			<h2>Payments</h2>
			<table class="sheet-table sheet-compact">
				<tbody>
				<?php foreach ($calc['payments'] as $p): ?>
					<tr><td><?= e($p['label']) ?></td><td class="num"><?= e($m($p['amount'])) ?></td><td class="num muted"><?= $p['pct_total'] !== null ? e(Presales::pct($p['pct_total'])) : '' ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php if ($arrays): ?>
			<section class="sheet-sec sheet-box sheet-box-yellow">
				<h2>Arrays</h2>
				<table class="sheet-table sheet-compact sheet-arrays">
					<thead><tr><th>Array</th><th>Mount</th><th class="num">Azimuth</th><th class="num">Tilt</th><th class="num">Panels</th></tr></thead>
					<tbody>
					<?php foreach ($arrays as $a): ?>
						<tr class="<?= $a['tilt'] !== null && (float) $a['tilt'] > (float) $d['steep_deg'] ? 'text-red' : '' ?>">
							<td><?= e($a['name'] ?? '') ?></td>
							<td class="nowrap" title="<?= e(Projects::ROOF_MATERIALS[$a['material'] ?? ''] ?? '') ?>"><?= e(Presales::MOUNTS[$a['mount']] ?? '') ?></td>
							<td class="num"><?= $a['azimuth'] !== null ? (int) $a['azimuth'] . '° ' . e(Projects::compass((int) $a['azimuth'])) : '' ?></td>
							<td class="num"><?= $a['tilt'] !== null ? e($deg((float) $a['tilt'])) . '°' : '' ?></td>
							<td class="num"><?= $a['panels'] !== null ? (int) $a['panels'] : '' ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>
	</div>
</div>

<section class="sheet-sec sheet-wide sheet-keep">
	<h2>Warranty summary</h2>
	<div class="sheet-notes sheet-pre"><?= nl2br(e($calc['warranty_summary'])) ?></div>
</section>

<?php if ($d['notes']): ?>
	<section class="sheet-sec sheet-wide">
		<h2>Notes</h2>
		<div class="sheet-notes"><?= nl2br(e($d['notes'])) ?></div>
	</section>
<?php endif; ?>

<p class="sheet-foot">Internal build sheet. Not a proposal or contract.</p>
