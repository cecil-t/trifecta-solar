<?php
use App\Csrf;
use App\Projects;
use App\Service;

$status = Service::status($t);
$site = Service::siteLine($t);
$back = '/service/' . (int) $t['id'];
$statusChip = match ($status) {
    'open' => '<span class="chip chip-blue">Open</span>',
    'scheduled' => '<span class="chip chip-blue">Scheduled ' . e(fmt_date($t['scheduled_on'])) . '</span>',
    'to_invoice' => '<span class="chip chip-orange">Ready to invoice</span>',
    default => '<span class="chip chip-green">Completed</span>',
};
$muted = static fn (string $s) => '<span class="muted">' . e($s) . '</span>';
?>
<div class="project-head">
    <div>
        <a href="/service" class="back">&larr; Service</a>
        <h1><span class="pnum"><?= e($t['ticket_number']) ?></span> <?= e($t['customer_name'] ?? '') ?></h1>
        <div class="badges">
            <?= $statusChip ?>
            <?php if ($t['coverage']): ?><span class="chip"><?= e(Service::COVERAGE[$t['coverage']]) ?></span><?php endif; ?>
            <?php if ((string) $t['trifecta_install'] === '0'): ?><span class="chip chip-purple">Not our install</span><?php endif; ?>
        </div>
    </div>
    <div class="head-actions">
        <?php if ($t['drive_url']): ?>
            <a href="<?= e($t['drive_url']) ?>" target="_blank" rel="noopener" class="btn btn-secondary">Google Drive service folder &#8599;</a>
        <?php endif; ?>
        <?php if ($t['monitoring_url']): ?>
            <a href="<?= e($t['monitoring_url']) ?>" target="_blank" rel="noopener" class="btn btn-secondary">Monitoring portal &#8599;</a>
        <?php endif; ?>
        <?php if (!$t['drive_url'] || !$t['monitoring_url']): ?>
            <a href="<?= $back ?>/edit" class="btn btn-ghost">+ Add <?= !$t['drive_url'] && !$t['monitoring_url'] ? 'Drive / monitoring links' : (!$t['drive_url'] ? 'Drive folder link' : 'monitoring link') ?></a>
        <?php endif; ?>
        <a href="<?= $back ?>/edit" class="btn btn-primary">Edit</a>
    </div>
</div>

