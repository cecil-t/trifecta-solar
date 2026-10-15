<?php
use App\Commission;
use App\Commissions;
use App\Csrf;
use App\Presales;

/**
 * @var array      $c         commission record with salesperson_name, handoff_name, project_number, project_name
 * @var array      $entries   payments and adjustments
 * @var array      $payees    per person: share, adjust, due, paid, balance
 * @var array|null $o         the opportunity it came from (id, name)
 * @var array|null $payStep   the project's pay-commission step
 * @var bool       $admin
 * @var array      $people    id, name
 * @var array      $activity
 */
$m = static fn ($v) => Presales::money((float) $v);
$plan = Commission::plan($c['plan']);
$due = array_sum(array_column($payees, 'due'));
$paid = array_sum(array_column($payees, 'paid'));
$state = Commissions::status($c, $due, $paid);
$chip = ['Owed' => 'chip-orange', 'Part paid' => 'chip-blue', 'Paid' => 'chip-green', 'Nothing owed' => 'chip-na', 'Cancelled' => 'chip-na'];
?>
<div class="page-head">
	<div>
		<a href="/commissions" class="back">&larr; Sales commissions</a>
		<div class="page-kicker">Sales commission</div>
		<h1><?= e($c['project_id'] ? trim($c['project_number'] . ' ' . $c['project_name']) : $c['job_name']) ?></h1>
		<div class="badges"><span class="chip <?= $chip[$state] ?? '' ?>"><?= e($state) ?></span></div>
	</div>
	<?php if ($admin): ?>
		<div class="head-actions">
			<a href="/commissions/<?= (int) $c['id'] ?>/edit" class="btn btn-secondary">Edit</a>
			<?php if ($state !== 'Cancelled' && $due - $paid > 0.005): ?>
				<?php $owedList = implode(', ', array_map(static fn ($p) => Presales::money($p['balance']) . ' to ' . $p['name'], array_filter($payees, static fn ($p) => $p['balance'] > 0.005))); ?>
				<form method="post" action="/commissions/<?= (int) $c['id'] ?>/paid" data-confirm="Mark this job paid? This records a payment dated today of what is still owed (<?= e($owedList) ?>)<?= $payStep && !$payStep['done_date'] ? ' and closes the project\'s Pay sales commission step' : '' ?>. To use another date, add the payment below instead.">
					<?= Csrf::field() ?>
					<button class="btn btn-primary">Mark paid</button>
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>

<div class="presales-top">
	<section class="card band">
		<h2>Job</h2>
		<dl class="kv">
			<?php if ($c['project_id']): ?><dt>Project</dt><dd><a href="/projects/<?= (int) $c['project_id'] ?>"><?= e(trim($c['project_number'] . ' ' . $c['project_name'])) ?></a><?php if ($payStep): ?><div class="small muted">"<?= e($payStep['name']) ?>" <?= $payStep['done_date'] ? 'done ' . e(fmt_date($payStep['done_date'])) : 'open' ?></div><?php endif; ?></dd><?php endif; ?>
			<?php if ($o): ?><dt>Opportunity</dt><dd><a href="/presales/<?= (int) $o['id'] ?>"><?= e($o['name']) ?></a><?= $c['design_id'] ? ' &middot; <a href="/presales/' . (int) $o['id'] . '/designs/' . (int) $c['design_id'] . '" class="small">sold build sheet</a>' : '' ?></dd><?php endif; ?>
			<dt>Contract signed</dt><dd><?= $c['signed_on'] ? e(fmt_date($c['signed_on'])) : '<span class="muted">Not set</span>' ?></dd>
			<dt>Salesperson</dt><dd><?= e((string) $c['salesperson_name']) ?><?= $c['handoff_name'] ? '<div class="small muted">Handed off by ' . e($c['handoff_name']) . ' (' . e(Presales::pct((float) $c['handoff_share'], 0)) . ' of the whole payout)</div>' : '' ?></dd>
			<dt>Lead source</dt><dd><?= $c['lead_source'] ? e(Presales::LEAD_SOURCES[$c['lead_source']] ?? $c['lead_source']) : '<span class="muted">Not set</span>' ?><?= $c['referrer_name'] ? ': ' . e($c['referrer_name']) : '' ?></dd>
			<dt>Plan</dt><dd><?= $plan ? e(Commission::describe($plan['commission'])) . ($c['lead_source'] === 'self_gen' ? '<div class="small muted">Self-generated: ' . e(Commission::describe($plan['self_gen'])) . '</div>' : '') : '<span class="muted">Amounts as recorded (before the tracker)</span>' ?></dd>
			<?php if ($c['notes']): ?><dt>Notes</dt><dd class="desc-text"><?= nl2br(e($c['notes'])) ?></dd><?php endif; ?>
		</dl>
	</section>
	<section class="card band">
		<h2>Amounts</h2>
		<table class="table table-tight table-amounts">
			<tbody>
			<tr><td>Commissionable amount <span class="muted small">(price less pass-through and extras)</span></td><td class="num"><?= e($m($c['contract_amount'])) ?></td></tr>
			<tr><td>Commission</td><td class="num"><?= e($m($c['commission'])) ?></td></tr>
			<tr><td>Self-generated bonus</td><td class="num"><?= e($m($c['self_gen'])) ?></td></tr>
			<tr><td>Other payouts<?= $c['payouts_note'] ? ' <span class="muted small">(' . e($c['payouts_note']) . ')</span>' : '' ?></td><td class="num"><?= e($m($c['payouts'])) ?></td></tr>
			<tr class="row-total"><td><strong>Total to pay</strong></td><td class="num"><strong><?= e($m(Commissions::total($c))) ?></strong></td></tr>
			<?php if ((float) $c['referral_bonus'] > 0): ?>
				<tr><td>Referral bonus to <?= e((string) ($c['referrer_name'] ?: 'the referrer')) ?> <span class="muted small">(<?= $c['referral_paid_on'] ? 'paid ' . e(fmt_date($c['referral_paid_on'])) : 'not paid yet' ?>, W-9 <?= $c['referral_w9'] ? 'on file' : 'needed' ?>)</span></td><td class="num"><?= e($m($c['referral_bonus'])) ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table>
	</section>
