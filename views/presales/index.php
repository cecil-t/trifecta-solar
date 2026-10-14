<?php
use App\Presales;
use App\Projects;

/**
 * @var array  $rows    opportunities with customer, salesperson, build sheet count and price range
 * @var string $status  open | sold | lost | all
 * @var string $q       search text
 * @var array  $counts  per status
 */
$tabs = ['open' => 'Open', 'sold' => 'Sold', 'lost' => 'Lost', 'all' => 'All'];
?>
<div class="page-head">
	<div>
		<h1>Sales</h1>
		<p class="muted">Build sheets for jobs being quoted. A sold build sheet carries into its project.</p>
	</div>
	<div class="head-actions">
		<a href="/presales/catalog" class="btn btn-ghost">Catalog</a>
		<a href="/presales/rates" class="btn btn-ghost">Rates</a>
		<a href="/presales/new" class="btn btn-primary">New opportunity</a>
	</div>
</div>

<div class="tabs">
	<?php foreach ($tabs as $key => $label): ?>
		<a href="/presales?status=<?= $key ?>" class="tab <?= $status === $key ? 'active' : '' ?>"><?= e($label) ?> <span class="tab-count"><?= (int) ($counts[$key] ?? 0) ?></span></a>
	<?php endforeach; ?>
</div>

<form class="filters" method="get" action="/presales">
	<input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, customer, address">
	<button class="btn btn-secondary btn-small">Search</button>
</form>

<div class="card card-flush card-sticky-head scroll-x-phone">
	<table class="table table-presales table-tight">
		<thead>
		<tr><th>Opportunity</th><th>Sales</th><th>Type</th><th class="num">Build sheets</th><th class="num">kW DC</th><th class="num">Price</th><th>Status</th><th>Updated</th></tr>
		</thead>
		<tbody>
		<?php if (!$rows): ?>
			<tr><td colspan="8" class="empty"><?= $q !== '' ? 'Nothing matches that search.' : 'Nothing here yet.' ?></td></tr>
		<?php endif; ?>
		<?php foreach ($rows as $r): ?>
			<tr data-href="/presales/<?= (int) $r['id'] ?>" id="opp-<?= (int) $r['id'] ?>">
				<td>
					<a href="/presales/<?= (int) $r['id'] ?>"><strong><?= e($r['name']) ?></strong></a>
					<?php if ($r['customer_name'] && $r['customer_name'] !== $r['name']): ?><div class="muted small"><?= e($r['customer_name']) ?></div><?php endif; ?>
					<?php if ($r['site_city']): ?><div class="muted small"><?= e(trim($r['site_city'] . ', ' . $r['site_state'], ', ')) ?></div><?php endif; ?>
				</td>
				<td><?= e($r['salesperson_initials'] ?: $r['salesperson_name']) ?></td>
				<td><?= e(Projects::CUSTOMER_TYPES[$r['customer_type']] ?? '') ?><?= $r['is_agricultural'] ? ' <span class="chip">Ag</span>' : '' ?></td>
				<td class="num"><?= (int) $r['design_count'] ?></td>
				<td class="num"><?= $r['dc_max'] ? e(number_format($r['dc_max'] / 1000, 2)) : '' ?></td>
				<td class="num nowrap">
					<?php if ($r['price_min'] !== null): ?>
						<?= e(Presales::money((float) $r['price_min'])) ?><?= $r['price_max'] > $r['price_min'] ? ' to ' . e(Presales::money((float) $r['price_max'])) : '' ?>
					<?php endif; ?>
				</td>
				<td>
					<span class="chip <?= $r['status'] === 'sold' ? 'chip-green' : ($r['status'] === 'lost' ? 'chip-na' : 'chip-blue') ?>"><?= e(Presales::STATUSES[$r['status']]) ?></span>
					<?php if ($r['status'] === 'sold' && $r['project_number']): ?><div class="muted small">Project <?= e($r['project_number']) ?></div><?php endif; ?>
					<?php if ($r['status'] === 'lost' && $r['lost_reason']): ?><div class="muted small"><?= e($r['lost_reason']) ?></div><?php endif; ?>
				</td>
				<td class="nowrap"><?= e(fmt_dt($r['updated_at'], 'm/d/Y')) ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
