<?php
use App\Commissions;
use App\Csrf;
use App\Draws;
use App\Presales;

/**
 * @var array  $u          id, name
 * @var bool   $admin
 * @var array  $sum        Draws::summary
 * @var array  $plan       Draws::trueUpPlan: apply, cash, rows (per job: c, draw, cash), left
 * @var array  $jobs       Draws::jobs: c, balance, due, state
 * @var array  $schedules
 * @var array  $lines      draws, newest first (the newest 6 shown, older folded by year)
 * @var float  $warnPct
 * @var string $dueLabel
 * @var array  $frozen     Draws::frozen: ids (lines covered by commission, read-only), through
 * @var array  $activity
 */
$m = static fn ($v) => Presales::money((float) $v);
$pct = static fn (float $v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
$job = static fn (array $c) => $c['project_id'] ? trim($c['project_number'] . ' ' . $c['project_name']) : $c['job_name'];
[$standClass, $standText] = Draws::chip($sum);
$applyTotal = array_sum(array_column($plan['apply'], 'amount'));
$cashTotal = array_sum(array_column($plan['cash'], 'amount'));
$today = date('Y-m-d');
// Draws paid: the newest few open; older ones folded by year, so the list stays short
$lineGroups = $lines ? [['year' => null, 'lines' => array_slice($lines, 0, 6)]] : [];
foreach (array_slice($lines, 6) as $l) {
	$yr = substr($l['pay_date'], 0, 4);
	$last = count($lineGroups) - 1;
	if ($lineGroups[$last]['year'] !== $yr) {
		$lineGroups[] = ['year' => $yr, 'lines' => []];
		$last++;
	}
	$lineGroups[$last]['lines'][] = $l;
}
?>
<div class="page-head">
	<div>
		<a href="/commissions<?= $admin ? '?sales=' . (int) $u['id'] : '' ?>" class="back">&larr; Sales commissions</a>
		<div class="page-kicker">Draw against commission</div>
		<h1><?= e($u['name']) ?></h1>
		<div class="badges"><span class="chip <?= e($standClass) ?>"><?= e($standText) ?></span><?php if ($sum['schedule']): ?> <span class="chip"><?= e($m($sum['schedule']['amount'])) ?> every <?= (int) $sum['schedule']['every_days'] ?> days<?= $sum['next'] ? ', next ' . e(fmt_date($sum['next'])) : '' ?></span><?php endif; ?></div>
	</div>
</div>

<div class="metrics commission-metrics">
	<div><span class="m-num"><?= e($m($sum['drawn'])) ?></span><span class="m-label">Draws paid</span></div>
	<div><span class="m-num"><?= e($m($sum['covered'])) ?></span><span class="m-label">Covered by draws</span></div>
	<div><span class="m-num"><?= e($m($sum['advance'])) ?></span><span class="m-label">Advance</span></div>
	<div><span class="m-num <?= $sum['owed'] > 0.005 ? 'text-orange' : '' ?>"><?= e($m($sum['owed'])) ?></span><span class="m-label">Due now</span></div>
	<div><span class="m-num"><?= e($m($sum['pipeline'])) ?></span><span class="m-label">Pipeline</span></div>
</div>
<p class="hint mb-xs">Advance is draws paid less commission covered by draws. Commission comes due <?= e($dueLabel) ?>. The advance left after what is due rides on the pipeline (sold jobs not due yet) and is flagged past <?= e($pct($warnPct)) ?>% of it. If the salesperson leaves, the advance comes out of what their sold jobs pay as they complete.</p>

<?php if ($admin): ?>
	<section class="card band mt">
		<h2>True-up</h2>
		<?php if ($sum['advance'] < -0.005): ?>
			<p class="text-orange">Commission marked covered by draws is <?= e($m(-$sum['advance'])) ?> more than the draws paid. Check the draw lines, or pay the difference.</p>
		<?php endif; ?>
		<?php if (!$plan['apply'] && !$plan['cash']): ?>
			<p class="muted">Nothing is due to true up.<?= $sum['ahead'] > 0.005 ? ' The ' . e($m($sum['ahead'])) . ' advance waits for the next jobs to come due.' : '' ?></p>
		<?php else: ?>
			<div class="table-wrap">
				<table class="table table-tight">
					<thead><tr><th>Due job</th><th>Signed</th><th class="num">From draws</th><th class="num">Still owed</th></tr></thead>
					<tbody>
					<?php foreach ($plan['rows'] as $r): ?>
						<tr>
							<td><a href="/commissions/<?= (int) $r['c']['id'] ?>"><?= e($job($r['c'])) ?></a></td>
							<td class="nowrap"><?= $r['c']['signed_on'] ? e(fmt_date($r['c']['signed_on'])) : '' ?></td>
							<td class="num"><?= $r['draw'] > 0.005 ? e($m($r['draw'])) : '-' ?></td>
							<td class="num <?= $r['cash'] > 0.005 ? 'text-orange' : '' ?>"><?= $r['cash'] > 0.005 ? e($m($r['cash'])) : '-' ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot><tr><td>Total</td><td></td><td class="num"><?= e($m($applyTotal)) ?></td><td class="num"><?= e($m($cashTotal)) ?></td></tr></tfoot>
				</table>
			</div>
			<form method="post" action="/commissions/draws/<?= (int) $u['id'] ?>/trueup" class="grid-form mt-sm"
				data-confirm="True up <?= e($u['name']) ?>? <?= e($m($applyTotal)) ?> of the draws covers <?= count($plan['apply']) ?> due job<?= count($plan['apply']) === 1 ? '' : 's' ?>, oldest first.">
				<?= Csrf::field() ?>
				<label>Date
					<input type="date" name="entry_date" value="<?= e($sum['last'] && $sum['last'] <= $today ? $sum['last'] : $today) ?>">
				</label>
				<?php if ($cashTotal > 0.005): ?>
					<label class="check span-2 self-end"><input type="checkbox" name="pay_cash" value="1"> Also record the <?= e($m($cashTotal)) ?> still owed as paid on this date (the catch-up, paid on payroll)</label>
				<?php endif; ?>
				<div class="self-end"><button class="btn btn-primary">True up</button></div>
			</form>
			<p class="hint mt-xs">Marks the jobs paid from the draws (and the catch-up, when checked) and closes their projects' "Pay sales commission" step once nothing is owed.<?= $plan['left'] > 0.005 ? ' ' . e($m($plan['left'])) . ' of the advance is left over for jobs not due yet.' : '' ?></p>
		<?php endif; ?>
	</section>
<?php endif; ?>

<section class="card band mt">
	<h2>Jobs with a balance</h2>
	<div class="table-wrap">
		<table class="table table-tight">
			<thead><tr><th>Job</th><th>Signed</th><th>Status</th><th class="num">Balance</th></tr></thead>
			<tbody>
			<?php if (!$jobs): ?><tr><td colspan="4" class="empty">Nothing owed on any sold job.</td></tr><?php endif; ?>
			<?php foreach ($jobs as $j): ?>
				<tr data-href="/commissions/<?= (int) $j['c']['id'] ?>">
					<td><a href="/commissions/<?= (int) $j['c']['id'] ?>"><?= e($job($j['c'])) ?></a><?= (int) $j['c']['handoff_user_id'] === (int) $u['id'] ? ' <span class="muted small">(handoff share)</span>' : '' ?></td>
					<td class="nowrap"><?= $j['c']['signed_on'] ? e(fmt_date($j['c']['signed_on'])) : '' ?></td>
					<td class="nowrap"><span class="chip <?= e(Commissions::CHIPS[$j['state']] ?? '') ?>"><?= e($j['state']) ?></span></td>
					<td class="num"><?= e($m($j['balance'])) ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<section class="card band mt">
	<h2>Draw schedule</h2>
	<div class="table-wrap">
		<table class="table table-tight">
			<thead><tr><th class="num">Amount</th><th>Every</th><th>First payroll</th><th>Last payroll</th><th>Note</th><?= $admin ? '<th></th>' : '' ?></tr></thead>
			<tbody>
			<?php if (!$schedules): ?><tr><td colspan="<?= $admin ? 6 : 5 ?>" class="empty">No schedule yet.</td></tr><?php endif; ?>
			<?php foreach ($schedules as $s): ?>
				<tr>
					<td class="num"><?= e($m($s['amount'])) ?></td>
					<td><?= (int) $s['every_days'] ?> days</td>
					<td class="nowrap"><?= e(fmt_date($s['start_date'])) ?></td>
					<td class="nowrap"><?= $s['end_date'] ? e(fmt_date($s['end_date'])) : '<span class="muted">Ongoing</span>' ?></td>
					<td class="cell-desc"><?= e((string) $s['note']) ?></td>
					<?php if ($admin): ?>
						<td>
							<details class="roof-details draw-change">
								<summary>Change</summary>
								<form method="post" action="/commissions/draws/<?= (int) $u['id'] ?>/schedules/<?= (int) $s['id'] ?>/change" class="draw-line-form mt-xs">
									<?= Csrf::field() ?>
									<input type="text" name="amount" inputmode="decimal" placeholder="New amount" aria-label="New amount">
									<input type="date" name="from_date" value="<?= e(Draws::nextDate($s) ?? $s['start_date']) ?>" aria-label="From payroll date">
									<button class="btn btn-secondary btn-small">Change amount</button>
								</form>
								<form method="post" action="/commissions/draws/<?= (int) $u['id'] ?>/schedules/<?= (int) $s['id'] ?>/stop" class="draw-line-form mt-xs">
									<?= Csrf::field() ?>
									<input type="date" name="end_date" value="<?= e($s['end_date'] ?: ($s['generated_through'] ?: $s['start_date'])) ?>" aria-label="Last payroll date">
									<button class="btn btn-secondary btn-small">Stop after this payroll</button>
								</form>
								<form method="post" action="/commissions/draws/<?= (int) $u['id'] ?>/schedules/<?= (int) $s['id'] ?>/delete" class="mt-xs" data-confirm="Delete this schedule and every draw it wrote? To keep the draws already paid, stop it instead.">
									<?= Csrf::field() ?>
									<button class="btn btn-ghost btn-small">Delete schedule</button>
								</form>
							</details>
						</td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php if ($admin): ?>
		<form method="post" action="/commissions/draws/<?= (int) $u['id'] ?>/schedules" class="grid-form mt-sm">
			<?= Csrf::field() ?>
			<label>Draw amount ($)
				<input type="text" name="amount" inputmode="decimal" required>
			</label>
			<label>First payroll date
				<input type="date" name="start_date" required>
			</label>
			<label>Every (days)
				<input type="number" name="every_days" value="14" min="1" max="31">
			</label>
			<label>Note
				<input type="text" name="note" maxlength="200">
			</label>
			<div class="span-all"><button class="btn btn-secondary"><?= $schedules ? 'Add a schedule' : 'Set up the draw' ?></button></div>
		</form>
		<p class="hint mt-xs">A draw line is written for each payroll up to today. A first payroll in the past writes the past ones too; to start from an opening balance instead, set the first payroll after it. To change the amount, use Change on the schedule.</p>
	<?php endif; ?>
</section>

<section class="card band mt">
	<h2>Draws paid</h2>
	<div class="table-wrap">
		<table class="table table-tight table-draw-lines">
			<thead><tr><th>Date</th><th>Kind</th><th class="num">Amount</th><th>Note</th><?= $admin ? '<th></th>' : '' ?></tr></thead>
			<?php if (!$lines): ?><tbody><tr><td colspan="<?= $admin ? 5 : 4 ?>" class="empty">No draws yet.</td></tr></tbody><?php endif; ?>
			<?php foreach ($lineGroups as $g): ?>
				<?php if ($g['year'] !== null): ?>
					<tbody class="year-head"><tr><td colspan="<?= $admin ? 5 : 4 ?>"><button type="button" class="year-toggle" aria-expanded="false"><span class="year-caret">&#9656;</span> <?= $g['year'] === substr($lines[0]['pay_date'], 0, 4) ? 'Earlier in ' : '' ?><?= e($g['year']) ?> <span class="tab-count"><?= count($g['lines']) ?></span> <span class="muted small"><?= e($m(array_sum(array_column($g['lines'], 'amount')))) ?></span></button></td></tr></tbody>
				<?php endif; ?>
			<tbody<?= $g['year'] !== null ? ' hidden' : '' ?>>
			<?php foreach ($g['lines'] as $l): $fid = 'draw-line-' . (int) $l['id']; ?>
				<tr<?= isset($frozen['ids'][(int) $l['id']]) ? ' class="draw-frozen"' : '' ?>>
					<?php if ($admin && !isset($frozen['ids'][(int) $l['id']])): ?>
						<td><input type="date" name="pay_date" value="<?= e($l['pay_date']) ?>" form="<?= e($fid) ?>" aria-label="Date"></td>
						<td class="nowrap"><?= $l['kind'] === 'opening' ? 'Opening balance' : ($l['schedule_id'] ? 'Scheduled' : 'Extra') ?><?= $l['schedule_id'] && $l['edited'] ? ' <span class="muted small">(edited)</span>' : '' ?></td>
						<td class="num"><input type="text" name="amount" inputmode="decimal" value="<?= e(number_format((float) $l['amount'], 2, '.', '')) ?>" form="<?= e($fid) ?>" aria-label="Amount"></td>
						<td><input type="text" name="note" value="<?= e((string) $l['note']) ?>" maxlength="200" form="<?= e($fid) ?>" aria-label="Note"></td>
						<td class="nowrap">
							<form method="post" action="/commissions/draws/<?= (int) $u['id'] ?>/lines/<?= (int) $l['id'] ?>" id="<?= e($fid) ?>" class="inline-form">
								<?= Csrf::field() ?>
								<button class="btn btn-secondary btn-small">Save</button>
							</form>
							<form method="post" action="/commissions/draws/<?= (int) $u['id'] ?>/lines/<?= (int) $l['id'] ?>/delete" class="inline-form" data-confirm="Remove this <?= $l['kind'] === 'opening' ? 'opening balance' : 'draw' ?>?<?= $l['schedule_id'] ? ' The schedule will not write it again (a skipped payroll).' : '' ?>">
								<?= Csrf::field() ?>
								<button class="btn btn-ghost btn-small" title="Remove">&times;</button>
							</form>
						</td>
					<?php else: ?>
						<td class="nowrap"><?= e(fmt_date($l['pay_date'])) ?></td>
						<td><?= $l['kind'] === 'opening' ? 'Opening balance' : ($l['schedule_id'] ? 'Scheduled' : 'Extra') ?><?= isset($frozen['ids'][(int) $l['id']]) ? ' <span class="chip chip-green" title="Already covered by commission, so it can\'t be changed">Covered</span>' : '' ?></td>
						<td class="num"><?= e($m($l['amount'])) ?></td>
						<td class="cell-desc"><?= e((string) $l['note']) ?></td>
						<?= $admin ? '<td></td>' : '' ?>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
			<?php endforeach; ?>
			<?php if (count($lines) > 1): ?><tfoot><tr><td>Total</td><td></td><td class="num"><?= e($m($sum['drawn'])) ?></td><td></td><?= $admin ? '<td></td>' : '' ?></tr></tfoot><?php endif; ?>
		</table>
	</div>
	<?php if ($admin): ?>
		<form method="post" action="/commissions/draws/<?= (int) $u['id'] ?>/lines" class="grid-form mt-sm">
			<?= Csrf::field() ?>
			<label>Kind
				<select name="kind">
					<option value="draw">Extra draw</option>
					<option value="opening">Opening balance</option>
				</select>
			</label>
			<label>Date
				<input type="date" name="pay_date" value="<?= e($today) ?>">
			</label>
			<label>Amount ($)
				<input type="text" name="amount" inputmode="decimal">
			</label>
			<label>Note
				<input type="text" name="note" maxlength="200">
			</label>
			<div class="span-all"><button class="btn btn-secondary">Add</button></div>
		</form>
		<p class="hint mt-xs">Each payroll's draw is recorded automatically; Save is only for correcting one. Commission uses the draws oldest first, and a draw it has covered is frozen (Covered). Opening balance: draws already paid before the tracker that commission has not covered yet, as of a payroll date. Only one per person; jobs already marked paid stay as they are.</p>
	<?php endif; ?>
</section>

<section class="card band mt" id="log">
	<h2>Log</h2>
	<?= App\View::partial('partials/activity', ['entries' => $activity, 'back' => '/commissions/draws/' . (int) $u['id'] . '#log']) ?>
</section>
