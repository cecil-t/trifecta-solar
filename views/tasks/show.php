<?php
use App\Auth;
use App\Csrf;
use App\Todos;

$due = Todos::dueState($d['due_on'], $d['done_at']);
$link = Todos::linkLabel($d);
$back = '/tasks/' . (int) $d['id'];
$canDelete = Auth::isAdmin() || (int) $d['created_by'] === Auth::id() || (int) $d['assigned_to'] === Auth::id();
?>
<div class="project-head">
	<div>
		<a href="<?= e($listUrl) ?>" class="back">&larr; Tasks</a>
		<h1 class="<?= $d['done_at'] ? 'is-done-title' : '' ?>"><?= e($d['title']) ?></h1>
		<div class="badges">
			<?= $d['done_at'] ? '<span class="chip chip-green">Done ' . e(fmt_dt($d['done_at'], 'm/d/Y')) . '</span>' : '<span class="chip chip-blue">Open</span>' ?>
			<?php if ($d['due_on']): ?><span class="chip <?= $due === 'overdue' ? 'chip-red' : ($due === 'today' ? 'chip-orange' : '') ?>"><?= $due === 'overdue' ? 'Overdue: ' : 'Due ' ?><?= e(fmt_date($d['due_on'])) ?></span><?php endif; ?>
		</div>
	</div>
	<div class="head-actions">
		<form method="post" action="<?= $back ?>/toggle"><?= Csrf::field() ?><input type="hidden" name="back" value="<?= $back ?>">
			<button class="btn <?= $d['done_at'] ? 'btn-secondary' : 'btn-primary' ?>"><?= $d['done_at'] ? 'Reopen' : '&#10003; Mark done' ?></button>
		</form>
		<a href="<?= $back ?>/edit" class="btn btn-secondary">Edit</a>
	</div>
</div>

<div class="grid-2">
	<section class="card">
		<h2>Details</h2>
		<?= $d['details'] ? '<p class="desc-text">' . nl2br(e($d['details']), false) . '</p>' : '<p class="muted">No details.</p>' ?>
		<dl class="kv">
			<dt>Assigned to</dt><dd><?= e($d['assignee_name']) ?></dd>
			<dt>Assigned by</dt><dd><?= e($d['creator_name'] ?? 'System') ?> &middot; <?= e(fmt_dt($d['created_at'], 'm/d/Y')) ?></dd>
			<?php if ($link): ?><dt>For</dt><dd><a href="<?= e($link[0]) ?>"><?= e($link[1]) ?></a></dd><?php endif; ?>
			<?php if ($d['done_at']): ?><dt>Done</dt><dd><?= e(fmt_dt($d['done_at'])) ?><?= $d['done_by_name'] ? ' by ' . e($d['done_by_name']) : '' ?></dd><?php endif; ?>
		</dl>
		<?php if ($canDelete): ?>
			<form method="post" action="<?= $back ?>/delete" data-confirm="Delete this task? This cannot be undone." class="mt-sm"><?= Csrf::field() ?><button class="linklike text-red small">Delete task</button></form>
		<?php endif; ?>
	</section>

	<section class="card" id="log">
		<h2>Log</h2>
		<form method="post" action="<?= $back ?>/comments" class="comment-form">
			<?= Csrf::field() ?>
			<textarea name="body" rows="2" placeholder="Add a comment... (you can edit or delete it for 7 days)" required data-enter-submit></textarea>
			<button class="btn btn-primary btn-small">Post</button>
		</form>
		<?= App\View::partial('partials/activity', ['entries' => $activity, 'back' => $back . '#log']) ?>
	</section>
</div>