<div class="summary-grid summary-3">
    <section class="card">
        <h2>Request</h2>
        <p class="desc-text"><?= nl2br(e($t['description'])) ?></p>
        <dl class="kv">
            <dt>Opened</dt><dd><?= e(fmt_date($t['opened_on'])) ?></dd>
            <dt>Came from</dt><dd><?= $t['source'] ? e(Service::SOURCES[$t['source']]) : $muted('Not set') ?></dd>
            <dt>Owner</dt><dd><?= $t['owner_name'] ? e($t['owner_name']) : $muted('Not set') ?></dd>
            <?php if ($t['scheduled_on'] && !$t['completed_on']): ?><dt>Scheduled</dt><dd><?= e(fmt_date($t['scheduled_on'])) ?></dd><?php endif; ?>
            <dt>Completed</dt><dd><?= $t['completed_on'] ? e(fmt_date($t['completed_on'])) : $muted('Not yet') ?></dd>
        </dl>
    </section>

    <section class="card">
        <h2>Customer and site</h2>
        <dl class="kv">
            <dt>Customer</dt><dd><?= $t['customer_id'] ? '<a href="/organizations/' . (int) $t['customer_id'] . '">' . e($t['customer_name']) . '</a>' : $muted('Not set') ?></dd>
            <?php foreach (array_slice($contacts, 0, 2) as $c): ?>
                <dt><?= e($c['role'] ?: 'Contact') ?></dt>
                <dd><?= e($c['name']) ?><?php if ($c['phone']): ?> &middot; <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a><?php endif; ?></dd>
            <?php endforeach; ?>
            <dt>Site</dt><dd><?= $site !== '' ? '<a href="https://www.google.com/maps/search/?api=1&amp;query=' . e(rawurlencode($site)) . '" target="_blank" rel="noopener">' . e($site) . ' &#8599;</a>' : $muted('Not set') ?></dd>
            <dt>Installed by</dt><dd><?= match ((string) $t['trifecta_install']) { '1' => 'Trifecta', '0' => 'Another installer', default => $muted('Unknown') } ?></dd>
            <?php if ($t['project_id']): ?><dt>Project</dt><dd><a href="/projects/<?= (int) $t['project_id'] ?>"><?= e($t['project_number'] . ' ' . $t['project_name']) ?></a></dd><?php endif; ?>
        </dl>
        <?php if ($history): ?>
            <h3 class="sub">Other service for this customer</h3>
            <ul class="plain small">
                <?php foreach ($history as $h): ?><li><a href="/service/<?= (int) $h['id'] ?>"><?= e($h['ticket_number']) ?></a> <?= e(fmt_date($h['opened_on'])) ?> &middot; <?= e(mb_strimwidth($h['description'], 0, 60, '...')) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Billing</h2>
        <div class="metrics">
            <div><span class="m-num"><?= (int) $t['trips'] ?></span><span class="m-label">Trips</span></div>
            <div><span class="m-num"><?= e(Service::hours((float) $t['man_hours'])) ?: '0' ?></span><span class="m-label">Man-hours</span></div>
        </div>
        <dl class="kv">
            <dt>Coverage</dt><dd><?= $t['coverage'] ? e(Service::COVERAGE[$t['coverage']]) : $muted('Not set') ?></dd>
            <dt>Amount</dt><dd><?= $t['bill_amount_cents'] !== null ? '<strong class="amount">' . e(Projects::money((int) $t['bill_amount_cents'])) . '</strong>' : $muted(Service::needsInvoice($t) ? 'Not set' : 'None') ?></dd>
            <?php if ($t['billing_note']): ?><dt>Note</dt><dd><?= e($t['billing_note']) ?></dd><?php endif; ?>
            <dt>Invoiced</dt><dd><?= (int) $t['invoiced'] ? 'Yes' . ($t['invoiced_on'] ? ' ' . e(fmt_date($t['invoiced_on'])) : '') . ($t['invoice_number'] ? ' &middot; #' . e($t['invoice_number']) : '') : (Service::needsInvoice($t) ? 'No' : $muted('Not needed')) ?></dd>
        </dl>

        <?php if (!$t['completed_on']): ?>
            <form method="post" action="<?= $back ?>/quick" class="quick-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="complete">
                <input type="date" name="completed_on" value="<?= date('Y-m-d') ?>" aria-label="Completed date">
                <button class="btn btn-secondary btn-small">Mark completed</button>
            </form>
        <?php elseif ($status === 'to_invoice'): ?>
            <form method="post" action="<?= $back ?>/quick" class="quick-form quick-invoice">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="invoiced">
                <input type="text" name="bill_amount" value="<?= $t['bill_amount_cents'] !== null ? e(number_format($t['bill_amount_cents'] / 100, 2, '.', '')) : '' ?>" placeholder="Amount" inputmode="decimal" aria-label="Amount billed">
                <input type="text" name="invoice_number" placeholder="Invoice #" aria-label="Invoice number">
                <input type="date" name="invoiced_on" value="<?= date('Y-m-d') ?>" aria-label="Invoice date">
                <button class="btn btn-primary btn-small">Mark invoiced</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<section class="card task-strip" id="todos">
    <div class="task-strip-head">
        <h2>Tasks <span class="muted small">(<?= count($todos) ?> open)</span></h2>
        <a href="/tasks/new?service=<?= (int) $t['id'] ?>&amp;back=<?= e(rawurlencode('/service/' . (int) $t['id'] . '#todos')) ?>" class="btn btn-ghost btn-small">+ Assign a task</a>
    </div>
    <?php if ($todos): ?><?= App\View::partial('tasks/_list', ['rows' => $todos, 'back' => '/service/' . (int) $t['id'] . '#todos', 'showLink' => false]) ?><?php endif; ?>
</section>

