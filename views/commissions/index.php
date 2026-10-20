<?php
use App\Presales;

/**
 * @var array  $rows       commission records with due, paid, balance, state (for one person when filtered)
 * @var array  $total      column totals
 * @var array  $counts     owed, paid, all, cancelled
 * @var string $tab        owed | notdue | paid | all | cancelled
 * @var string $year
 * @var int    $person     filtered person (always the viewer for salespeople)
 * @var string $personName
 * @var bool   $admin
 * @var array  $people     id, name
 * @var array  $years
 * @var array  $referrals  unpaid referral bonuses (admins)
 * @var array  $draws      people on a draw (id, name + Draws::summary)
 * @var string $dueAt      closeout | final_payment
 * @var float  $warnPct
 */
$m = static fn ($v) => Presales::money((float) $v);
$qs = static fn (array $over) => '/commissions?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'year' => $year, 'sales' => $admin && $person ? $person : null], $over), static fn ($v) => $v !== '' && $v !== null));
$tabs = ['owed' => 'Owed now', 'notdue' => 'Not due yet', 'paid' => 'Paid', 'all' => 'All', 'cancelled' => 'Cancelled'];
$chip = App\Commissions::CHIPS;
?>
<div class="page-head">
	<div>
		<h1>Sales commissions</h1>
		<p class="muted"><?= $admin ? 'Every sold job, what each salesperson is owed and what has been paid, oldest first. Commission comes due ' . App\Commissions::dueLabel() . '; closing its "Pay sales commission" step marks it paid.' : 'Your sold jobs, what you are owed and what has been paid, oldest first. Commission comes due ' . App\Commissions::dueLabel() . '.' ?><?= $person && $admin ? ' Amounts are ' . e($personName) . '\'s share.' : '' ?></p>
	</div>
</div>

<?php if ($draws || ($admin && !$person)): ?>
	<section class="card band draws-card">
		<h2>Draws against commission</h2>
		<?php if ($draws): ?>
			<div class="table-wrap">
				<table class="table table-tight">
					<thead><tr><th>Salesperson</th><th class="num">Draw</th><th class="num" title="Draws paid, including any opening balance">Draws paid</th><th class="num" title="Commission covered by draws">Covered</th><th class="num" title="Draws paid less commission covered">Advance</th><th class="num" title="Commission that is due and not yet paid">Due now</th><th class="num" title="Commission on sold jobs that are not due yet">Pipeline</th><th>Standing</th></tr></thead>
					<tbody>
					<?php foreach ($draws as $d): [$dc, $dt] = App\Draws::chip($d); ?>
						<tr data-href="/commissions/draws/<?= (int) $d['id'] ?>">
							<td><a href="/commissions/draws/<?= (int) $d['id'] ?>"><strong><?= e($d['name']) ?></strong></a></td>
							<td class="num nowrap"><?= $d['schedule'] ? e($m($d['schedule']['amount'])) . '<div class="muted small">next ' . e(fmt_date($d['next'])) . '</div>' : '<span class="muted">Stopped</span>' ?></td>
							<td class="num"><?= e($m($d['drawn'])) ?></td>
							<td class="num"><?= e($m($d['covered'])) ?></td>
							<td class="num"><?= e($m($d['advance'])) ?></td>
							<td class="num"><?= e($m($d['owed'])) ?><?= $d['ready'] > 0.005 ? '<div class="small text-orange">' . e($m($d['ready'])) . ' to true up</div>' : '' ?></td>
							<td class="num"><?= e($m($d['pipeline'])) ?></td>
							<td class="nowrap"><span class="chip <?= e($dc) ?>"><?= e($dt) ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php else: ?>
			<p class="muted">No one is on a draw yet.</p>
		<?php endif; ?>
		<?php if ($admin && !$person): ?>
			<form method="get" action="/commissions/draws" class="filters draws-pick">
				<select name="user" aria-label="Salesperson">
					<?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?>
				</select>
				<button class="btn btn-secondary btn-small">Open draw</button>
				<span class="muted small">Flagged when the advance passes <?= e(rtrim(rtrim(number_format($warnPct, 1), '0'), '.')) ?>% of the pipeline.</span>
			</form>
		<?php endif; ?>
	</section>
