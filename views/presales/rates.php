<?php
use App\Csrf;
use App\Presales;

/**
 * @var array $settings    key => value (raw stored values)
 * @var array $commission  rate rows (or as typed)
 * @var array $deposits    tier rows
 * @var array $templates   schedule rows with 'lines' text
 * @var array $rules       contingency rules
 * @var bool  $canEdit
 * @var array $activity
 * @var string $commissionDefault  row index of the default commission rate
 * @var string $templateDefault    row index of the default schedule
 */
$dis = $canEdit ? '' : 'disabled';
$s = static fn (string $k) => $settings[$k] ?? '';
$pct = static fn ($v) => $v === '' || $v === null ? '' : Presales::field((float) $v * 100, 4);
$n = static fn ($v, $dec = 4) => $v === '' || $v === null ? '' : Presales::field((float) $v, $dec);
$commission = $commission ?: [[]];
$deposits = $deposits ?: [[]];
$templates = $templates ?: [[]];
$rules = $rules ?: [[]];
?>
<div class="page-head">
	<div>
		<a href="/presales" class="back">&larr; Pre-Sales</a>
		<h1>Buildsheet rates</h1>
		<p class="muted">New build sheets copy these in when they are created. Saved build sheets keep their own copy, so changing a rate here never changes a quote already made.<?= $canEdit ? '' : ' Admins and salespeople can edit.' ?></p>
	</div>
</div>

