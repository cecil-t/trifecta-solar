<?php use App\Weather; $weatherAt = Weather::location(); ?>
<h1 class="visually-hidden">Dashboard</h1>
<?php if ($weatherAt): ?>
<div class="stat-group dash-weather"><span class="stat-group-label"><?= e($weatherAt['place']) ?></span>
	<?php if ($weather): ?>
		<div class="weather" role="group" aria-label="Weather for <?= e($weatherAt['place']) ?>">
			<?php foreach ($weather as $w): ?>
				<div class="wday">
					<?= Weather::icon($w['icon']) ?>
					<div>
						<div class="wlabel"><?= e($w['label']) ?></div>
						<div class="wtemp"><span class="hi"><?= (int) $w['hi'] ?>&deg;</span> <span class="lo"><?= (int) $w['lo'] ?>&deg;</span></div>
						<div class="wdesc"><?= e($w['desc']) ?><?= $w['pop'] !== null && $w['pop'] >= 20 ? ' &middot; ' . (int) $w['pop'] . '% precip' : '' ?></div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else: ?>
		<div class="weather weather-empty muted small">Weather unavailable</div>
	<?php endif; ?>
</div>
<?php endif; ?>

<div class="stat-group"><span class="stat-group-label">Projects</span>
<div class="stat-row">
	<a class="stat" href="/projects?phase=pre_install"><span class="stat-num"><?= $counts['pre_install'] ?></span><span class="stat-label">Pre-Install</span></a>
	<a class="stat" href="/projects?phase=installation"><span class="stat-num"><?= $counts['installation'] ?></span><span class="stat-label">Installation</span></a>
	<a class="stat" href="/projects?phase=closeout"><span class="stat-num"><?= $counts['closeout'] ?></span><span class="stat-label">Closeout</span></a>
	<a class="stat stat-purple" href="/projects?phase=on_hold"><span class="stat-num"><?= $counts['on_hold'] ?></span><span class="stat-label">On hold</span></a>
</div>
</div>
<div class="stat-group mt-sm"><span class="stat-group-label">Service</span>
<div class="stat-row">
	<a class="stat stat-service" href="/service"><span class="stat-num"><?= $service['open'] ?></span><span class="stat-label">Open service</span></a>
	<a class="stat stat-service <?= $service['to_invoice'] ? 'stat-orange' : '' ?>" href="/service?tab=to_invoice"><span class="stat-num"><?= $service['to_invoice'] ?></span><span class="stat-label">Ready to invoice</span></a>
</div>
</div>

<section class="card mt" id="my-tasks">
	<div class="card-head card-head-plain">
		<h2>My tasks <span class="muted small">(<?= count($myTasks) ?>)</span></h2>
		<div class="card-head-actions"><a href="/tasks" class="small">All tasks</a><a href="/tasks/new?back=/" class="btn btn-secondary btn-small">Assign a task</a></div>
	</div>
	<?php if (!$myTasks): ?>
		<p class="muted small">Nothing on your list.</p>
	<?php else: ?>
		<?= App\View::partial('tasks/_list', ['rows' => $myTasks, 'back' => '/#my-tasks', 'showAssignee' => false]) ?>
	<?php endif; ?>
</section>

<?php
// Group open items by project: one summary row each, items revealed on click.
$today = date('Y-m-d');
$byProject = [];
foreach ($items as $i) {
	$g = &$byProject[(int) $i['pid']];
	$g ??= ['pid' => (int) $i['pid'], 'number' => $i['project_number'], 'name' => $i['project_name'], 'items' => [], 'overdue' => 0, 'unanswered' => 0, 'next' => null];
	$g['items'][] = $i;
	if ($i['target_date'] && $i['target_date'] < $today) { $g['overdue']++; }
	if ($i['needed'] === null) { $g['unanswered']++; }
	if ($i['target_date'] && ($g['next'] === null || $i['target_date'] < $g['next'])) { $g['next'] = $i['target_date']; }
	unset($g);
}
usort($byProject, static fn ($a, $b) => [$b['overdue'] > 0, $a['next'] === null, $a['next'], $a['number']] <=> [$a['overdue'] > 0, $b['next'] === null, $b['next'], $b['number']]);
?>
<section class="card card-flush card-sticky-head mt">
	<div class="card-head">
		<h2>My open items <span class="muted small">(<?= count($items) ?> on <?= count($byProject) ?> project<?= count($byProject) === 1 ? '' : 's' ?>)</span></h2>
		<span class="muted small">Project tasks you own that are actionable now: the project has reached that phase, the target date is within 30 days, or it is the next payment. Steps from a phase the project has moved past are left out. Click a project to see its items.</span>
	</div>
	<?php if (!$items): ?>
		<p class="empty">Nothing open with your name on it.</p>
	<?php else: ?>
		<table class="table table-open">
			<thead><tr><th>Project</th><th class="num">Open</th><th class="num">Overdue</th><th class="num">Needs answer</th><th>Next target</th></tr></thead>
			<?php foreach ($byProject as $g): ?>
				<tbody class="open-group">
					<tr class="open-head" onclick="var b=this.closest('tbody').nextElementSibling;b.hidden=!b.hidden;this.classList.toggle('is-open',!b.hidden)">
						<td><span class="year-caret">&#9656;</span> <strong><?= e($g['number']) ?></strong> <?= e($g['name']) ?> <a href="/projects/<?= (int) $g['pid'] ?>" class="open-link" onclick="event.stopPropagation()">Open project &rsaquo;</a></td>
						<td class="num"><?= count($g['items']) ?></td>
						<td class="num <?= $g['overdue'] ? 'text-red' : '' ?>"><?= $g['overdue'] ?: '' ?></td>
						<td class="num"><?= $g['unanswered'] ?: '' ?></td>
						<td class="<?= $g['next'] && $g['next'] < $today ? 'text-red' : '' ?>"><?= e(fmt_date($g['next'])) ?></td>
					</tr>
				</tbody>
				<tbody class="open-items" hidden>
				<?php foreach ($g['items'] as $i): $overdue = $i['target_date'] && $i['target_date'] < $today; ?>
					<tr>
						<td class="open-item" colspan="4">
							<a href="/projects/<?= (int) $i['pid'] ?>#task-<?= (int) $i['id'] ?>"><?= $i['parent_name'] ? '<span class="muted">' . e($i['parent_name']) . ' &rsaquo;</span> ' : '' ?><?= e($i['name']) ?></a>
							<?php if ($i['needed'] === null): ?><span class="chip chip-orange">Needed?</span><?php endif; ?>
							<?php if ($i['note']): ?><span class="muted small"> &middot; <?= e($i['note']) ?></span><?php endif; ?>
						</td>
						<td class="<?= $overdue ? 'text-red' : '' ?>"><?= e(fmt_date($i['target_date'])) ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			<?php endforeach; ?>
		</table>
	<?php endif; ?>
</section>

<section class="card mt" id="latest-activity">
	<h2>Latest activity</h2>
	<?= App\View::partial('partials/activity', ['entries' => $recent, 'showProject' => true]) ?>
</section>