<?php elseif ($admin && $person): ?>
	<p class="draws-card"><a href="/commissions/draws/<?= (int) $person ?>">Set up a draw for <?= e($personName) ?></a></p>
<?php endif; ?>

<div class="tabs">
	<?php foreach ($tabs as $k => $label): ?>
		<a href="<?= e($qs(['tab' => $k])) ?>" class="tab <?= $tab === $k ? 'active' : '' ?>"><?= e($label) ?> <span class="tab-count"><?= (int) $counts[$k] ?></span></a>
	<?php endforeach; ?>
</div>

<form class="filters" method="get" action="/commissions">
	<input type="hidden" name="tab" value="<?= e($tab) ?>">
	<?php if ($admin): ?>
		<select name="sales" data-autosubmit aria-label="Salesperson">
			<option value="">Everyone</option>
			<?php foreach ($people as $u): ?>
				<option value="<?= (int) $u['id'] ?>" <?= $person === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
			<?php endforeach; ?>
		</select>
	<?php endif; ?>
	<select name="year" data-autosubmit aria-label="Year signed">
		<option value="">All years</option>
		<?php foreach ($years as $y): ?>
			<option value="<?= e($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= e($y) ?></option>
		<?php endforeach; ?>
	</select>
	<button class="btn btn-secondary btn-small">Filter</button>
</form>

<div class="metrics commission-metrics">
	<div><span class="m-num"><?= e($m($total['due'])) ?></span><span class="m-label">Earned</span></div>
	<div><span class="m-num"><?= e($m($total['paid'])) ?></span><span class="m-label">Paid</span></div>
	<div><span class="m-num <?= $total['due_now'] > 0.005 ? 'text-orange' : '' ?>"><?= e($m($total['due_now'])) ?></span><span class="m-label">Owed now</span></div>
	<div><span class="m-num"><?= e($m($total['not_due'])) ?></span><span class="m-label">Not due yet</span></div>
	<div><span class="m-num"><?= count($rows) ?></span><span class="m-label">Jobs</span></div>
</div>

<div class="card card-flush card-sticky-head scroll-x-phone">
	<table class="table table-tight table-commissions">
		<thead>
		<tr><th>Signed</th><th>Job</th><th>Salesperson</th><th class="num" title="Commissionable amount: the price less pass-through items and extras">Amount</th><th class="num">Commission</th><th class="num">Self-gen</th><th class="num">Other</th><th class="num">Adjusted</th><th class="num"><?= $person ? 'Share due' : 'Due' ?></th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th></tr>
		</thead>
		<tbody>
		<?php if (!$rows): ?><tr><td colspan="12" class="empty">Nothing here.</td></tr><?php endif; ?>
		<?php foreach ($rows as $r): ?>
			<tr data-href="/commissions/<?= (int) $r['id'] ?>" id="c-<?= (int) $r['id'] ?>">
				<td class="nowrap"><?= $r['signed_on'] ? e(fmt_date($r['signed_on'])) : '<span class="muted">Not set</span>' ?></td>
				<td>
					<a href="/commissions/<?= (int) $r['id'] ?>"><strong><?= e($r['project_id'] ? trim($r['project_number'] . ' ' . $r['project_name']) : $r['job_name']) ?></strong></a>
					<?php if ($r['lead_source']): ?><div class="muted small"><?= e(Presales::LEAD_SOURCES[$r['lead_source']] ?? '') ?><?= $r['referrer_name'] ? ': ' . e($r['referrer_name']) : '' ?></div><?php endif; ?>
				</td>
				<td><?= e((string) $r['salesperson_name']) ?><?= $r['handoff_name'] ? '<div class="muted small">' . e(Presales::pct((float) $r['handoff_share'], 0)) . ' to ' . e($r['handoff_name']) . '</div>' : '' ?></td>
				<td class="num"><?= e($m($r['contract_amount'])) ?></td>
				<td class="num"><?= e($m($r['commission'])) ?></td>
				<td class="num"><?= (float) $r['self_gen'] > 0 ? e($m($r['self_gen'])) : '-' ?></td>
				<td class="num"<?= $r['payouts_note'] ? ' title="' . e($r['payouts_note']) . '"' : '' ?>><?= (float) $r['payouts'] > 0 ? e($m($r['payouts'])) : '-' ?></td>
				<td class="num"><?= abs($r['adjust']) > 0.005 ? e($m($r['adjust'])) : '-' ?></td>
				<td class="num"><strong><?= e($m($r['due'])) ?></strong></td>
				<td class="num"><?= e($m($r['paid'])) ?></td>
				<td class="num <?= $r['balance'] > 0.005 && $r['state'] !== 'Not due yet' ? 'text-orange' : '' ?>"><?= e($m($r['balance'])) ?></td>
				<td class="nowrap"><span class="chip <?= $chip[$r['state']] ?? '' ?>"<?= $r['phase'] && $r['state'] === 'Not due yet' ? ' title="Project is in ' . e(App\Tasks::PHASE_LABELS[$r['phase']] ?? '') . '; due at Closeout"' : '' ?>><?= e($r['state']) ?></span></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
		<?php if (count($rows) > 1): ?>
			<tfoot><tr><td></td><td>Total</td><td></td><td class="num"><?= e($m($total['contract'])) ?></td><td class="num"><?= e($m($total['commission'])) ?></td><td class="num"><?= e($m($total['self_gen'])) ?></td><td class="num"><?= e($m($total['payouts'])) ?></td><td class="num"><?= e($m($total['adjust'])) ?></td><td class="num"><?= e($m($total['due'])) ?></td><td class="num"><?= e($m($total['paid'])) ?></td><td class="num"><?= e($m($total['balance'])) ?></td><td></td></tr></tfoot>
		<?php endif; ?>
	</table>