<form method="post" action="/presales/rates" class="project-form">
	<?= Csrf::field() ?>

	<section class="card band">
		<h2>Pricing</h2>
		<div class="grid-form">
			<label>Sales tax (%) <input type="text" name="settings[tax_rate]" value="<?= e($pct($s('tax_rate'))) ?>" inputmode="decimal" <?= $dis ?>></label>
			<label>Tax note <input type="text" name="settings[tax_note]" value="<?= e($s('tax_note')) ?>" maxlength="120" <?= $dis ?>></label>
			<label>Default markup (%) <input type="text" name="settings[markup_default]" value="<?= e($pct($s('markup_default'))) ?>" inputmode="decimal" <?= $dis ?>></label>
			<label>Round final price up to ($) <input type="text" name="settings[round_to]" value="<?= e($n($s('round_to'), 0)) ?>" inputmode="numeric" <?= $dis ?>></label>
			<label>Default labor price ($/W) <input type="text" name="settings[labor_default]" value="<?= e($n($s('labor_default'), 4)) ?>" inputmode="decimal" required <?= $dis ?>>
				<small class="hint">New build sheets start here. Never zero.</small>
			</label>
			<label>Labor rate ($/man-hour) <input type="text" name="settings[labor_rate]" value="<?= e($n($s('labor_rate'), 2)) ?>" inputmode="decimal" <?= $dis ?>></label>
			<label class="span-2">Labor guideline
				<input type="text" name="settings[labor_guideline]" value="<?= e($s('labor_guideline')) ?>" maxlength="200" placeholder="e.g. Residential roof: about $0.20/W" <?= $dis ?>>
				<small class="hint">Shown beside the labor price on every build sheet.</small>
			</label>
			<label>Site visit ($) <input type="text" name="settings[site_visit]" value="<?= e($n($s('site_visit'), 2)) ?>" inputmode="decimal" <?= $dis ?>></label>
			<label>Lodging ($/person/night) <input type="text" name="settings[lodging_rate]" value="<?= e($n($s('lodging_rate'), 2)) ?>" inputmode="decimal" <?= $dis ?>></label>
			<label>Per diem ($/person/day) <input type="text" name="settings[per_diem_rate]" value="<?= e($n($s('per_diem_rate'), 2)) ?>" inputmode="decimal" <?= $dis ?>></label>
			<label>Price goes stale after (days) <input type="text" name="settings[stale_days]" value="<?= e($n($s('stale_days'), 0)) ?>" inputmode="numeric" <?= $dis ?>></label>
			<label>Steep array over (degrees) <input type="text" name="settings[steep_deg]" value="<?= e($n($s('steep_deg'), 1)) ?>" inputmode="decimal" <?= $dis ?>></label>
		</div>
	</section>

	<section class="card band">
		<h2>Commission</h2>
		<p class="hint">The adjusted rate is what is applied to the marked-up cost; the old sheet grosses the rate up (rate / (1 - rate)) so commission comes out near the rate times the final price. The step-down amount is added on top at that rate.</p>
		<div class="eq eq-commission" data-rows>
			<div class="eq-head"><span>Rate (%)</span><span>Adjusted (%)</span><span>Step-down ($)</span><span>Default</span><span></span></div>
			<?php foreach ($commission as $i => $c): ?>
				<div class="eq-row">
					<input type="text" name="commission[<?= $i ?>][rate]" value="<?= e((string) ($c['rate'] ?? '')) ?>" inputmode="decimal" aria-label="Rate" <?= $dis ?>>
					<input type="text" name="commission[<?= $i ?>][adjusted]" value="<?= e((string) ($c['adjusted'] ?? '')) ?>" inputmode="decimal" aria-label="Adjusted rate" <?= $dis ?>>
					<input type="text" name="commission[<?= $i ?>][step_down]" value="<?= e($n($c['step_down'] ?? '', 2)) ?>" inputmode="decimal" aria-label="Step-down amount" <?= $dis ?>>
					<label class="boxwrap"><input type="radio" name="commission_default" value="<?= $i ?>" data-row-value <?= (string) $commissionDefault === (string) $i ? 'checked' : '' ?> <?= $dis ?>> Default</label>
					<button type="button" class="btn btn-ghost btn-small row-del" title="Remove" <?= $dis ?>>&times;</button>
				</div>
			<?php endforeach; ?>
			<?php if ($canEdit): ?><button type="button" class="btn btn-ghost btn-small row-add">+ Rate</button><?php endif; ?>
		</div>
	</section>

	<section class="card band">
		<h2>Contingency</h2>
		<div class="grid-form">
			<label>Cap (%) <input type="text" name="settings[contingency_cap]" value="<?= e($n($s('contingency_cap'), 2)) ?>" inputmode="decimal" <?= $dis ?>></label>
			<label>Minimum ($) <input type="text" name="settings[contingency_floor]" value="<?= e($n($s('contingency_floor'), 2)) ?>" inputmode="decimal" <?= $dis ?>>
				<small class="hint">Small jobs carry at least this much.</small>
			</label>
		</div>
		<h3 class="sub">Rules</h3>
		<p class="hint">Rules add up (to the cap). Automatic rules switch on from the build sheet; manual ones are a checkbox on it.</p>
		<div class="eq eq-rules" data-rows>
			<div class="eq-head"><span>Rule</span><span>%</span><span>When</span><span>Reasoning</span><span>Active</span><span></span></div>
			<?php foreach ($rules as $i => $r): ?>
				<div class="eq-row">
					<input type="hidden" name="rules[<?= $i ?>][id]" value="<?= e((string) ($r['id'] ?? '')) ?>">
					<input type="text" name="rules[<?= $i ?>][name]" value="<?= e($r['name'] ?? '') ?>" aria-label="Rule name" <?= $dis ?>>
					<input type="text" name="rules[<?= $i ?>][pct]" value="<?= e($n($r['pct'] ?? '', 2)) ?>" inputmode="decimal" aria-label="Percent" <?= $dis ?>>
					<select name="rules[<?= $i ?>][trigger]" aria-label="When it applies" <?= $dis ?>>
						<?php foreach (Presales::TRIGGERS as $k => $label): ?>
							<option value="<?= $k ?>" <?= ($r['trigger'] ?? 'manual') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
						<?php endforeach; ?>
					</select>
					<input type="text" name="rules[<?= $i ?>][reason]" value="<?= e($r['reason'] ?? '') ?>" aria-label="Reasoning" <?= $dis ?>>
					<label class="boxwrap"><input type="checkbox" name="rules[<?= $i ?>][is_active]" value="1" data-default-checked="1" <?= ($r['is_active'] ?? 1) ? 'checked' : '' ?> <?= $dis ?>> On</label>
					<button type="button" class="btn btn-ghost btn-small row-del" title="Remove" <?= $dis ?>>&times;</button>
				</div>
			<?php endforeach; ?>
			<?php if ($canEdit): ?><button type="button" class="btn btn-ghost btn-small row-add">+ Rule</button><?php endif; ?>
		</div>
	</section>

	<section class="card band">
		<h2>Deposits</h2>
		<p class="hint">The first payment is a fixed amount by job class and DC size. Non-Residential is any commercial, agricultural, municipal or non-profit job. A build sheet only offers the tiers that fit, so a residential amount never shows on a Non-Residential job. Leave "up to" blank for no limit.</p>
		<div class="eq eq-deposits" data-rows>
			<div class="eq-head"><span>Class</span><span>From kW</span><span>Up to kW</span><span>Deposit ($)</span><span></span></div>
			<?php foreach ($deposits as $i => $t): ?>
				<div class="eq-row">
					<select name="deposits[<?= $i ?>][class]" aria-label="Class" <?= $dis ?>>
						<option value="residential" <?= ($t['class'] ?? '') === 'residential' ? 'selected' : '' ?>>Residential</option>
						<option value="commercial" <?= ($t['class'] ?? '') === 'commercial' ? 'selected' : '' ?>>Non-Residential</option>
					</select>
					<input type="text" name="deposits[<?= $i ?>][min_kw]" value="<?= e($n($t['min_kw'] ?? '', 2)) ?>" inputmode="decimal" aria-label="From kW" <?= $dis ?>>
					<input type="text" name="deposits[<?= $i ?>][max_kw]" value="<?= e($n($t['max_kw'] ?? '', 2)) ?>" inputmode="decimal" aria-label="Up to kW" placeholder="No limit" <?= $dis ?>>
					<input type="text" name="deposits[<?= $i ?>][amount]" value="<?= e($n($t['amount'] ?? '', 2)) ?>" inputmode="decimal" aria-label="Deposit" <?= $dis ?>>
					<button type="button" class="btn btn-ghost btn-small row-del" title="Remove" <?= $dis ?>>&times;</button>
				</div>
			<?php endforeach; ?>
			<?php if ($canEdit): ?><button type="button" class="btn btn-ghost btn-small row-add">+ Tier</button><?php endif; ?>
		</div>
	</section>

	<section class="card band">
		<h2>Payment schedules</h2>
		<p class="hint">After the deposit. One payment per line as "Label = percent of the balance after the deposit"; they must add up to 100.</p>
		<div class="eq eq-schedules" data-rows>
			<div class="eq-head"><span>Name</span><span>Payments</span><span>Default</span><span></span></div>
			<?php foreach ($templates as $i => $t): ?>
				<div class="eq-row">
					<input type="text" name="templates[<?= $i ?>][name]" value="<?= e($t['name'] ?? '') ?>" aria-label="Schedule name" <?= $dis ?>>
					<textarea name="templates[<?= $i ?>][lines]" rows="3" aria-label="Payments" placeholder="At PTI = 75&#10;Final payment = 25" <?= $dis ?>><?= e($t['lines'] ?? '') ?></textarea>
					<label class="boxwrap"><input type="radio" name="template_default" value="<?= $i ?>" data-row-value <?= (string) $templateDefault === (string) $i ? 'checked' : '' ?> <?= $dis ?>> Default</label>
					<button type="button" class="btn btn-ghost btn-small row-del" title="Remove" <?= $dis ?>>&times;</button>
				</div>
			<?php endforeach; ?>
			<?php if ($canEdit): ?><button type="button" class="btn btn-ghost btn-small row-add">+ Schedule</button><?php endif; ?>
		</div>
	</section>

	<section class="card band">
		<h2>Warranty wording</h2>
		<p class="hint">Used in every build sheet's warranty summary.</p>
		<div class="grid-form">
			<label class="span-all">Trifecta Solar labor and workmanship
				<textarea name="settings[trifecta_warranty_text]" rows="2" <?= $dis ?>><?= e($s('trifecta_warranty_text')) ?></textarea>
			</label>
			<label class="span-all">SolarInsure (when the build sheet includes it)
				<textarea name="settings[solarinsure_text]" rows="2" <?= $dis ?>><?= e($s('solarinsure_text')) ?></textarea>
			</label>
		</div>
	</section>

	<?php if ($canEdit): ?>
		<div class="form-actions">
			<button class="btn btn-primary">Save rates</button>
		</div>
	<?php endif; ?>
</form>

<section class="card band">
	<h2>History</h2>
	<?= App\View::partial('partials/activity', ['entries' => $activity]) ?>
</section>
<script src="<?= asset('assets/js/presales.js') ?>"></script>
