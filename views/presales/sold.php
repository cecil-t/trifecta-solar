<?php
use App\Csrf;
use App\Layout;
use App\Presales;
use App\Production;
use App\Projects;
use App\Warranties;

/**
 * Create project from the sold build sheet: what carries over, then create or link.
 *
 * @var array  $o          opportunity
 * @var array  $d          the build sheet picked as sold
 * @var array  $designs    the opportunity's build sheets
 * @var array  $plan       App\SoldProject::plan()
 * @var array  $contacts   the customer's active contacts
 * @var int[]  $picked     contacts ticked
 * @var string $number     suggested project number
 * @var string $name       project name
 * @var string $signed     contract signed date as typed
 * @var bool   $nameTaken  a project already has that name
 * @var array  $linkable   projects the build sheet could be linked to instead
 * @var array  $lookups    names for the ids on the opportunity
 */
$pr = $plan['project'];
$calc = $plan['calc'];
$fmtNum = static fn ($n, $dec = 1) => rtrim(rtrim(number_format((float) $n, $dec), '0'), '.');
$siteAddr = trim(implode(', ', array_filter([$pr['site_street'], $pr['site_city'], trim(($pr['site_state'] ?? '') . ' ' . ($pr['site_zip'] ?? ''))])));
$materialLabels = ['panels' => 'Panels', 'inverters' => 'Inverters', 'racking' => 'Racking / tracker', 'batteries' => 'Batteries', 'bos' => 'BOS'];
$answerLabels = ['solarinsure' => 'SolarInsure registration', 'solaredge' => 'SolarEdge extended warranty', 'sitework' => 'Third-party sitework', 'rebate' => 'Utility rebate'];
$notSet = '<span class="muted">Not set</span>';
?>
<div class="project-head">
	<div>
		<a href="/presales/<?= (int) $o['id'] ?>" class="back">&larr; <?= e($o['name']) ?></a>
		<div class="page-kicker">Mark sold</div>
		<h1>Create project<?= help_link('sales') ?></h1>
		<p class="muted small">Nothing is saved until you create or link the project. After that, the sold build sheet is locked.</p>
	</div>
</div>

