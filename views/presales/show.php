<?php
use App\Csrf;
use App\Presales;
use App\Projects;

/**
 * @var array $o                 opportunity with customer, salesperson and project names
 * @var array $cards             build sheets: ['d' => design row, 'calc' => Pricing result, 'flags' => price flags]
 * @var array $contacts          customer contacts
 * @var array $otherOpps         the customer's other opportunities
 * @var array $customerProjects  the customer's projects
 * @var array $activity
 */
$siteAddr = trim(implode(', ', array_filter([$o['site_street'], $o['site_city'], trim(($o['site_state'] ?? '') . ' ' . ($o['site_zip'] ?? ''))])));
$statusChip = ['open' => 'chip-blue', 'sold' => 'chip-green', 'lost' => 'chip-na'][$o['status']];
?>
<div class="project-head">
	<div>
		<a href="/presales?status=<?= e($o['status']) ?>#opp-<?= (int) $o['id'] ?>" class="back">&larr; Sales</a>
		<div class="page-kicker">Opportunity</div>
		<h1><?= e($o['name']) ?></h1>
		<div class="badges">
			<span class="chip <?= $statusChip ?>"><?= e(Presales::STATUSES[$o['status']]) ?></span>
			<?php if ($o['status'] === 'lost' && $o['lost_reason']): ?><span class="chip"><?= e($o['lost_reason']) ?></span><?php endif; ?>
			<?php if ($o['status'] === 'sold' && $o['project_id']): ?><a class="chip chip-green" href="/projects/<?= (int) $o['project_id'] ?>">Project <?= e($o['project_number'] . ' ' . $o['project_name']) ?></a><?php endif; ?>
			<?php if (!$customerProjects && $o['customer_id']): ?><span class="chip chip-orange" title="This customer has no projects yet">Prospect</span><?php endif; ?>
		</div>
	</div>
	<div class="head-actions">
		<?php if ($drive = safe_url($o['drive_url'])): ?>
			<a href="<?= e($drive) ?>" target="_blank" rel="noopener" class="btn btn-secondary">Google Drive folder &#8599;</a>
		<?php endif; ?>
		<a href="/presales/<?= (int) $o['id'] ?>/edit" class="btn btn-ghost" title="Customer, site and utility bill">Edit opportunity</a>
		<form method="post" action="/presales/<?= (int) $o['id'] ?>/designs">
			<?= Csrf::field() ?>
			<button class="btn btn-primary">New build sheet</button>
		</form>
	</div>
</div>

