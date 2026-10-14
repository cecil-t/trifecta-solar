<?php
use App\Csrf;
use App\Presales;

/**
 * @var array  $groups      category => items
 * @var string $q
 * @var string $cat
 * @var string $show        active | stale | retired | all
 * @var int    $staleCount
 * @var bool   $canEdit
 */
$days = (int) Presales::num('stale_days');
$qs = static fn (array $over) => '/presales/catalog?' . http_build_query(array_filter(array_merge(['q' => $q, 'category' => $cat, 'show' => $show], $over), static fn ($v) => $v !== '' && $v !== null));
$back = $qs([]);
$spec = static function (array $i): string {
	$parts = [];
	if ($i['watts']) { $parts[] = rtrim(rtrim(number_format((float) $i['watts'], 1), '0'), '.') . ' W'; }
	if ($i['ac_kw']) { $parts[] = rtrim(rtrim(number_format((float) $i['ac_kw'], 3), '0'), '.') . ' kW AC'; }
	if ($i['kwh']) { $parts[] = rtrim(rtrim(number_format((float) $i['kwh'], 2), '0'), '.') . ' kWh'; }
	if ($i['kw']) { $parts[] = rtrim(rtrim(number_format((float) $i['kw'], 2), '0'), '.') . ' kW'; }
	return implode(' &middot; ', array_map('e', $parts));
};
?>
<div class="page-head">
	<div>
		<a href="/presales" class="back">&larr; Pre-Sales</a>
		<h1>Catalog</h1>
		<p class="muted">Internal cost of everything a build sheet can price. Each price carries its date and source; prices older than <?= $days ?> days show red so they get checked (quarterly).<?= $canEdit ? '' : ' Admins and salespeople can edit.' ?></p>
	</div>
	<?php if ($canEdit): ?><a href="/presales/catalog/new<?= $cat ? '?category=' . e(rawurlencode($cat)) : '' ?>" class="btn btn-primary">New item</a><?php endif; ?>
</div>

<div class="tabs">
	<a href="<?= e($qs(['show' => 'active'])) ?>" class="tab <?= $show === 'active' ? 'active' : '' ?>">Active</a>
	<a href="<?= e($qs(['show' => 'stale'])) ?>" class="tab <?= $show === 'stale' ? 'active' : '' ?>">Needs a price check <span class="tab-count"><?= (int) $staleCount ?></span></a>
	<a href="<?= e($qs(['show' => 'retired'])) ?>" class="tab <?= $show === 'retired' ? 'active' : '' ?>">Retired</a>
	<a href="<?= e($qs(['show' => 'all'])) ?>" class="tab <?= $show === 'all' ? 'active' : '' ?>">All</a>
</div>

<form class="filters" method="get" action="/presales/catalog">
	<input type="hidden" name="show" value="<?= e($show) ?>">
	<input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, notes, source">
	<select name="category" data-autosubmit>
		<option value="">All categories</option>
		<?php foreach (Presales::CATEGORIES as $k => [$label]): ?>
			<option value="<?= $k ?>" <?= $cat === $k ? 'selected' : '' ?>><?= e($label) ?></option>
		<?php endforeach; ?>
	</select>
	<button class="btn btn-secondary btn-small">Filter</button>
</form>

<?php if (!$groups): ?>
	<p class="muted">Nothing here<?= $q !== '' ? ' for that search' : '' ?>.</p>
