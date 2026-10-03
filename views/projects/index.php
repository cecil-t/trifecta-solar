<?php
use App\Tasks;
use App\Projects;

$tabs = [
    'active' => 'Active', 'pre_install' => 'Pre-Install', 'clear' => 'Clear to install',
    'installation' => 'Installation', 'closeout' => 'Closeout', 'on_hold' => 'On hold',
    'complete' => 'Complete', 'cancelled' => 'Cancelled', 'all' => 'All',
];
$qs = static fn (array $over) => '/projects?' . http_build_query(array_filter(array_merge($filter, $over), static fn ($v) => $v !== '' && $v !== 0 && $v !== null));
?>
<div class="page-head">
    <div>
        <h1>Projects</h1>
    </div>
    <a href="/projects/new" class="btn btn-primary">New project</a>
</div>

<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= e($qs(['phase' => $key])) ?>" class="tab <?= $filter['phase'] === $key ? 'active' : '' ?>">
            <?= e($label) ?> <span class="tab-count"><?= (int) ($counts[$key] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<form class="filters" method="get" action="/projects">
    <input type="hidden" name="phase" value="<?= e($filter['phase']) ?>">
    <input type="search" name="q" value="<?= e($filter['q']) ?>" placeholder="Search name, #, customer, municipality">
    <select name="sales" onchange="this.form.submit()">
        <option value="">All salespeople</option>
        <?php foreach ($users as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= $filter['sales'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary btn-small">Filter</button>
</form>

<div class="card card-flush">
    <table class="table table-projects">
        <thead>
        <tr><th>#</th><th>Project</th><th>Sales</th><th class="num">kW DC</th><th>Type</th><th>Municipality</th><th>Status</th><th class="num">Days</th></tr>
        </thead>
        <tbody>
        <?php if (!$projects): ?>
            <tr><td colspan="8" class="empty">No projects match.</td></tr>
        <?php endif; ?>
        <?php foreach ($projects as $p): $st = $p['status']; ?>
            <tr onclick="if(!event.target.closest('a'))location='/projects/<?= (int) $p['id'] ?>'" class="clickable">
                <td><strong><?= e($p['project_number']) ?></strong></td>
                <td>
                    <a href="/projects/<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a>
                    <?php if ($p['status_note']): ?><div class="muted small cell-note"><?= e($p['status_note']) ?></div><?php endif; ?>
                </td>
                <td><?= e($p['sales_initials'] ?? '') ?></td>
                <td class="num"><?= $p['dc_kw'] !== null ? number_format($p['dc_kw'], 1) : '' ?></td>
                <td class="small"><?= e(Projects::CUSTOMER_TYPES[$p['customer_type']] ?? '') ?><?= $p['install_type'] ? '<div class="muted">' . e(Projects::INSTALL_TYPES[$p['install_type']]) . '</div>' : '' ?></td>
                <td class="small"><?= e($p['municipality_name'] ?? '') ?><?= $p['county_name'] ? '<div class="muted">' . e($p['county_name']) . ' Co.</div>' : '' ?></td>
                <td>
                    <span class="phase phase-<?= e($st['phase']) ?>"><?= e(Tasks::PHASE_LABELS[$st['phase']]) ?></span>
                    <?php if ($p['hold_state'] === 'on_hold'): ?><span class="chip chip-orange" title="<?= e($p['hold_reason']) ?>">On hold</span><?php endif; ?>
                    <?php if ($st['phase'] === 'pre_install' && $st['clear_to_install']): ?><span class="chip chip-green">Clear to install</span><?php endif; ?>
                </td>
                <td class="num"><?= $st['days'] ?? '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