<div class="presales-top">
	<section class="card band">
		<h2>Customer</h2>
		<dl class="kv">
			<dt>Customer</dt><dd><?= $o['customer_id'] ? '<a href="/organizations/' . (int) $o['customer_id'] . '">' . e($o['customer_name']) . '</a>' : '<span class="muted">Not set</span>' ?></dd>
			<?php foreach (array_slice($contacts, 0, 3) as $c): ?>
				<dt><?= e($c['role'] ?: 'Contact') ?></dt>
				<dd><?= e($c['name']) ?><?php if ($c['phone']): ?> &middot; <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a><?php endif; ?><?php if ($c['email']): ?><br><a href="mailto:<?= e($c['email']) ?>" class="small"><?= e($c['email']) ?></a><?php endif; ?></dd>
			<?php endforeach; ?>
			<?php if (!$contacts && ($o['customer_phone'] || $o['customer_email'])): ?>
				<dt>Contact</dt><dd><?= e($o['customer_phone']) ?><?= $o['customer_phone'] && $o['customer_email'] ? '<br>' : '' ?><?= $o['customer_email'] ? '<a href="mailto:' . e($o['customer_email']) . '" class="small">' . e($o['customer_email']) . '</a>' : '' ?></dd>
			<?php endif; ?>
			<dt>Site</dt><dd><?= $siteAddr !== '' ? maps_link($siteAddr) : '<span class="muted">Not set</span>' ?></dd>
			<dt>Type</dt><dd><?= e(Projects::CUSTOMER_TYPES[$o['customer_type']] ?? '') ?><?= $o['is_agricultural'] ? ' &middot; Agricultural' : '' ?><?php $cls = Presales::depositClass($o['customer_type'], (bool) $o['is_agricultural']); ?><?= $cls === 'commercial' ? ' <span class="muted small">(Non-Residential)</span>' : '' ?></dd>
			<dt>Salesperson</dt><dd><?= e($o['salesperson_name'] ?? '') ?><?= $o['handoff_user_id'] ? '<div class="small muted">Handed off by ' . e((string) App\Db::value('SELECT name FROM users WHERE id = ?', [(int) $o['handoff_user_id']])) . '</div>' : '' ?></dd>
			<dt>Lead source</dt><dd><?= $o['lead_source'] ? e(Presales::leadLabel($o)) : '<a href="/presales/' . (int) $o['id'] . '/edit" class="small">Set the lead source</a>' ?>
				<?php if ($o['lead_source'] === 'self_gen' && Presales::isRepeatCustomer($o)): ?><div class="small text-orange">We already did a job for this customer, so no self-generated bonus applies.</div><?php endif; ?></dd>
			<dt>Utility bill</dt><dd><?php if ($o['bill_name'] || $o['utility_id'] || $o['utility_account'] || $o['annual_usage_kwh']): ?>
				<?= implode(' &middot; ', array_map('e', array_filter([$o['bill_name'], $o['utility_name']]))) ?>
				<?php if ($o['annual_usage_kwh']): ?><div><?= number_format((int) $o['annual_usage_kwh']) ?> kWh, last 12 months</div><?php endif; ?>
				<?php if ($o['utility_account'] || $o['utility_meter']): ?><div class="small muted"><?= $o['utility_account'] ? 'Account ' . e($o['utility_account']) : '' ?><?= $o['utility_account'] && $o['utility_meter'] ? ' &middot; ' : '' ?><?= $o['utility_meter'] ? 'Meter ' . e($o['utility_meter']) : '' ?><?= $o['utility_tariff'] ? ' &middot; Rate ' . e($o['utility_tariff']) : '' ?></div><?php endif; ?>
				<?php if ($o['service_address']): ?><div class="small muted">Service address <?= e($o['service_address']) ?></div><?php endif; ?>
			<?php else: ?><a href="/presales/<?= (int) $o['id'] ?>/edit" class="small">Add from the electric bill</a><?php endif; ?></dd>
			<?php if ($customerProjects): ?>
				<dt>Projects</dt><dd><?php foreach ($customerProjects as $cp): ?><div><a href="/projects/<?= (int) $cp['id'] ?>"><?= e($cp['project_number'] . ' ' . $cp['name']) ?></a></div><?php endforeach; ?></dd>
			<?php endif; ?>
			<?php if ($otherOpps): ?>
				<dt>Other quotes</dt><dd><?php foreach ($otherOpps as $oo): ?><div><a href="/presales/<?= (int) $oo['id'] ?>"><?= e($oo['name']) ?></a> <span class="muted small"><?= e(Presales::STATUSES[$oo['status']]) ?></span></div><?php endforeach; ?></dd>
			<?php endif; ?>
		</dl>
		<?php if ($o['notes']): ?><p class="mt-sm desc-text"><?= nl2br(e($o['notes'])) ?></p><?php endif; ?>
	</section>

	<section class="card band">
		<h2>Change status</h2>
		<?php if ($o['status'] !== 'open'): ?>
			<p class="muted small">Marked <?= e(Presales::STATUSES[$o['status']]) ?> <?= e(fmt_dt((string) $o['status_at'], 'm/d/Y')) ?>.</p>
			<form method="post" action="/presales/<?= (int) $o['id'] ?>/status">
				<?= Csrf::field() ?>
				<input type="hidden" name="status" value="open">
				<button class="btn btn-ghost btn-small">Reopen</button>
			</form>
		<?php else: ?>
			<?php if ($cards): ?>
				<form method="post" action="/presales/<?= (int) $o['id'] ?>/status" class="stack-sm">
					<?= Csrf::field() ?>
					<input type="hidden" name="status" value="sold">
					<label>Sold build sheet
						<select name="sold_design_id">
							<?php foreach ($cards as $c): ?><option value="<?= (int) $c['d']['id'] ?>"><?= e($c['d']['name']) ?> (<?= e(Presales::money($c['calc']['price'])) ?>)</option><?php endforeach; ?>
						</select>
					</label>
					<button class="btn btn-secondary btn-small">Mark sold</button>
				</form>
			<?php endif; ?>
			<form method="post" action="/presales/<?= (int) $o['id'] ?>/status" class="stack-sm mt-sm">
				<?= Csrf::field() ?>
				<input type="hidden" name="status" value="lost">
				<label>Lost reason (optional)
					<input type="text" name="lost_reason" maxlength="200" placeholder="e.g. Went with another installer, decided not to proceed">
				</label>
				<button class="btn btn-ghost btn-small">Mark lost</button>
			</form>
		<?php endif; ?>
	</section>
