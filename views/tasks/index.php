<?php
use App\Controllers\TodoController;

$qs = static fn (array $over) => '/tasks?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'who' => $who ?: null, 'q' => $q], $over), static fn ($v) => $v !== '' && $v !== null));
?>
<div class="page-head">
	<div><h1>Tasks</h1></div>
	<a href="/tasks/new" class="btn btn-primary">Assign a task</a>
</div>

<div class="tabs">
	<?php foreach (TodoController::TABS as $key => $label): ?>
		<a href="<?= e($qs(['tab' => $key, 'who' => null, 'q' => null])) ?>" class="tab <?= $tab === $key ? 'active' : '' ?>"><?= e($label) ?> <span class="tab-count"><?= (int) $counts[$key] ?></span></a>
	<?php endforeach; ?>
</div>

<form class="filters" method="get" action="/tasks">
	<input type="search" name="q" value="<?= e($q) ?>" placeholder="Search tasks, project, ticket #">
	<?php if (in_array($tab, ['open', 'done', 'all'], true)): ?>
		<select name="who" data-autosubmit>
			<option value="">Everyone</option>
			<?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $who === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
		</select>
	<?php endif; ?>
	<button class="btn btn-secondary btn-small">Search</button>
</form>

<div class="card card-flush card-sticky-head">
	<?php if (!$rows): ?>
		<p class="empty"><?= match ($tab) { 'mine' => 'Nothing on your list.', 'assigned' => 'Everything you assigned to others is done.', 'done' => 'No completed tasks yet.', 'all' => ($q !== '' ? 'No tasks match.' : 'No tasks yet.'), default => 'No open tasks.' } ?></p>
	<?php else: ?>
		<?= App\View::partial('tasks/_list', ['rows' => $rows, 'back' => $qs([]), 'columns' => true]) ?>
	<?php endif; ?>
</div>
