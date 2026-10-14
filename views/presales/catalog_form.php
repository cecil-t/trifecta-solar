<?php
use App\Csrf;
use App\Presales;

/**
 * @var array    $item      catalog row or the form as typed
 * @var int|null $id
 * @var int      $used      build sheets using it (edit only)
 * @var array    $activity
 * @var bool     $canEdit
 */
$v = static fn (string $k, $d = '') => $item[$k] ?? $d;
$sel = static fn ($a, $b) => (string) $a === (string) $b ? 'selected' : '';
$n = static fn ($x, $dec = 4) => $x === null || $x === '' ? '' : (is_numeric($x) ? Presales::field((float) $x, $dec) : (string) $x);
$isNew = $id === null;
$canEdit ??= true;
$dis = $canEdit ? '' : 'disabled';
?>
<div class="page-head">
	<div>
		<a href="/presales/catalog<?= $isNew ? '' : '?category=' . e(rawurlencode((string) $v('category'))) . '#item-' . (int) $id ?>" class="back">&larr; Catalog</a>
		<h1><?= $isNew ? 'New catalog item' : e($v('name')) ?></h1>
		<?php if (!$isNew): ?>
			<p class="muted">Used on <?= (int) $used ?> build sheet<?= $used === 1 ? '' : 's' ?>. Saved build sheets keep the price they were built with and show a flag when it changes here.</p>
		<?php endif; ?>
	</div>
</div>