</div>

<h2 class="section-title mt">Build sheets</h2>
<?php if (!$cards): ?>
	<p class="muted">No build sheets yet. Use New build sheet above.</p>
<?php endif; ?>
<div class="design-cards">
	<?php foreach ($cards as $c): $d = $c['d']; $k = $c['calc']; $sold = (int) $o['sold_design_id'] === (int) $d['id']; ?>
		<a class="card design-card<?= $sold ? ' is-sold' : '' ?>" href="/presales/<?= (int) $o['id'] ?>/designs/<?= (int) $d['id'] ?>">
			<span class="design-card-head">
				<strong><?= e($d['name']) ?></strong>
				<?php if ($sold): ?><span class="chip chip-green">Sold</span><?php endif; ?>
			</span>
			<span class="design-card-price"><?= e(Presales::money($k['price'])) ?></span>
			<span class="design-card-meta">
				<?= e(number_format($k['dc_kw'], 2)) ?> kW DC
				<?php if ($k['price_per_watt'] !== null): ?> &middot; <?= e(Presales::perWatt($k['price_per_watt'])) ?>/W<?php endif; ?>
				<?php if ($k['gross_margin_pct'] !== null): ?> &middot; <?= e(Presales::pct($k['gross_margin_pct'])) ?> margin<?php endif; ?>
			</span>
			<?php if ($d['install_type']): ?><span class="design-card-meta"><?= e(Projects::INSTALL_TYPES[$d['install_type']] ?? '') ?><?= $k['storage_kwh'] > 0 ? ' &middot; ' . e(number_format($k['storage_kwh'], 1)) . ' kWh storage' : '' ?></span><?php endif; ?>
			<?php if ($c['flags']['changed'] || $c['flags']['retired']): ?>
				<span class="chip chip-red">Catalog prices changed since built</span>
			<?php elseif ($c['flags']['stale']): ?>
				<span class="chip chip-orange">Check <?= (int) $c['flags']['stale'] ?> price<?= $c['flags']['stale'] > 1 ? 's' : '' ?></span>
			<?php endif; ?>
			<span class="muted small">Created <?= e(fmt_dt($d['created_at'], 'm/d/Y')) ?> &middot; Updated <?= e(fmt_dt($d['updated_at'], 'm/d/Y g:i A')) ?><?= $d['updated_by_name'] ? ' by ' . e($d['updated_by_name']) : '' ?></span>
		</a>
	<?php endforeach; ?>
</div>

<section class="card band mt" id="log">
	<h2>Log</h2>
	<form method="post" action="/presales/<?= (int) $o['id'] ?>/comments" class="comment-form">
		<?= Csrf::field() ?>
		<textarea name="body" rows="2" placeholder="Add a note..." data-enter-submit data-autogrow></textarea>
		<button class="btn btn-secondary btn-small">Post</button>
	</form>
	<?= App\View::partial('partials/activity', ['entries' => $activity, 'back' => '/presales/' . (int) $o['id'] . '#log']) ?>
</section>
