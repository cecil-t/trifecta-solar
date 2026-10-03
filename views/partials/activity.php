<?php
/** @var array $entries rows from App\Activity::feed() */
?>
<ol class="activity">
    <?php if (!$entries): ?>
        <li class="activity-empty">No activity yet.</li>
    <?php endif; ?>
    <?php foreach ($entries as $a): ?>
        <li class="activity-item activity-<?= e($a['kind']) ?>">
            <span class="avatar avatar-sm"><?= e($a['user_initials'] ?: ($a['user_name'] ? mb_substr($a['user_name'], 0, 1) : 'S')) ?></span>
            <div class="activity-body">
                <div class="activity-meta">
                    <strong><?= e($a['user_name'] ?? 'System') ?></strong>
                    <time datetime="<?= e($a['created_at']) ?>" title="<?= e(fmt_dt($a['created_at'])) ?>"><?= e(time_ago($a['created_at'])) ?></time>
                    <?php if ($a['edited_at']): ?><span class="muted">(edited <?= e(fmt_dt($a['edited_at'])) ?>)</span><?php endif; ?>
                </div>
                <?php if ($a['kind'] === 'change'): ?>
                    <div>changed <strong><?= e($a['field']) ?></strong>
                        from <span class="val"><?= $a['old_value'] === null || $a['old_value'] === '' ? '<em>blank</em>' : e($a['old_value']) ?></span>
                        to <span class="val"><?= $a['new_value'] === null || $a['new_value'] === '' ? '<em>blank</em>' : e($a['new_value']) ?></span></div>
                <?php else: ?>
                    <div class="<?= $a['kind'] === 'comment' ? 'comment-text' : '' ?>"><?= nl2br(e($a['body'])) ?></div>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ol>
