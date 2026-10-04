<?php
/**
 * Task rows with a check-off box.
 * @var array  $rows   from App\Todos
 * @var string $back   where to return after checking one off
 * @var bool   $showAssignee
 * @var bool   $showLink   show the linked project / ticket
 * @var bool   $columns    Tasks page layout: Assigned to, Due and Project / Service in their own columns
 */
use App\Csrf;
use App\Todos;

$showAssignee ??= true;
$showLink ??= true;
$columns ??= false;
$preview = static function (?string $details): string {
	$text = trim(preg_replace('/\s+/u', ' ', (string) $details));
	return mb_strlen($text) > 160 ? rtrim(mb_substr($text, 0, 157)) . '...' : $text;
};
?>
<?php if ($columns): ?>
	<div class="todo-head" aria-hidden="true"><span></span><span>Task</span><span>Assigned to</span><span>Due</span><span>Project / Service</span></div>
<?php endif; ?>
<ul class="todo-list<?= $columns ? ' todo-cols' : '' ?>">
	<?php foreach ($rows as $d): $due = Todos::dueState($d['due_on'], $d['done_at']); $link = Todos::linkLabel($d); $details = $preview($d['details'] ?? null); ?>
		<li id="todo-<?= (int) $d['id'] ?>" class="todo <?= $d['done_at'] ? 'is-done' : '' ?>">
			<form method="post" action="/tasks/<?= (int) $d['id'] ?>/toggle" class="todo-check">
				<?= Csrf::field() ?>
				<input type="hidden" name="back" value="<?= e($back) ?>">
				<button class="checkbox-btn" title="<?= $d['done_at'] ? 'Reopen' : 'Mark done' ?>" aria-label="<?= $d['done_at'] ? 'Reopen' : 'Mark done' ?>: <?= e($d['title']) ?>"><?= $d['done_at'] ? '&#10003;' : '' ?></button>
			</form>
			<div class="todo-main">
				<a href="/tasks/<?= (int) $d['id'] ?>" class="todo-title"><?= e($d['title']) ?></a>
				<?php if ($details !== ''): ?><p class="todo-details"><?= e($details) ?></p><?php endif; ?>
				<?php if ($columns): ?>
					<?php if (((int) $d['created_by'] !== (int) $d['assigned_to'] && $d['creator_name']) || $d['done_at']): ?>
						<div class="todo-meta">
							<?php if ((int) $d['created_by'] !== (int) $d['assigned_to'] && $d['creator_name']): ?><span>from <?= e($d['creator_name']) ?></span><?php endif; ?>
							<?php if ($d['done_at']): ?><span>done <?= e(fmt_dt($d['done_at'], 'm/d/Y')) ?><?= $d['done_by_name'] ? ' by ' . e($d['done_by_name']) : '' ?></span><?php endif; ?>
						</div>
					<?php endif; ?>
				<?php else: ?>
					<div class="todo-meta">
						<?php if ($d['due_on']): ?><span class="due due-<?= $due ?>"><?= $due === 'overdue' ? 'Overdue' : ($due === 'today' ? 'Due today' : 'Due') ?> <?= e(fmt_date($d['due_on'])) ?></span><?php endif; ?>
						<?php if ($showAssignee): ?><span><?= e($d['assignee_name']) ?></span><?php endif; ?>
						<?php if ((int) $d['created_by'] !== (int) $d['assigned_to'] && $d['creator_name']): ?><span class="muted">from <?= e($d['creator_name']) ?></span><?php endif; ?>
						<?php if ($showLink && $link): ?><a href="<?= e($link[0]) ?>"><?= e($link[1]) ?></a><?php endif; ?>
						<?php if ($d['done_at']): ?><span class="muted">done <?= e(fmt_dt($d['done_at'], 'm/d/Y')) ?><?= $d['done_by_name'] ? ' by ' . e($d['done_by_name']) : '' ?></span><?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php if ($columns): ?>
				<div class="todo-cells">
					<span class="todo-cell todo-assignee"><?= e($d['assignee_name']) ?></span>
					<span class="todo-cell todo-due"><?php if ($d['due_on']): ?><span class="due due-<?= $due ?>"><span class="cell-label<?= $due === '' ? ' phone-only' : '' ?>"><?= $due === 'overdue' ? 'Overdue' : ($due === 'today' ? 'Due today' : 'Due') ?> </span><?= e(fmt_date($d['due_on'])) ?></span><?php endif; ?></span>
					<span class="todo-cell todo-link"><?php if ($link): ?><a href="<?= e($link[0]) ?>"><?= e($link[1]) ?></a><?php endif; ?></span>
				</div>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