<?php if (count($designs) > 1): ?>
	<form method="get" action="/presales/<?= (int) $o['id'] ?>/sold" class="sold-pick">
		<label>Sold build sheet
			<select name="design" data-autosubmit>
				<?php foreach ($designs as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) $x['id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($x['name']) ?><?= $x['price_total'] ? ' (' . e(Presales::money((float) $x['price_total'])) . ')' : '' ?></option><?php endforeach; ?>
			</select>
		</label>
		<noscript><button class="btn btn-ghost btn-small">Show</button></noscript>
	</form>
<?php endif; ?>

<?php if ($plan['warnings']): ?>
	<div class="flash flash-warn">
		<strong>Check before creating:</strong>
		<ul class="tight-list"><?php foreach ($plan['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul>
	</div>
<?php endif; ?>

<div class="sold-layout">
	<section class="card band sold-create">
		<h2>New project from <?= e($d['name']) ?></h2>
		<form method="post" action="/presales/<?= (int) $o['id'] ?>/sold" class="stack-sm">
			<?= Csrf::field() ?>
			<input type="hidden" name="design_id" value="<?= (int) $d['id'] ?>">
			<input type="hidden" name="mode" value="create">
			<div class="grid-form">
				<label>Project number
					<input type="text" name="number" value="<?= e($number) ?>" required maxlength="12" pattern="[A-Za-z0-9\-]{2,12}">
				</label>
				<label>Contract signed
					<input type="text" name="signed" value="<?= e($signed) ?>" placeholder="mm/dd/yyyy" inputmode="numeric" maxlength="10">
					<small class="hint">Starts the day counter. Can be filled in later on the project.</small>
				</label>
				<label class="span-2">Project name
					<input type="text" name="name" value="<?= e($name) ?>" required maxlength="120">
					<?php if ($nameTaken): ?><small class="text-red">A project already has this name. Project names must be unique.</small><?php endif; ?>
				</label>
			</div>
			<?php if ($contacts): ?>
				<fieldset class="checkfield">
					<legend class="field-label">Contacts on the project</legend>
					<?php foreach ($contacts as $c): ?>
						<label class="boxwrap"><input type="checkbox" name="contacts[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $picked, true) ? 'checked' : '' ?>> <?= e($c['name']) ?><?= $c['role'] ? ' <span class="muted small">' . e($c['role']) . '</span>' : '' ?></label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
			<button class="btn btn-primary" data-confirm="Create project <?= e($number) ?> and lock this build sheet?">Create project</button>
		</form>

		<?php if ($linkable): ?>
			<details class="sold-link mt">
				<summary>Or link to a project already entered</summary>
				<form method="post" action="/presales/<?= (int) $o['id'] ?>/sold" class="stack-sm mt-sm">
					<?= Csrf::field() ?>
					<input type="hidden" name="design_id" value="<?= (int) $d['id'] ?>">
					<input type="hidden" name="mode" value="link">
					<label>Project
						<select name="project_id" data-searchable="Search by number, name or customer" required>
							<option value="">Choose</option>
							<?php foreach ($linkable as $lp): ?><option value="<?= (int) $lp['id'] ?>"><?= e($lp['project_number'] . ' ' . $lp['name']) ?><?= $lp['customer_name'] ? ' (' . e($lp['customer_name']) . ')' : '' ?></option><?php endforeach; ?>
						</select>
					</label>
					<p class="hint">Fills only what is blank on that project. Its tasks and payment steps stay as they are.</p>
					<button class="btn btn-secondary btn-small" data-confirm="Link this build sheet to that project and lock it?">Mark sold and link</button>
				</form>
			</details>
		<?php endif; ?>
	</section>

	<section class="card band">
		<h2>Carries over</h2>
		<dl class="kv">
			<dt>Customer</dt><dd><?= $lookups['customer'] ? e($lookups['customer']) : $notSet ?></dd>
			<dt>Salesperson</dt><dd><?= $lookups['salesperson'] ? e($lookups['salesperson']) : $notSet ?></dd>
			<dt>Type</dt><dd><?= e(Projects::CUSTOMER_TYPES[$pr['customer_type']] ?? '') ?><?= $pr['is_agricultural'] ? ' &middot; Agricultural' : '' ?><?= $pr['tax_exempt'] ? ' &middot; Tax exempt' : '' ?></dd>
			<dt>Site</dt><dd><?= $siteAddr !== '' ? e($siteAddr) : $notSet ?><?php if ($lookups['municipality']): ?><div class="small muted"><?= e($lookups['municipality']) ?></div><?php endif; ?></dd>
			<dt>Utility</dt><dd><?= $lookups['utility'] ? e($lookups['utility']) : $notSet ?><?php if ($pr['utility_account'] || $pr['bill_name']): ?><div class="small muted"><?= e(implode(' · ', array_filter([$pr['bill_name'], $pr['utility_account'] ? 'Account ' . $pr['utility_account'] : null, $pr['utility_meter'] ? 'Meter ' . $pr['utility_meter'] : null]))) ?></div><?php endif; ?></dd>
			<dt>Price</dt><dd><strong class="amount"><?= e(Projects::money($pr['contract_price_cents'])) ?></strong><?= $pr['quote_number'] ? ' <span class="muted small">Proposal ' . e($pr['quote_number']) . '</span>' : '' ?></dd>
			<dt>System</dt><dd><?= e($fmtNum($calc['dc_kw'], 2)) ?> kW DC &middot; <?= e($fmtNum($calc['ac_kw'], 2)) ?> kW AC<?= $calc['storage_kwh'] > 0 ? ' &middot; ' . e($fmtNum($calc['storage_kwh'])) . ' kWh storage' : '' ?></dd>
			<dt>Install</dt><dd><?= e(Projects::INSTALL_TYPES[$pr['install_type']] ?? '') ?><?= $pr['racking'] ? ' &middot; ' . e($pr['racking']) : '' ?></dd>
			<dt>Internet</dt><dd><?= isset(Projects::INVERTER_INTERNET[$pr['inverter_internet'] ?? '']) ? e(Projects::INVERTER_INTERNET[$pr['inverter_internet']]) : $notSet ?></dd>
			<dt>Est. annual</dt><dd><?= $pr['est_annual_kwh'] ? e(number_format($pr['est_annual_kwh'])) . ' kWh <span class="muted small">(' . e(Production::MODELS[$plan['model']] ?? '') . ($plan['monthly'] ? ', with its 12 months' : '') . ')</span>' : $notSet ?></dd>
			<dt>Warranty</dt><dd><?= e(Warranties::PROGRAMS[$pr['warranty_program']]) ?></dd>
			<dt>Drive folder</dt><dd><?= $pr['drive_url'] ? 'Yes' : $notSet ?></dd>
			<dt>Commission</dt><dd>Record set up: <?= e(Presales::money($plan['commission'])) ?> to pay<?php if (abs($plan['commission'] - $calc['to_salesperson']) > 1): ?><div class="small text-orange">The build sheet shows <?= e(Presales::money($calc['to_salesperson'])) ?><?= empty($d['commission_plan']) ? ': it was priced before commission plans, so the record uses today\'s plan' : '' ?>. Check it on the commission page.</div><?php endif; ?></dd>
		</dl>
		<?php if ($pr['notes']): ?><h3 class="sub">Internal notes go to the project notes</h3><p class="desc-text small"><?= nl2br(e($pr['notes'])) ?></p><?php endif; ?>
	</section>

	<section class="card band">
		<h2>Equipment and arrays</h2>
		<ul class="tight-list">
			<?php foreach ($plan['modules'] as $r): ?><li><?= (int) $r['qty'] ?> &times; <?= e($fmtNum($r['watts'])) ?> W <span class="muted"><?= e($r['description']) ?></span></li><?php endforeach; ?>
			<?php foreach ($plan['inverters'] as $r): ?><li><?= (int) $r['qty'] ?> &times; <?= e($fmtNum($r['ac_kw'], 2)) ?> kW AC <span class="muted"><?= e($r['description']) ?></span></li><?php endforeach; ?>
			<?php foreach ($plan['batteries'] as $r): ?><li><?= (int) $r['qty'] ?> &times; <?= $r['kwh'] ? e($fmtNum($r['kwh'])) . ' kWh' : 'battery' ?> <span class="muted"><?= e($r['description']) ?></span></li><?php endforeach; ?>
		</ul>
		<?php if ($plan['arrays']): ?>
			<div class="table-wrap mt-sm">
				<table class="table table-tight">
					<thead><tr><th>Array</th><th>Mount</th><th class="num">Az</th><th class="num">Tilt</th><th class="num">Panels</th><th class="num">Shade</th><th class="num">Area</th><th class="num">Perimeter</th></tr></thead>
					<tbody>
					<?php foreach ($plan['arrays'] as $i => $a): ?>
						<tr>
							<td><?= e($a['name'] ?: 'Array ' . ($i + 1)) ?><?= isset(Projects::ROOF_MATERIALS[$a['material'] ?? '']) ? '<div class="small muted">' . e(Projects::ROOF_MATERIALS[$a['material']]) . '</div>' : '' ?></td>
							<td><?= e(Presales::MOUNTS[$a['mount']] ?? '') ?></td>
							<td class="num"><?= $a['azimuth'] !== null ? (int) $a['azimuth'] . '&deg;' : '' ?></td>
							<td class="num"><?= $a['tilt'] !== null ? e($fmtNum($a['tilt'])) . '&deg;' : '' ?></td>
							<td class="num"><?= $a['panels'] !== null ? (int) $a['panels'] : '' ?></td>
							<td class="num"><?= $a['shading_pct'] ? e($fmtNum($a['shading_pct'])) . '%' : '' ?></td>
							<td class="num"><?= $a['area_m2'] !== null ? e(Layout::sqft($a['area_m2'])) : '' ?></td>
							<td class="num"><?= $a['perimeter_m'] !== null ? e(Layout::feetUp($a['perimeter_m'])) : '' ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="hint">The site layout stays on the build sheet; the project links to it.</p>
		<?php endif; ?>
	</section>

	<section class="card band">
		<h2>Tasks</h2>
		<p class="small">The standard task list, with these from the build sheet:</p>
		<h3 class="sub">Payment steps</h3>
		<ol class="tight-list"><?php foreach ($plan['payments'] as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ol>
		<?php if (!$plan['payments']): ?><p class="muted small">No payment schedule on the build sheet: the template's payment steps stay.</p><?php endif; ?>
		<?php if ($plan['materials']): ?>
			<h3 class="sub">Materials notes</h3>
			<dl class="kv">
				<?php foreach ($plan['materials'] as $k => $text): ?><dt><?= e($materialLabels[$k]) ?></dt><dd class="small"><?= nl2br(e($text)) ?></dd><?php endforeach; ?>
			</dl>
		<?php endif; ?>
		<h3 class="sub">Questions answered</h3>
		<dl class="kv">
			<?php foreach ($plan['answers'] as $k => $a): ?><dt><?= e($answerLabels[$k]) ?></dt><dd class="small"><?= $a['needed'] ? 'Yes' : 'No' ?><?= $a['note'] ? '<div class="muted">' . nl2br(e($a['note'])) . '</div>' : '' ?></dd><?php endforeach; ?>
		</dl>
		<?php if ($plan['warranties']): ?>
			<h3 class="sub">Warranty lines</h3>
			<ul class="tight-list small">
				<?php foreach ($plan['warranties'] as $w): ?><li><?= e(Warranties::KINDS[$w['kind']]) ?>: <?= e((string) ($w['covers'] ?? $w['provider'] ?? '')) ?>, <?= e($fmtNum($w['term_years'])) ?> years<?= isset($w['guarantee_kwh']) ? ', ' . e(number_format($w['guarantee_kwh'])) . ' kWh guaranteed' : '' ?></li><?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</div>
<script src="<?= asset('assets/js/combobox.js') ?>"></script>