<?php endif; ?>
<?php foreach ($groups as $k => $items): ?>
	<section class="card card-flush card-sticky-head scroll-x-phone cat-card">
		<table class="table table-catalog table-tight">
			<thead><tr><th><?= e(Presales::categoryLabel($k)) ?> <span class="th-count"><?= count($items) ?></span></th><th class="num">Cost</th><th>Priced</th><th>Tax</th><th>Fits</th><th>Specs and warranty</th><th>Price date and source</th><th></th></tr></thead>
			<tbody>
			<?php foreach ($items as $i): $stale = Presales::isStale($i['price_updated_at']); ?>
				<tr id="item-<?= (int) $i['id'] ?>" class="<?= $i['is_active'] ? '' : 'row-muted' ?>">
					<td>
						<a href="/presales/catalog/<?= (int) $i['id'] ?>"><strong><?= e($i['name']) ?></strong></a>
						<?php if (!$i['is_active']): ?> <span class="chip">Retired</span><?php endif; ?>
						<?php if ($i['notes']): ?>
							<details class="cat-notes"><summary>Details</summary><p><?= nl2br(e($i['notes'])) ?></p></details>
						<?php endif; ?>
					</td>
					<td class="num nowrap">
						<strong><?= e(Presales::unit((float) $i['price'])) ?></strong><?= $i['qty_rule'] === 'dc_watts' ? '/W' : ($k === 'sitework' ? '/ft' : '') ?>
						<?php if ((float) $i['shipping'] > 0): ?><div class="muted small">+ <?= e(Presales::unit((float) $i['shipping'])) ?> shipping</div><?php endif; ?>
						<?php if ((float) $i['setup_cost'] > 0): ?><div class="muted small">+ <?= e(Presales::money((float) $i['setup_cost'])) ?> per line</div><?php endif; ?>
						<?php if ($i['min_total'] !== null): ?><div class="muted small">min <?= e(Presales::money((float) $i['min_total'])) ?></div><?php endif; ?>
						<?php if ($i['ext_warranty_cost'] !== null): ?><div class="muted small">ext. warranty +<?= e(Presales::money((float) $i['ext_warranty_cost'])) ?></div><?php endif; ?>
						<?php if ($i['siw_cost'] !== null): ?><div class="muted small">SolarInsure +<?= e(Presales::money((float) $i['siw_cost'])) ?></div><?php endif; ?>
					</td>
					<td class="small nowrap"><?= e(Presales::QTY_SHORT[$i['qty_rule']] ?? '') ?><?= $k === 'sitework' && $i['qty_rule'] === 'manual' ? ' foot' : '' ?></td>
					<td><span class="chip chip-tax-<?= e($i['tax_class']) ?>"><?= e(Presales::TAX_SHORT[$i['tax_class']] ?? '') ?></span></td>
					<td class="small"><?= $i['segment'] ? e(Presales::CLASS_LABELS[$i['segment']]) : '' ?><?= $i['segment'] && $i['mounts'] ? '<br>' : '' ?><?= $i['mounts'] ? e(implode(', ', array_map(static fn ($x) => Presales::MOUNT_FILTERS[$x] ?? $x, explode(',', $i['mounts'])))) : '' ?><?= !$i['segment'] && !$i['mounts'] ? '<span class="muted">Any</span>' : '' ?></td>
					<td class="small cell-wrap"><?= $spec($i) ?><?= $i['domestic_content_pct'] !== null ? '<div>Domestic ' . e(Presales::field((float) $i['domestic_content_pct'], 1)) . '%</div>' : '' ?><?= $i['feoc_ok'] !== null ? '<div>' . ((int) $i['feoc_ok'] ? 'FEOC compliant' : '<span class="text-red">Not FEOC compliant</span>') . '</div>' : '' ?><?= $i['warranty_text'] ? '<div class="muted">' . e($i['warranty_text']) . '</div>' : '' ?><?= $i['ext_warranty_years'] ? '<div class="muted">Extendable to ' . (int) $i['ext_warranty_years'] . ' years</div>' : '' ?></td>
					<td class="small cell-wrap">
						<span class="<?= $stale ? 'price-stale' : '' ?>"><?= $i['price_updated_at'] ? e(fmt_date($i['price_updated_at'])) : 'No date' ?></span>
						<?= $i['updated_by_name'] ? '<span class="muted"> &middot; ' . e($i['updated_by_name']) . '</span>' : '' ?>
						<?= $i['price_source'] ? '<div class="muted cat-src" title="' . e($i['price_source']) . '">' . e($i['price_source']) . '</div>' : '' ?>
					</td>
					<td class="nowrap">
						<?php if ($canEdit && $i['is_active']): ?>
							<form method="post" action="/presales/catalog/<?= (int) $i['id'] ?>/confirm" class="inline-form">
								<?= Csrf::field() ?>
								<input type="hidden" name="back" value="<?= e($back) ?>">
								<button class="btn btn-ghost btn-small" title="The price was checked and is still right: date it today">Confirm price</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endforeach; ?>