</div>

<section class="card band mt">
	<h2>Who is paid</h2>
	<div class="table-wrap">
		<table class="table table-tight">
			<thead><tr><th>Person</th><th>Role</th><th class="num">Share</th><th class="num">Adjusted</th><th class="num">Due</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead>
			<tbody>
			<?php if (!$payees): ?><tr><td colspan="7" class="empty">No salesperson set.</td></tr><?php endif; ?>
			<?php foreach ($payees as $p): ?>
				<tr>
					<td><?= e($p['name']) ?></td>
					<td><?= e($p['role']) ?></td>
					<td class="num"><?= e($m($p['share'])) ?></td>
					<td class="num"><?= abs($p['adjust']) > 0.005 ? e($m($p['adjust'])) : '-' ?></td>
					<td class="num"><strong><?= e($m($p['due'])) ?></strong></td>
					<td class="num"><?= e($m($p['paid'])) ?></td>
					<td class="num <?= $p['balance'] > 0.005 ? 'text-orange' : '' ?>"><?= e($m($p['balance'])) ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<h3 class="sub">Payments and adjustments</h3>
	<div class="table-wrap">
		<table class="table table-tight">
			<thead><tr><th>Date</th><th>Kind</th><th>For</th><th class="num">Amount</th><th>Note</th><?= $admin ? '<th></th>' : '' ?></tr></thead>
			<tbody>
			<?php if (!$entries): ?><tr><td colspan="<?= $admin ? 6 : 5 ?>" class="empty">Nothing paid yet.</td></tr><?php endif; ?>
			<?php foreach ($entries as $e): ?>
				<tr>
					<td class="nowrap"><?= e(fmt_date($e['entry_date'])) ?></td>
					<td><?= $e['kind'] === 'payment' ? 'Payment' : 'Adjustment' ?></td>
					<td><?= e((string) $e['user_name']) ?></td>
					<td class="num"><?= e($m($e['amount'])) ?></td>
					<td class="cell-desc"><?= e((string) $e['note']) ?></td>
					<?php if ($admin): ?>
						<td class="nowrap">
							<form method="post" action="/commissions/<?= (int) $c['id'] ?>/entries/<?= (int) $e['id'] ?>/delete" class="inline-form" data-confirm="Remove this <?= e($e['kind']) ?>?">
								<?= Csrf::field() ?>
								<button class="btn btn-ghost btn-small" title="Remove">&times;</button>
							</form>
						</td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php if ($admin): ?>
		<form method="post" action="/commissions/<?= (int) $c['id'] ?>/entries" class="grid-form mt-sm commission-entry">
			<?= Csrf::field() ?>
			<label>Kind
				<select name="kind">
					<option value="payment">Payment</option>
					<option value="adjustment">Adjustment</option>
				</select>
			</label>
			<label>For
				<select name="user_id">
					<?php foreach ($payees ?: [] as $p): ?><option value="<?= (int) $p['user_id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
					<?php foreach ($people as $u): if (isset($payees[(int) $u['id']])) { continue; } ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?>
				</select>
			</label>
			<label>Amount ($)
				<input type="text" name="amount" inputmode="decimal" placeholder="Negative to take back">
			</label>
			<label>Date
				<input type="date" name="entry_date" value="<?= e(date('Y-m-d')) ?>">
			</label>
			<label class="span-2">Note
				<input type="text" name="note" maxlength="300" placeholder="Required for an adjustment, e.g. build sheet and proposal didn't match">
			</label>
			<div class="self-end"><button class="btn btn-secondary">Add</button></div>
		</form>
	<?php endif; ?>
</section>

<section class="card band mt" id="log">
	<h2>Log</h2>
	<?= App\View::partial('partials/activity', ['entries' => $activity, 'back' => '/commissions/' . (int) $c['id'] . '#log']) ?>
</section>
