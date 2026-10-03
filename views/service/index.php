<?php
use App\Service;

$tabs = ['active' => 'Open', 'to_invoice' => 'Ready to invoice', 'done' => 'Done', 'all' => 'All'];
$qs = static fn (array $over) => '/service?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'q' => $q], $over), static fn ($v) => $v !== '' && $v !== null));
$statusChip = static fn (string $s) => match ($s) {
    'open' => '<span class="chip chip-blue">Open</span>',
    'scheduled' => '<span class="chip chip-blue">Scheduled</span>',
    'to_invoice' => '<span class="chip chip-orange">Ready to invoice</span>',
    default => '<span class="chip chip-green">Done</span>',
};
?>
<div class="page-head">
    <div>
        <h1>Service</h1>
    </div>
    <a href="/service/new" class="btn btn-primary">New service ticket</a>
</div>

<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= e($qs(['tab' => $key])) ?>" class="tab <?= $tab === $key ? 'active' : '' ?>">
            <?= e($label) ?> <span class="tab-count"><?= (int) ($counts[$key] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<form class="filters" method="get" action="/service">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search #, customer, description, address">
    <button class="btn btn-secondary btn-small">Search</button>
</form>

<div class="card card-flush">
    <table class="table table-service">
        <thead>
        <tr><th>#</th><th>Opened</th><th>Customer</th><th>Problem</th><th>Coverage</th><th class="num">Trips</th><th class="num">Man-hrs</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="8" class="empty"><?= $tab === 'to_invoice' ? 'Nothing waiting to be invoiced.' : 'No service tickets match.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $t): ?>
            <tr onclick="if(!event.target.closest('a'))location='/service/<?= (int) $t['id'] ?>'" class="clickable">
                <td><strong><?= e($t['ticket_number']) ?></strong></td>
                <td class="small"><?= e(fmt_date($t['opened_on'])) ?></td>
                <td>
                    <a href="/service/<?= (int) $t['id'] ?>"><?= e($t['customer_name'] ?? '') ?></a>
                    <div class="muted small"><?= e($t['site_city'] ?? '') ?><?= $t['project_number'] ? ' &middot; ' . e($t['project_number']) : ((string) $t['trifecta_install'] === '0' ? ' &middot; not our install' : '') ?></div>
                </td>
                <td class="small cell-desc"><?= e(mb_strimwidth((string) $t['description'], 0, 110, '...')) ?></td>
                <td class="small"><?= e(Service::COVERAGE[$t['coverage']] ?? '') ?></td>
                <td class="num"><?= (int) $t['trips'] ?: '' ?></td>
                <td class="num"><?= (float) $t['man_hours'] ? e(Service::hours((float) $t['man_hours'])) : '' ?></td>
                <td>
                    <?= $statusChip($t['status']) ?>
                    <?php if ($t['status'] === 'scheduled'): ?><div class="muted small"><?= e(fmt_date($t['scheduled_on'])) ?></div><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
