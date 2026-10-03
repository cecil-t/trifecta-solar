<?php use App\Csrf; ?>
<div class="page-head">
	<div><h1>Municipalities</h1><p class="muted">Townships, boroughs and cities. Anyone can add one; each name is unique within its county.</p></div>
</div>

<section class="card">
	<h2>Add a municipality</h2>
	<form method="post" action="/municipalities" class="grid-form">
		<?= Csrf::field() ?>
		<label class="span-2">Name <input type="text" name="name" placeholder="e.g. Penn Township" required></label>
		<label>County
			<select name="county_id" required>
				<option value="">Choose</option>
				<?php foreach ($counties as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name'] . ' (' . $c['state_code'] . ')') ?></option><?php endforeach; ?>
			</select>
		</label>
		<div class="self-end"><button class="btn btn-primary">Add</button></div>
	</form>
</section>

<div class="card card-flush mt">
	<table class="table">
		<thead><tr><th>Municipality</th><th>County</th><th>Zoning</th><th>Building permit</th><th>Inspections</th><th class="num">Projects</th></tr></thead>
		<tbody>
		<?php if (!$rows): ?><tr><td colspan="6" class="empty">None yet.</td></tr><?php endif; ?>
		<?php foreach ($rows as $m): ?>
			<tr>
				<td><a href="/municipalities/<?= (int) $m['id'] ?>"><?= e($m['name']) ?></a></td>
				<td><?= e($m['county'] . ', ' . $m['state_code']) ?></td>
				<?php foreach (App\Municipalities::SLOTS as $slot): $by = (string) $m[$slot['by']]; ?>
					<td class="small"><?= $by === '' ? '<span class="muted">-</span>' : e(App\Municipalities::BY[$by] ?? $by) ?></td>
				<?php endforeach; ?>
				<td class="num"><?= (int) $m['project_count'] ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
