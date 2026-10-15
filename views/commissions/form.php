<?php
use App\Commission;
use App\Csrf;
use App\Presales;

/**
 * @var array      $c       record or draft (or the form as typed)
 * @var array|null $p       the project
 * @var array      $opps    sold opportunities to pick from (new records)
 * @var int        $oppId   the picked opportunity
 * @var array      $people  id, name
 * @var bool       $isNew
 */
$v = static fn (string $k, $d = '') => $c[$k] ?? $d;
$sel = static fn ($a, $b) => (string) $a === (string) $b && (string) $a !== '' ? 'selected' : '';
$num = static fn ($x) => $x === '' || $x === null ? '' : number_format((float) $x, 2, '.', '');
$plan = Commission::plan((string) $v('plan'));
$back = $isNew ? '/projects/' . (int) $p['id'] : '/commissions/' . (int) $c['id'];
?>
<div class="page-head">
	<div>
		<a href="<?= e($back) ?>" class="back">&larr; <?= $isNew ? e($p['name']) : 'Commission' ?></a>
		<h1><?= $isNew ? 'Set up sales commission' : 'Edit sales commission' ?></h1>
		<?php if ($p): ?><p class="muted"><?= e(trim($p['project_number'] . ' ' . $p['name'])) ?></p><?php endif; ?>
	</div>
</div>

<?php if ($isNew): ?>
	<form method="get" action="/projects/<?= (int) $p['id'] ?>/commission/new" class="card band">
		<h2>Fill in from the sale</h2>
		<div class="grid-form">
			<label class="span-2">Sold opportunity
				<select name="opportunity" data-autosubmit>
					<option value="0">None: enter it by hand</option>
					<?php foreach ($opps as $op): ?>
						<option value="<?= (int) $op['id'] ?>" <?= $sel($oppId, $op['id']) ?>><?= e($op['name']) ?><?= $op['salesperson_name'] ? ' (' . e($op['salesperson_name']) . ')' : '' ?></option>
					<?php endforeach; ?>
				</select>
				<small class="hint">Takes the commissionable amount, pays-the-salesperson items and plan from its sold build sheet, and the lead source and handoff from the opportunity. Links the opportunity to this project.</small>
			</label>
			<div class="self-end"><button class="btn btn-secondary btn-small">Fill in</button></div>
		</div>
	</form>
<?php endif; ?>

