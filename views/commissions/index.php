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
 */
$m = static fn ($v) => Presales::money((float) $v);
$qs = static fn (array $over) => '/commissions?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'year' => $year, 'sales' => $admin && $person ? $person : null], $over), static fn ($v) => $v !== '' && $v !== null));
$tabs = ['owed' => 'Owed now', 'notdue' => 'Not due yet', 'paid' => 'Paid', 'all' => 'All', 'cancelled' => 'Cancelled'];
$chip = ['Owed' => 'chip-orange', 'Part paid' => 'chip-orange', 'Not due yet' => 'chip-blue', 'Paid' => 'chip-green', 'Nothing owed' => 'chip-na', 'Cancelled' => 'chip-na'];
?>
<div class="page-head">
	<div>
		<h1>Sales commissions</h1>
		<p class="muted"><?= $admin ? 'Every sold job, what each salesperson is owed and what has been paid, oldest first. Commission comes due when the project reaches Closeout (PTO); closing its "Pay sales commission" step marks it paid.' : 'Your sold jobs, what you are owed and what has been paid, oldest first. Commission comes due when the project reaches Closeout (PTO).' ?><?= $person && $admin ? ' Amounts are ' . e($personName) . '\'s share.' : '' ?></p>
	</div>
</div>

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
