<div class="page-head">
    <div>
        <h1>Good to see you, <?= e(explode(' ', $user['name'])[0]) ?></h1>
        <p class="muted">Where things stand across active projects.</p>
    </div>
    <a href="/projects/new" class="btn btn-primary">New project</a>
</div>

<div class="stat-row">
    <a class="stat" href="/projects?phase=pre_install"><span class="stat-num"><?= $counts['pre_install'] ?></span><span class="stat-label">Pre-Install</span></a>
    <a class="stat stat-green" href="/projects?phase=clear"><span class="stat-num"><?= $counts['clear'] ?></span><span class="stat-label">Clear to install</span></a>
    <a class="stat" href="/projects?phase=installation"><span class="stat-num"><?= $counts['installation'] ?></span><span class="stat-label">Installation</span></a>
    <a class="stat" href="/projects?phase=closeout"><span class="stat-num"><?= $counts['closeout'] ?></span><span class="stat-label">Closeout</span></a>
    <a class="stat stat-orange" href="/projects?phase=on_hold"><span class="stat-num"><?= $counts['on_hold'] ?></span><span class="stat-label">On hold</span></a>
</div>

<section class="card card-flush mt">
    <div class="card-head">
        <h2>My open items <span class="muted small">(<?= count($items) ?><?= count($items) >= 60 ? '+' : '' ?>)</span></h2>
        <span class="muted small">Items you own that are still needed or unanswered, soonest target date first.</span>
    </div>
    <?php if (!$items): ?>
        <p class="empty">Nothing open with your name on it.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Project</th><th>Item</th><th>Target</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($items as $i): ?>
                <?php $overdue = $i['target_date'] && $i['target_date'] < date('Y-m-d'); ?>
                <tr>
                    <td><a href="/projects/<?= (int) $i['pid'] ?>#task-<?= (int) $i['id'] ?>"><strong><?= e($i['project_number']) ?></strong> <?= e($i['project_name']) ?></a></td>
                    <td>
                        <?= $i['parent_name'] ? '<span class="muted">' . e($i['parent_name']) . ' &rsaquo;</span> ' : '' ?><?= e($i['name']) ?>
                        <?php if ($i['needed'] === null): ?><span class="chip chip-orange">Needed?</span><?php endif; ?>
                    </td>
                    <td class="<?= $overdue ? 'text-red' : '' ?>"><?= e(fmt_date($i['target_date'])) ?></td>
                    <td class="cell-note"><?= e($i['note']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