<form method="post" action="<?= $isNew ? '/projects/' . (int) $p['id'] . '/commission' : '/commissions/' . (int) $c['id'] ?>" class="project-form">
	<?= Csrf::field() ?>
	<?php if ($isNew): ?>
		<input type="hidden" name="opportunity_id" value="<?= (int) $v('opportunity_id', 0) ?>">
		<input type="hidden" name="design_id" value="<?= (int) $v('design_id', 0) ?>">
		<input type="hidden" name="plan" value="<?= e((string) $v('plan')) ?>">
	<?php endif; ?>
	<section class="card band">
		<h2>Job</h2>
		<div class="grid-form">
			<?php if (!$p): ?><label class="span-2">Job <input type="text" name="job_name" value="<?= e((string) $v('job_name')) ?>" required></label><?php endif; ?>
			<label>Contract signed <input type="date" name="signed_on" value="<?= e((string) $v('signed_on')) ?>"></label>
			<label>Salesperson
				<select name="salesperson_id">
					<option value="">Choose</option>
					<?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $sel($v('salesperson_id'), $u['id']) ?>><?= e($u['name']) ?></option><?php endforeach; ?>
				</select>
			</label>
			<label>Handed off by
				<select name="handoff_user_id">
					<option value="">Nobody</option>
					<?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $sel($v('handoff_user_id'), $u['id']) ?>><?= e($u['name']) ?></option><?php endforeach; ?>
				</select>
				<small class="hint">Gets <?= $plan ? e(Presales::pct($plan['handoff_share'], 0)) : 'the handoff share' ?> of the whole payout.</small>
			</label>
			<label>Lead source
				<select name="lead_source">
					<option value="">Choose</option>
					<?php foreach (Presales::LEAD_SOURCES as $k => $label): ?><option value="<?= $k ?>" <?= $sel($v('lead_source'), $k) ?>><?= e($label) ?></option><?php endforeach; ?>
				</select>
			</label>
			<label>Referred by <input type="text" name="referrer_name" value="<?= e((string) $v('referrer_name')) ?>" maxlength="200"></label>
			<label>Status
				<select name="status">
					<option value="active" <?= $sel($v('status', 'active'), 'active') ?>>Active</option>
					<option value="cancelled" <?= $sel($v('status'), 'cancelled') ?>>Cancelled (job cancelled)</option>
				</select>
			</label>
		</div>
	</section>

	<section class="card band">
		<h2>Amounts</h2>
		<div class="grid-form">
			<label>Commissionable amount ($)
				<input type="text" name="contract_amount" value="<?= e($num($v('contract_amount'))) ?>" inputmode="decimal" required>
				<small class="hint">The contract price less pass-through items and extras without markup. Update it when a change order changes the price.</small>
			</label>
			<?php if ($plan): ?>
				<div class="checkfield span-2">
					<span class="field-label">Commission and bonus</span>
					<span class="boxwrap"><?= e(Commission::describe($plan['commission'])) ?></span>
					<small class="hint">Figured from this plan when you save. Self-generated bonus (net-new customers): <?= e(Commission::describe($plan['self_gen'])) ?>.</small>
				</div>
			<?php else: ?>
				<label>Commission ($) <input type="text" name="commission" value="<?= e($num($v('commission'))) ?>" inputmode="decimal"></label>
				<label>Self-generated bonus ($) <input type="text" name="self_gen" value="<?= e($num($v('self_gen'))) ?>" inputmode="decimal"></label>
				<label>Handoff share (%) <input type="text" name="handoff_share" value="<?= e((string) round((float) $v('handoff_share', 0) * 100, 2)) ?>" inputmode="decimal"></label>
			<?php endif; ?>
			<label>Other payouts ($)
				<input type="text" name="payouts" value="<?= e($num($v('payouts'))) ?>" inputmode="decimal">
				<small class="hint">Site visit, REAP grant paperwork and other pays-the-salesperson items.</small>
			</label>
			<label class="span-2">Other payouts note <input type="text" name="payouts_note" value="<?= e((string) $v('payouts_note')) ?>" maxlength="300" placeholder="e.g. Site visit, REAP grant paperwork"></label>
		</div>
	</section>

	<section class="card band">
		<h2>Referral bonus</h2>
		<div class="grid-form">
			<label>Referral bonus ($)
				<input type="text" name="referral_bonus" value="<?= e($num($v('referral_bonus'))) ?>" inputmode="decimal" placeholder="<?= $plan ? e($num($plan['referral_bonus'])) . ' for a referral' : '' ?>">
				<small class="hint">Owed to the referrer, not the salesperson. Blank uses the plan's amount when the lead source is Referral.</small>
			</label>
			<label>Paid on <input type="date" name="referral_paid_on" value="<?= e((string) $v('referral_paid_on')) ?>"></label>
			<div class="checkfield">
				<span class="field-label">W-9</span>
				<label class="boxwrap"><input type="checkbox" name="referral_w9" value="1" <?= $v('referral_w9') ? 'checked' : '' ?>> On file</label>
			</div>
		</div>
	</section>

	<section class="card band">
		<h2>Notes</h2>
		<textarea name="notes" rows="3"><?= e((string) $v('notes')) ?></textarea>
	</section>

	<div class="form-actions">
		<button class="btn btn-primary"><?= $isNew ? 'Set up commission' : 'Save' ?></button>
		<a href="<?= e($back) ?>" class="btn btn-ghost">Cancel</a>
	</div>
</form>
<?php if (!$isNew): ?>
	<form method="post" action="/commissions/<?= (int) $c['id'] ?>/delete" class="mt" data-confirm="Delete this commission record and its payments? This can't be undone.">
		<?= Csrf::field() ?>
		<button class="btn btn-ghost btn-small">Delete commission record</button>
	</form>
<?php endif; ?>
