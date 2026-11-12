<?php
use App\Controllers\ReportController;
use App\Tasks;

/**
 * @var array  $tiles      yes, open, done, unanswered, unanswered_pto counts
 * @var array  $rows       p (project), phase, contract, pto, rebate (task), steps, next, state, completed_on, waiting
 * @var array  $history    project id => log entries for the rebate task and comments mentioning a rebate
 * @var array  $utilities  utility id => name, for the filter
 * @var array  $filter     show, utility, q
 * @var string $today
 */
require __DIR__ . '/_helpers.php';
$tile = static fn (string $num, string $label, string $sub = '') => '<div><span class="m-num">' . $num . '</span><span class="m-label">' . e($label) . '</span>'
	. ($sub !== '' ? '<span class="muted small">' . e($sub) . '</span>' : '') . '</div>';
$phases = Tasks::PHASE_LABELS + ['on_hold' => 'On hold'];
$summary = ReportController::REBATE_SHOW[$filter['show']] . ($filter['utility'] && isset($utilities[$filter['utility']]) ? ' · ' . $utilities[$filter['utility']] : '')
	. ' · as of ' . fmt_date($today);
$who = static fn (?array $u) => $u ? '<span class="rebate-who" title="' . e($u['name']) . '">' . e((string) $u['initials']) . '</span>' : '';
?>
<img src="<?= asset('assets/img/logo-horizontal.png') ?>" alt="Trifecta Solar" class="print-only report-logo" width="190">
<div class="page-head report-head">
	<div>
		<a href="/reports" class="back no-print">&larr; Reports</a>
		<h1>Utility rebates<?= help_link('reports') ?></h1>
		<p class="report-summary"><?= e($summary) ?></p>
	</div>
	<button type="button" class="btn btn-secondary no-print" data-print>Print</button>
</div>

<section class="card card-flush report-card-body">
	<div class="metrics report-metrics">
		<?= $tile((string) $tiles['yes'], 'Projects with a rebate') ?>
		<?= $tile((string) $tiles['open'], 'Steps still open') ?>
		<?= $tile((string) $tiles['done'], 'Every step completed') ?>
		<?= $tile((string) $tiles['unanswered'], 'Question not answered', $tiles['unanswered_pto'] ? $tiles['unanswered_pto'] . ' past PTO' : '') ?>
	</div>
	<p class="hint report-hint">From the Utility rebate task in each project's Closeout phase, answered Yes. Steps marked Not needed don't count toward completion. Cancelled projects are left out.</p>
</section>

