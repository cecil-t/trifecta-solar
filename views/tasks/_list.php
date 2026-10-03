<?php
/**
 * Task rows with a check-off box.
 * @var array  $rows   from App\Todos
 * @var string $back   where to return after checking one off
 * @var bool   $showAssignee
 * @var bool   $showLink   show the linked project / ticket
 */
use App\Csrf;
use App\Todos;

$showAssignee ??= true;
$showLink ??= true;
?>
<ul class="todo-list">
	<?php foreach ($rows as $d): $due = Todos::dueState($d['due_on'], $d['done_at']); $link = Todos::linkLabel($d); ?>
		<li class="todo <?= $d['done_at'] ? 'is-done' : '' ?>">
			<form method="post" action="/tasks/<?= (int) $d['id'] ?>/toggle" class="todo-check">
				<?= Csrf::field() ?>
				<input type="hidden" name="back" value="<?= e($back) ?>">
				<button class="checkbox-btn" title="<?= $d['done_at'] ? 'Reopen' : 'Mark done' ?>" aria-label="<?= $d['done_at'] ? 'Reopen' : 'Mark done' ?>: <?= e($d['title']) ?>"><?= $d['done_at'] ? '&#10003;' : '' ?></button>
			</form>
			<div class="todo-main">
				<a href="/tasks/<?= (int) $d['id'] ?>" class="todo-title"><?= e($d['title']) ?></a>
				<div class="todo-meta">
					<?php if ($d['due_on']): ?><span class="due due-<?= $due ?>"><?= $due === 'overdue' ? 'Overdue' : ($due === 'today' ? 'Due today' : 'Due') ?> <?= e(fmt_date($d['due_on'])) ?></span><?php endif; ?>
					<?php if ($showAssignee): ?><span><?= e($d['assignee_name']) ?></span><?php endif; ?>
					<?php if ((int) $d['created_by'] !== (int) $d['assigned_to'] && $d['creator_name']): ?><span class="muted">from <?= e($d['creator_name']) ?></span><?php endif; ?>
					<?php if ($showLink && $link): ?><a href="<?= e($link[0]) ?>"><?= e($link[1]) ?></a><?php endif; ?>
					<?php if ($d['done_at']): ?><span class="muted">done <?= e(fmt_dt($d['done_at'], 'm/d/Y')) ?><?= $d['done_by_name'] ? ' by ' . e($d['done_by_name']) : '' ?></span><?php endif; ?>
				</div>
			</div>
		</li>
	<?php endforeach; ?>
</ul>