<form method="post" action="<?= $isNew ? '/presales/catalog' : '/presales/catalog/' . (int) $id ?>" class="project-form">
	<?= Csrf::field() ?>
	<section class="card band">
		<h2>Item</h2>
		<div class="grid-form">
			<label>Category
				<select name="category" <?= $dis ?>>
					<?php foreach (Presales::CATEGORIES as $k => [$label]): ?>
						<option value="<?= $k ?>" <?= $sel($v('category'), $k) ?>><?= e($label) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="span-2">Name
				<input type="text" name="name" value="<?= e($v('name')) ?>" required maxlength="160" <?= $dis ?>>
				<small class="hint">Keep it short; long detail goes in Notes.</small>
			</label>
			<div class="checkfield">
				<span class="field-label">Active</span>
				<label class="boxwrap"><input type="checkbox" name="is_active" value="1" <?= $v('is_active', 1) ? 'checked' : '' ?> <?= $dis ?>> Offered on build sheets</label>
				<small class="hint">Retire instead of deleting; old build sheets still name it.</small>
			</div>
		</div>
	</section>

	<section class="card band">
		<h2>Price</h2>
		<div class="grid-form">
			<label>Cost to us
				<input type="text" name="price" value="<?= e($v('qty_rule') === 'dc_watts' && $v('price') !== '' && $v('price') !== null ? Presales::fixed((float) $v('price'), 4) : $n($v('price'))) ?>" inputmode="decimal" required <?= $dis ?>>
				<small class="hint">Per unit, per watt or per foot (see Quantity). Up to 4 decimals: 0.1851.</small>
			</label>
			<label>Quantity
				<select name="qty_rule" <?= $dis ?>>
					<?php foreach (Presales::QTY_RULES as $k => $label): ?>
						<option value="<?= $k ?>" <?= $sel($v('qty_rule', 'manual'), $k) ?>><?= e($label) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>Shipping per unit
				<input type="text" name="shipping" value="<?= e($n($v('shipping'), 2)) ?>" inputmode="decimal" placeholder="0" <?= $dis ?>>
			</label>
			<label>Tax
				<select name="tax_class" <?= $dis ?>>
					<?php foreach (Presales::TAX_CLASSES as $k => $label): ?>
						<option value="<?= $k ?>" <?= $sel($v('tax_class', 'bos'), $k) ?>><?= e($label) ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>Per-line adder
				<input type="text" name="setup_cost" value="<?= e($n($v('setup_cost'), 2)) ?>" inputmode="decimal" placeholder="0" <?= $dis ?>>
				<small class="hint">Added once per line (trench start-up, tracker sensors).</small>
			</label>
			<label>Line minimum
				<input type="text" name="min_total" value="<?= e($n($v('min_total'), 2)) ?>" inputmode="decimal" placeholder="None" <?= $dis ?>>
			</label>
			<label class="span-2">Price source
				<input type="text" name="price_source" value="<?= e($v('price_source')) ?>" maxlength="200" placeholder="e.g. Distributor quote 4/2026" <?= $dis ?>>
				<small class="hint">Price date: <?= $v('price_updated_at') ? e(fmt_date((string) $v('price_updated_at'))) : 'none' ?>. Changing any price field dates it today.</small>
			</label>
		</div>
	</section>

	<section class="card band">
		<h2>Where it's offered</h2>
		<div class="grid-form">
			<label>Jobs
				<select name="segment" <?= $dis ?>>
					<?php foreach (Presales::SEGMENT_LABELS as $k => $label): ?>
						<option value="<?= $k ?>" <?= $sel((string) $v('segment'), $k) ?>><?= e($label) ?></option>
					<?php endforeach; ?>
				</select>
				<small class="hint">Non-Residential = commercial, agricultural, government, non-profit.</small>
			</label>
			<div class="checkfield span-2">
				<span class="field-label">Install types (none checked = any)</span>
				<div class="boxrow">
					<?php $mounts = array_filter(explode(',', (string) $v('mounts'))); ?>
					<?php foreach (Presales::MOUNT_FILTERS as $k => $label): ?>
						<label class="boxwrap"><input type="checkbox" name="mounts[]" value="<?= $k ?>" <?= in_array($k, $mounts, true) ? 'checked' : '' ?> <?= $dis ?>> <?= e($label) ?></label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</section>

	<section class="card band">
		<h2>Specs and warranty</h2>
		<div class="grid-form">
			<label>Module watts <input type="text" name="watts" value="<?= e($n($v('watts'), 2)) ?>" inputmode="decimal" <?= $dis ?>></label>
			<label>Inverter / micro kW AC <input type="text" name="ac_kw" value="<?= e($n($v('ac_kw'), 3)) ?>" inputmode="decimal" <?= $dis ?>></label>
			<label>Battery kWh <input type="text" name="kwh" value="<?= e($n($v('kwh'), 2)) ?>" inputmode="decimal" <?= $dis ?>></label>
			<label>Battery kW <input type="text" name="kw" value="<?= e($n($v('kw'), 2)) ?>" inputmode="decimal" <?= $dis ?>></label>
			<label class="span-2">Manufacturer warranty
				<input type="text" name="warranty_text" value="<?= e($v('warranty_text')) ?>" maxlength="200" placeholder="e.g. 25 year product, 30 year performance" <?= $dis ?>>
				<small class="hint">Goes into the build sheet's warranty summary.</small>
			</label>
			<label>Extended warranty years <input type="text" name="ext_warranty_years" value="<?= e($n($v('ext_warranty_years'), 0)) ?>" inputmode="numeric" <?= $dis ?>></label>
			<label>Extended warranty cost <input type="text" name="ext_warranty_cost" value="<?= e($n($v('ext_warranty_cost'), 2)) ?>" inputmode="decimal" placeholder="Not offered" <?= $dis ?>></label>
			<label>SolarInsure cost per unit
				<input type="text" name="siw_cost" value="<?= e($n($v('siw_cost'), 2)) ?>" inputmode="decimal" placeholder="None" <?= $dis ?>>
				<small class="hint">Batteries: added only when the build sheet uses SolarInsure.</small>
			</label>
			<label>Domestic content (%)
				<input type="text" name="domestic_content_pct" value="<?= e($n($v('domestic_content_pct'), 2)) ?>" inputmode="decimal" placeholder="Unknown" <?= $dis ?>>
				<small class="hint">Share of this item's cost that counts as US content.</small>
			</label>
			<label>FEOC
				<select name="feoc_ok" <?= $dis ?>>
					<option value="" <?= (string) $v('feoc_ok') === '' ? 'selected' : '' ?>>Unknown</option>
					<option value="1" <?= (string) $v('feoc_ok') === '1' ? 'selected' : '' ?>>Compliant (no prohibited foreign entity)</option>
					<option value="0" <?= (string) $v('feoc_ok') === '0' ? 'selected' : '' ?>>Not compliant</option>
				</select>
			</label>
			<div class="checkfield">
				<span class="field-label">Inverters</span>
				<label class="boxwrap"><input type="checkbox" name="needs_mlpe" value="1" <?= $v('needs_mlpe') ? 'checked' : '' ?> <?= $dis ?>> Needs an optimizer on every module (SolarEdge)</label>
			</div>
			<div class="checkfield">
				<span class="field-label">Warranty programs</span>
				<label class="boxwrap"><input type="checkbox" name="is_solarinsure" value="1" <?= $v('is_solarinsure') ? 'checked' : '' ?> <?= $dis ?>> This program is SolarInsure</label>
			</div>
		</div>
	</section>

	<section class="card band">
		<h2>Notes</h2>
		<textarea name="notes" rows="5" data-autogrow aria-label="Notes" <?= $dis ?>><?= e($v('notes')) ?></textarea>
		<label class="boxwrap mt-sm"><input type="checkbox" name="print_notes" value="1" <?= $v('print_notes') ? 'checked' : '' ?> <?= $dis ?>> Show these notes on printed build sheets (printing is not built yet)</label>
	</section>

	<?php if ($canEdit): ?>
		<div class="form-actions">
			<button class="btn btn-primary"><?= $isNew ? 'Add item' : 'Save' ?></button>
			<a href="/presales/catalog" class="btn btn-ghost">Cancel</a>
		</div>
	<?php endif; ?>
</form>

<?php if (!$isNew): ?>
	<section class="card band">
		<h2>History</h2>
		<?= App\View::partial('partials/activity', ['entries' => $activity]) ?>
	</section>
<?php endif; ?>