<form class="filters report-filters no-print mt" method="get" action="/reports/rebates">
	<label class="rf-wide">Show
		<select name="show" data-autosubmit>
			<?php foreach (ReportController::REBATE_SHOW as $k => $l): ?><option value="<?= $k ?>" <?= $filter['show'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
		</select>
	</label>
	<label class="rf-wide">Utility
		<select name="utility" data-autosubmit>
			<option value="">Any utility</option>
			<?php foreach ($utilities as $id => $name): ?><option value="<?= (int) $id ?>" <?= $filter['utility'] === (int) $id ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
		</select>
	</label>
	<label class="rf-wide">Search <input type="search" name="q" value="<?= e($filter['q']) ?>" placeholder="Number, name, customer, notes"></label>
	<button class="btn btn-primary btn-small">Apply</button>
</form>

<section class="card card-flush report-card-body">
	<div class="phase-title"><h2><?= count($rows) ?> project<?= count($rows) === 1 ? '' : 's' ?></h2><span class="muted small">Open first, longest since PTO first. Click a row's arrow for the rebate's history and comments.</span></div>
	<table class="table table-rebates">
		<thead><tr><th></th><th>Project</th><th>Phase</th><th>Contract signed</th><th>PTO</th><th>Rebate steps</th><th>Status</th></tr></thead>
		<tbody>
		<?php if (!$rows): ?><tr><td colspan="7" class="empty">No projects match.</td></tr><?php endif; ?>
		<?php foreach ($rows as $r): $p = $r['p']; $t = $r['rebate']; $log = $history[(int) $p['id']] ?? []; ?>
			<tr>
				<td class="nowrap"><button type="button" class="btn btn-ghost btn-small row-toggle" data-toggle-next-row aria-label="Show the rebate history for <?= e($p['project_number']) ?>">&#9662;</button></td>
				<td><a href="/projects/<?= (int) $p['id'] ?>"><strong><?= e($p['project_number']) ?></strong> <?= e($p['name']) ?></a>
					<div class="muted small"><?= e(implode(' · ', array_filter([(string) $p['customer_name'], (string) $p['utility_name'] ?: 'No utility', (string) $p['site_city']]))) ?></div>
					<?php if ($t['note'] || $t['reference'] || $t['owner']): ?>
						<div class="small"><?= $who($t['owner']) ?> <?= $t['reference'] ? '<strong>' . e($t['ref_label'] ?: 'Ref') . ':</strong> ' . e($t['reference']) . ' ' : '' ?><?= e((string) $t['note']) ?></div>
					<?php endif; ?></td>
				<td class="nowrap"><?= e($phases[$r['phase']] ?? $r['phase']) ?></td>
				<td class="nowrap"><?= e(fmt_date($r['contract'])) ?></td>
				<td class="nowrap"><?= e(fmt_date($r['pto'])) ?></td>
				<td>
					<?php if (!$r['steps']): ?><span class="muted small">No steps<?= $t['done_date'] ? '' : ', no completed date' ?></span><?php endif; ?>
					<ul class="rebate-steps">
					<?php foreach ($r['steps'] as $s): $na = (string) $s['needed'] === '0'; ?>
						<li class="<?= $na ? 'muted' : '' ?>"><span><?= e($s['name']) ?></span>
							<?php if ($na): ?><span class="chip chip-na">Not needed</span>
							<?php elseif ($s['done_date']): ?><span class="nowrap"><?= e(fmt_date($s['done_date'])) ?></span>
							<?php elseif ($s['target_date']): ?><span class="nowrap<?= $s['target_date'] < $today ? ' text-red' : '' ?>">Target <?= e(fmt_date($s['target_date'])) ?></span>
							<?php else: ?><span class="muted">Open</span><?php endif; ?>
							<?= $who($s['owner']) ?>
							<?php if ($s['note'] || $s['reference']): ?><div class="muted small"><?= $s['reference'] ? e(($s['ref_label'] ?: 'Ref') . ': ' . $s['reference']) . ' ' : '' ?><?= e((string) $s['note']) ?></div><?php endif; ?>
						</li>
					<?php endforeach; ?>
					</ul>
				</td>
				<td class="nowrap">
					<?php if ($r['state'] === 'done'): ?><span class="chip chip-green">Completed</span><?= $r['completed_on'] ? '<div class="muted small">' . e(fmt_date($r['completed_on'])) . '</div>' : '' ?>
					<?php elseif ($r['state'] === 'unanswered'): ?><span class="chip chip-orange">Not answered</span>
					<?php else: ?><span class="chip chip-blue">Open</span>
						<?php if ($r['next']): ?><div class="small">Next: <?= e($r['next']['name']) ?></div><?php endif; ?>
						<?php if ($r['waiting'] !== null): ?><div class="small<?= $r['waiting'] > 60 ? ' text-red' : ' muted' ?>"><?= (int) $r['waiting'] ?> days since PTO</div><?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr class="visit-edit-row" hidden>
				<td colspan="7">
					<?php if (!$log): ?><p class="small muted">No rebate history or comments yet.</p><?php else: ?>
						<table class="table table-compact table-rebate-log">
							<thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead>
							<tbody>
							<?php foreach ($log as $l): ?>
								<tr>
									<td class="nowrap"><?= e(fmt_dt($l['created_at'], 'm/d/Y')) ?></td>
									<td class="nowrap"><?= e((string) ($l['user_name'] ?? 'System')) ?></td>
									<td><?php if ($l['kind'] === 'change'): ?><?= e($l['field']) ?>: <?= $l['old_value'] !== null && $l['old_value'] !== '' ? '<span class="muted">' . e($l['old_value']) . '</span> &rarr; ' : '' ?><?= e($l['new_value'] !== null && $l['new_value'] !== '' ? $l['new_value'] : '(cleared)') ?>
										<?php else: ?><?= $l['kind'] === 'comment' ? '<span class="chip">Comment</span> ' : '' ?><?= nl2br(e((string) $l['body'])) ?><?php endif; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>
