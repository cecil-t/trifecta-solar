<?php use App\Tasks; ?>
<div class="page-head">
	<div>
		<h1>Task template</h1>
		<p class="muted">The default task list copied into each <strong>new</strong> project. Existing projects are not changed; add items to them from the project page.</p>
	</div>
</div>

<?php foreach (Tasks::PHASES as $phase => $label): ?>
	<section class="card card-flush mt">
		<div class="card-head">
			<h2><?= e($label) ?></h2>
			<a href="/admin/template/new?phase=<?= $phase ?>" class="btn btn-ghost btn-small">+ Task</a>
		</div>
		<table class="table table-template">
			<thead><tr><th>Order</th><th>Item</th><th>Needed</th><th>Applies to</th><th>Gate</th><th>Ref label</th><th>Owner</th><th></th></tr></thead>
			<tbody>
			<?php foreach ($tree[$phase] as $t): ?>
				<?php foreach (array_merge([$t], $t['subs']) as $row): $isSub = $row['parent_id'] !== null; ?>
					<tr id="tpl-<?= (int) $row['id'] ?>" class="<?= $row['is_active'] ? '' : 'row-muted' ?> <?= $isSub ? 'tpl-sub' : 'tpl-task' ?>">
						<td class="muted"><?= (int) $row['sort_order'] ?></td>
						<td><?= $isSub ? '<span class="indent">&rsaquo;</span> ' : '<strong>' ?><?= e($row['name']) ?><?= $isSub ? '' : '</strong>' ?><?= $row['is_active'] ? '' : ' <span class="chip">Inactive</span>' ?></td>
						<td><?= $row['default_needed'] === null ? '<span class="chip chip-orange">Ask</span>' : ((int) $row['default_needed'] ? 'Yes' : 'No') ?></td>
						<td><?= e(['all' => 'All', 'pv' => 'PV jobs', 'storage' => 'Battery jobs'][$row['applies_when']]) ?></td>
						<td class="small"><?= e(Tasks::GATES[$row['gate']] ?? '') ?></td>
						<td><?= e($row['ref_label'] ?? '') ?></td>
						<td><?= e($row['owner_initials'] ?? ($isSub ? '(task)' : '')) ?></td>
						<td class="nowrap">
							<a href="/admin/template/<?= (int) $row['id'] ?>" class="btn btn-ghost btn-small">Edit</a>
							<?php if (!$isSub): ?><a href="/admin/template/new?parent=<?= (int) $row['id'] ?>" class="btn btn-ghost btn-small">+ Sub</a><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endforeach; ?>
