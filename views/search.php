<?php
use App\Tasks;

$statusChip = static fn (string $s) => match ($s) {
	'open' => '<span class="chip chip-blue">Open</span>',
	'scheduled' => '<span class="chip chip-blue">Scheduled</span>',
	'to_invoice' => '<span class="chip chip-orange">Ready to invoice</span>',
	default => '<span class="chip chip-green">Completed</span>',
};
$qp = rawurlencode($q);
?>
<div class="page-head">
	<div>
		<h1>Search</h1>
		<?php if ($q !== ''): ?><p class="muted">Projects and service tickets matching &ldquo;<?= e($q) ?>&rdquo;</p><?php endif; ?>
	</div>
</div>

<form class="filters search-page-form" method="get" action="/search" role="search">
	<input type="search" name="q" value="<?= e($q) ?>" placeholder="Customer, project or ticket name, #, town" aria-label="Search projects and service">
	<button class="btn btn-secondary btn-small">Search</button>
</form>

<?php if ($q !== ''): ?>
<?php if (!$projects && !$tickets): ?>
	<div class="card empty-state">
		<p>Nothing matches &ldquo;<?= e($q) ?>&rdquo;.</p>
		<p class="muted small">Search looks at project and customer names, project and ticket numbers, municipality, and the service ticket's problem and address.</p>
	</div>
<?php endif; ?>

<?php if ($projects): ?>
<section class="card card-flush search-group">
	<div class="card-head">
		<h2>Projects <span class="tab-count"><?= count($projects) ?></span></h2>
		<a href="/projects?q=<?= $qp ?>" class="small">Open in Projects list</a>
	</div>
	<table class="table table-search">
		<thead>
		<tr><th>#</th><th>Project</th><th>Sales</th><th>Status</th></tr>
		</thead>
		<tbody>
		<?php foreach ($projects as $p): $st = $p['status']; ?>
			<tr id="project-<?= (int) $p['id'] ?>" data-href="/projects/<?= (int) $p['id'] ?>" class="clickable">
				<td><strong><?= e($p['project_number']) ?></strong></td>
				<td>
					<a href="/projects/<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a>
					<?php
					$sub = array_filter([
						$p['customer_name'] !== null && $p['customer_name'] !== $p['name'] ? $p['customer_name'] : null,
						$p['municipality_name'],
					]);
					?>
					<?php if ($sub): ?><div class="muted small"><?= e(implode(' · ', $sub)) ?></div><?php endif; ?>
				</td>
				<td class="small c-sales"><?php if ($p['sales_initials']): ?><span title="<?= e($p['sales_name']) ?>"><?= e($p['sales_initials']) ?></span><?php endif; ?></td>
				<td>
					<span class="phase phase-<?= e($st['phase']) ?>"><?= e(Tasks::PHASE_LABELS[$st['phase']]) ?></span>
					<?php if ($p['hold_state'] === 'on_hold'): ?><span class="chip chip-orange" title="<?= e($p['hold_reason']) ?>">On hold</span><?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>
<?php endif; ?>

<?php if ($tickets): ?>
<section class="card card-flush search-group">
	<div class="card-head">
		<h2>Service tickets <span class="tab-count"><?= count($tickets) ?></span></h2>
		<a href="/service?q=<?= $qp ?>" class="small">Open in Service list</a>
	</div>
	<table class="table table-search">
		<thead>
		<tr><th>#</th><th>Customer</th><th>Problem</th><th>Status</th></tr>
		</thead>
		<tbody>
		<?php foreach ($tickets as $t): ?>
			<tr id="ticket-<?= (int) $t['id'] ?>" data-href="/service/<?= (int) $t['id'] ?>" class="clickable">
				<td><strong><?= e($t['ticket_number']) ?></strong></td>
				<td>
					<a href="/service/<?= (int) $t['id'] ?>"><?= e($t['customer_name'] ?? '') ?></a>
					<div class="muted small"><?= e($t['site_city'] ?? '') ?><?= $t['project_number'] ? ' &middot; ' . e($t['project_number']) : '' ?></div>
				</td>
				<td class="small cell-desc c-desc"><?= e(mb_strimwidth((string) $t['description'], 0, 110, '...')) ?></td>
				<td>
					<?= $statusChip($t['status']) ?>
					<?php if ($t['status'] === 'scheduled'): ?><div class="muted small"><?= e(fmt_date($t['scheduled_on'])) ?></div><?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>
<?php endif; ?>
<?php endif; ?>
