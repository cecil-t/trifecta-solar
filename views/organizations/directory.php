<?php use App\Controllers\OrganizationController; use App\Auth; ?>
<div class="page-head">
	<div><h1>Directory</h1><p class="muted">Third parties: code and inspection agencies, designers, contractors.<?= Auth::isAdmin() ? '' : ' Only admins can add or change these.' ?></p></div>
	<?php if (Auth::isAdmin()): ?>
	<div class="row-gap">
		<a href="/organizations/new?type=agency" class="btn btn-secondary">Add agency</a>
		<a href="/organizations/new?type=designer" class="btn btn-secondary">Add designer</a>
		<a href="/organizations/new?type=contractor" class="btn btn-secondary">Add contractor</a>
	</div>
	<?php endif; ?>
</div>
<div class="card card-flush card-sticky-head scroll-x-phone">
	<table class="table">
		<thead><tr><th>Name</th><th>Type</th><th>Phone</th><th>Email</th><th class="num">Contacts</th></tr></thead>
		<tbody>
		<?php if (!$rows): ?><tr><td colspan="5" class="empty">Nothing here yet.</td></tr><?php endif; ?>
		<?php foreach ($rows as $o): ?>
			<tr class="<?= $o['is_active'] ? '' : 'row-muted' ?>">
				<td><a href="/organizations/<?= (int) $o['id'] ?>"><?= e($o['name']) ?></a></td>
				<td><?= e(OrganizationController::TYPES[$o['type']]) ?></td>
				<td><?= e($o['phone']) ?></td>
				<td><?= e($o['email']) ?></td>
				<td class="num"><?= (int) $o['contact_count'] ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