</div>
<?php if ($person): ?><p class="hint mt-xs">Commission, self-gen and other are the whole job's; due, paid and balance are <?= $admin ? e($personName) . '\'s' : 'yours' ?> after any in-house split.</p><?php endif; ?>

<?php if ($admin && $referrals): ?>
	<h2 class="sub mt">Referral bonuses to pay</h2>
	<div class="card card-flush">
		<table class="table table-tight">
			<thead><tr><th>Job</th><th>Referred by</th><th class="num">Bonus</th><th>W-9</th></tr></thead>
			<tbody>
			<?php foreach ($referrals as $f): ?>
				<tr data-href="/commissions/<?= (int) $f['id'] ?>">
					<td><a href="/commissions/<?= (int) $f['id'] ?>"><?= e($f['project_name'] ? trim($f['project_number'] . ' ' . $f['project_name']) : $f['job_name']) ?></a></td>
					<td><?= e((string) $f['referrer_name']) ?></td>
					<td class="num"><?= e($m($f['referral_bonus'])) ?></td>
					<td><?= $f['referral_w9'] ? 'On file' : '<span class="text-orange">Needed</span>' ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>

<?php if ($admin): ?>
	<details class="card band mt settings-fold" id="settings">
		<summary><strong>Commission settings</strong> <span class="muted small">Due at <?= e(App\Draws::DUE_AT[$dueAt]) ?>; draw advance flagged past <?= e(rtrim(rtrim(number_format($warnPct, 1), '0'), '.')) ?>% of pipeline</span></summary>
		<form method="post" action="/commissions/settings" class="grid-form mt-sm">
			<?= App\Csrf::field() ?>
			<label>Commission comes due at
				<select name="commission_due_at">
					<?php foreach (App\Draws::DUE_AT as $k => $label): ?><option value="<?= e($k) ?>" <?= $dueAt === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
				</select>
			</label>
			<label>Flag a draw advance past (% of pipeline)
				<input type="text" name="draw_warn_pct" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format($warnPct, 1, '.', ''), '0'), '.')) ?>">
			</label>
			<div class="self-end"><button class="btn btn-secondary">Save</button></div>
		</form>
		<p class="hint">Final payment received means every payment step under the project's Payments is checked off (or marked not needed), so those need to be kept up to date. A job in Closeout with a payment still open shows "Waiting on final payment". A project with no payment steps, or one marked Completed, counts as due at Closeout either way.</p>
	</details>
<?php endif; ?>