<section class="card card-flush" id="visits">
    <div class="phase-title">
        <h2>Visits</h2>
        <span class="muted small">Man-hours are on-site time for everyone who went (2 people for 3 hours = 6). Travel is covered by the trip.</span>
    </div>
    <table class="table table-visits">
        <thead><tr><th>Date</th><th>Who went</th><th class="num">Man-hrs</th><th class="num">Trips</th><th>What was done</th><th></th></tr></thead>
        <tbody>
        <?php if (!$visits): ?><tr><td colspan="6" class="empty">No visits yet.</td></tr><?php endif; ?>
        <?php foreach ($visits as $vi): ?>
            <tr>
                <td class="nowrap"><?= $vi['visit_date'] ? e(fmt_date($vi['visit_date'])) : $muted('No date') ?></td>
                <td><?= e($vi['crew'] ?? '') ?></td>
                <td class="num"><?= $vi['man_hours'] !== null ? e(Service::hours((float) $vi['man_hours'])) : '' ?></td>
                <td class="num"><?= (int) $vi['trips'] ?></td>
                <td class="small"><?= nl2br(e($vi['note'] ?? '')) ?></td>
                <td class="nowrap"><button type="button" class="btn btn-ghost btn-small" onclick="var r=this.closest('tr').nextElementSibling;r.hidden=!r.hidden">Edit</button></td>
            </tr>
            <tr class="visit-edit-row" hidden>
                <td colspan="6">
                    <form method="post" action="<?= $back ?>/visits/<?= (int) $vi['id'] ?>" class="visit-add visit-edit-form">
                        <?= Csrf::field() ?>
                        <label>Date <input type="date" name="visit_date" value="<?= e($vi['visit_date']) ?>"></label>
                        <label class="grow">Who went <input type="text" name="crew" value="<?= e($vi['crew']) ?>"></label>
                        <label class="short">Man-hours <input type="text" name="man_hours" value="<?= e($vi['man_hours'] !== null ? Service::hours((float) $vi['man_hours']) : '') ?>" inputmode="decimal"></label>
                        <label class="short">Trips <input type="number" name="trips" value="<?= (int) $vi['trips'] ?>" min="0"></label>
                        <label class="grow-2">What was done <input type="text" name="note" value="<?= e($vi['note']) ?>"></label>
                        <button class="btn btn-primary btn-small">Save</button>
                        <button class="btn btn-ghost btn-small text-red" formaction="<?= $back ?>/visits/<?= (int) $vi['id'] ?>/delete" onclick="return confirm('Remove this visit?')">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if (count($visits) > 1): ?>
            <tfoot><tr><td colspan="2"><strong>Total</strong></td><td class="num"><strong><?= e(Service::hours((float) $t['man_hours'])) ?></strong></td><td class="num"><strong><?= (int) $t['trips'] ?></strong></td><td colspan="2"></td></tr></tfoot>
        <?php endif; ?>
    </table>
    <form method="post" action="<?= $back ?>/visits" class="visit-add">
        <?= Csrf::field() ?>
        <label>Date <input type="date" name="visit_date" value="<?= date('Y-m-d') ?>"></label>
        <label class="grow">Who went <input type="text" name="crew" placeholder="e.g. Staff, Crew Member"></label>
        <label class="short">Man-hours <input type="text" name="man_hours" inputmode="decimal" placeholder="2"></label>
        <label class="short">Trips <input type="number" name="trips" value="1" min="0"></label>
        <label class="grow-2">What was done <input type="text" name="note" placeholder="e.g. Replaced bad MC4 on string 3"></label>
        <button class="btn btn-secondary btn-small">Add visit</button>
    </form>
</section>

<section class="card mt" id="log">
    <div class="log-head">
        <h2>Log</h2>
        <div class="seg">
            <a href="<?= $back ?>#log" class="<?= $commentsOnly ? '' : 'active' ?>">All activity</a>
            <a href="<?= $back ?>?log=comments#log" class="<?= $commentsOnly ? 'active' : '' ?>">Comments only</a>
        </div>
    </div>
    <form method="post" action="<?= $back ?>/comments" class="comment-form">
        <?= Csrf::field() ?>
        <textarea name="body" rows="2" placeholder="Add a comment... (you can edit or delete it for 7 days)" required></textarea>
        <button class="btn btn-primary btn-small">Post</button>
    </form>
    <?= App\View::partial('partials/activity', ['entries' => $activity, 'back' => $back . '#log']) ?>
</section>
