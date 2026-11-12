<?php
use App\Csrf;
use App\Projects;

/**
 * @var array   $req       web_requests row with data, files and bill decoded (App\WebsiteRequests::find)
 * @var array   $matches   existing customers that may be this person: id, name, why
 * @var ?array  $opp       the opportunity made from it
 * @var ?string $handledBy
 * @var bool    $canRead   the Claude API key is set
 * @var array   $activity
 */
$d = $req['data'];
[$type, $ag] = App\WebsiteRequests::type((string) ($d['project_type'] ?? ''));
$bills = $req['bills'];
$money = static fn (string $v): string => $v === '' ? '' : '$' . number_format((float) $v, 2);
$kwh = static fn (string $v): string => $v === '' ? '' : number_format((float) $v) . ' kWh';
$rows = [
	['Phone', (string) ($d['phone'] ?? '')],
	['Email', (string) ($d['email'] ?? '')],
	['Project type', (string) ($d['project_type'] ?? '')],
	['Service address', (string) ($d['address'] ?? '')],
	['Bill info', (string) ($d['share'] ?? '')],
	['Electric utility', (string) ($d['utility'] ?? '')],
	['Yearly use', $kwh((string) ($d['yearly_kwh'] ?? ''))],
	['Rate plan', (string) ($d['rate_plan'] ?? '')],
	['kWh this period', $kwh((string) ($d['period_kwh'] ?? ''))],
	['Current charges', $money((string) ($d['current_charges'] ?? ''))],
	['Customer charge', $money((string) ($d['customer_charge'] ?? ''))],
];
?>
<div class="page-head">
	<div>
		<a href="/presales" class="back">&larr; Sales</a>
		<h1><?= e(($d['name'] ?? '') !== '' ? $d['name'] : 'Website request') ?><?= help_link('sales') ?></h1>
		<p class="muted">Website quote request, sent <?= e(fmt_dt($req['submitted_at'] ?? $req['created_at'])) ?> (form entry <?= (int) $req['entry_id'] ?>).</p>
	</div>
	<div class="head-actions">
		<?php if ($req['status'] === 'pending'): ?><span class="chip chip-orange">Pending</span>
		<?php elseif ($req['status'] === 'created'): ?><span class="chip chip-green">Opportunity created</span>
		<?php else: ?><span class="chip chip-na">Dismissed</span><?php endif; ?>
	</div>
</div>

<?php if ($req['status'] === 'created' && $opp): ?>
	<div class="flash flash-success">Opportunity <a href="/presales/<?= (int) $opp['id'] ?>"><?= e($opp['name']) ?></a> was created from this request<?= $handledBy ? ' by ' . e($handledBy) : '' ?> on <?= e(fmt_dt($req['handled_at'], 'm/d/Y')) ?>.</div>
<?php elseif ($req['status'] === 'dismissed'): ?>
	<div class="flash flash-info">Dismissed<?= $handledBy ? ' by ' . e($handledBy) : '' ?> on <?= e(fmt_dt($req['handled_at'], 'm/d/Y')) ?>.
		<form method="post" action="/presales/requests/<?= (int) $req['id'] ?>/reopen" class="inline-form"><?= Csrf::field() ?><button class="btn btn-ghost btn-small">Put back on Pending</button></form>
	</div>
<?php endif; ?>

<div class="grid-2">
	<div class="col-stack">
		<section class="card">
			<h2>What they sent</h2>
			<dl class="kv">
				<?php foreach ($rows as [$label, $value]): if ($value === '') { continue; } ?>
					<dt><?= e($label) ?></dt><dd><?= $label === 'Email' ? '<a href="mailto:' . e($value) . '">' . e($value) . '</a>' : ($label === 'Phone' ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $value)) . '">' . e($value) . '</a>' : e($value)) ?></dd>
				<?php endforeach; ?>
				<?php if (($d['notes'] ?? '') !== ''): ?><dt>Notes</dt><dd><?= nl2br(e($d['notes'])) ?></dd><?php endif; ?>
			</dl>
		</section>

		<section class="card">
			<h2>Bill</h2>
			<?php if (!$req['files']): ?>
				<p class="muted">No bill attached.</p>
			<?php else: ?>
				<p class="small">
					<?php foreach ($req['files'] as $i => $f): ?>
						<a href="/presales/requests/<?= (int) $req['id'] ?>/file/<?= $i + 1 ?>" target="_blank" rel="noopener">Bill file <?= $i + 1 ?></a><?= $i < count($req['files']) - 1 ? ' &middot; ' : '' ?>
					<?php endforeach; ?>
					<span class="muted">(opened from the website)</span>
				</p>
				<?php if ($bills): ?>
					<?php if (count($bills) > 1): ?><p class="small"><?= count($bills) ?> separate bills. Pick the primary one under Create opportunity; the others go in the opportunity's Notes.</p><?php endif; ?>
					<?php foreach ($bills as $bi => $b): $bill = $b['raw']; ?>
						<?php if (count($bills) > 1): ?><h3 class="mt">Bill <?= $bi + 1 ?></h3><?php endif; ?>
						<dl class="kv">
							<?php foreach ([
								'Name on the bill' => $b['fields']['bill_name'] ?? $bill['account_holder'], 'Service address' => $bill['service_address'], 'Utility' => $bill['utility'],
								'Account #' => $bill['account_number'], 'Meter #' => $bill['meter_number'], 'Rate' => $bill['rate_name'],
								'Billing period' => trim($bill['period_start'] . ' to ' . $bill['period_end'], ' to'),
								'kWh this period' => $kwh($bill['kwh_this_period']), 'Current charges' => $money($bill['current_charges']),
								'Customer charge' => $money($bill['customer_charge']),
								'Price to Compare' => $bill['price_to_compare_cents'] !== '' ? $bill['price_to_compare_cents'] . ' cents per kWh' : '',
								'Supplier' => $bill['supplier'], 'Yearly use' => $kwh((string) ($b['fields']['annual_usage_kwh'] ?? '')),
								'Billed demand' => $bill['demand_kw'] !== '' ? $bill['demand_kw'] . ' kW' : '',
							] as $label => $value): if ($value === '' || $value === null) { continue; } ?>
								<dt><?= e($label) ?></dt><dd><?= e((string) $value) ?></dd>
							<?php endforeach; ?>
						</dl>
						<?php if ($bill['notes'] !== ''): ?><p class="small text-orange"><?= e($bill['notes']) ?></p><?php endif; ?>
						<?php if ($bill['uncertain']): ?><p class="small text-orange">Hard to read on the bill, check: <?= e(str_replace('_', ' ', implode(', ', $bill['uncertain']))) ?>.</p><?php endif; ?>
					<?php endforeach; ?>
					<p class="muted small mt-xs">Read <?= e(fmt_dt($req['bill_read_at'])) ?>. The primary bill fills the opportunity where the form left a field blank; Name on utility bill always comes from the bill.</p>
				<?php elseif ($req['bill_error']): ?>
					<p class="small text-orange">Not read: <?= e($req['bill_error']) ?></p>
				<?php else: ?>
					<p class="muted small"><?= $canRead ? 'Not read yet; it is read on the next website check.' : 'Not read: bill reading is not set up yet (the Claude API key).' ?></p>
				<?php endif; ?>
				<?php if ($canRead && $req['status'] !== 'created'): ?>
					<form method="post" action="/presales/requests/<?= (int) $req['id'] ?>/read" class="mt-xs"><?= Csrf::field() ?><button class="btn btn-secondary btn-small"><?= $bills ? 'Read it again' : 'Read the bill now' ?></button></form>
				<?php endif; ?>
			<?php endif; ?>
		</section>
	</div>

	<div class="col-stack">
		<?php if ($req['status'] === 'pending'): ?>
			<section class="card">
				<h2>Create opportunity</h2>
				<form method="get" action="/presales/new">
					<input type="hidden" name="request" value="<?= (int) $req['id'] ?>">
					<fieldset class="req-pick">
						<legend class="small muted">Customer</legend>
						<label class="radio-line"><input type="radio" name="customer" value="0" <?= $matches ? '' : 'checked' ?>> New customer: <?= e(($d['name'] ?? '') ?: 'from the request') ?></label>
						<?php foreach ($matches as $i => $m): ?>
							<label class="radio-line"><input type="radio" name="customer" value="<?= (int) $m['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>> <a href="/organizations/<?= (int) $m['id'] ?>" target="_blank" rel="noopener"><?= e($m['name']) ?></a> <span class="muted small">(<?= e(implode(', ', $m['why'])) ?>)</span></label>
						<?php endforeach; ?>
					</fieldset>
					<?php if (count($bills) > 1): ?>
						<fieldset class="req-pick">
							<legend class="small muted">Primary bill</legend>
							<?php foreach ($bills as $bi => $b): ?>
								<label class="radio-line"><input type="radio" name="bill" value="<?= $bi ?>" <?= $bi === 0 ? 'checked' : '' ?>> Bill <?= $bi + 1 ?>: <?= e(trim(($b['raw']['rate_name'] ?: $b['raw']['utility']) . ($b['raw']['account_number'] !== '' ? ', account ' . $b['raw']['account_number'] : '') . ($b['raw']['service_address'] !== '' ? ', ' . $b['raw']['service_address'] : ''), ', ')) ?></label>
							<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>
					<p class="hint"><?= $matches ? 'Possible existing customer found. Pick it for a repeat customer, or New customer if it is someone else.' : 'No existing customer has this email, phone, name or street.' ?>
						Type: <?= e(Projects::CUSTOMER_TYPES[$type] ?? $type) ?><?= $ag ? ', agricultural' : '' ?>. Lead source: Trifecta lead (website).</p>
					<button class="btn btn-primary">Create opportunity</button>
				</form>
				<p class="hint mt">Opens the New opportunity form filled in from the request. Nothing is saved until you save that form.</p>
			</section>
			<section class="card">
				<h2>Dismiss</h2>
				<form method="post" action="/presales/requests/<?= (int) $req['id'] ?>/dismiss" data-confirm="Dismiss this request? It leaves the Pending list.">
					<?= Csrf::field() ?>
					<label>Why (optional) <input type="text" name="reason" maxlength="200" placeholder="e.g. spam, test, duplicate"></label>
					<button class="btn btn-ghost btn-small mt-xs">Dismiss</button>
				</form>
			</section>
		<?php endif; ?>
		<?php if ($activity): ?>
			<section class="card">
				<h2>Log</h2>
				<ul class="log-list small">
					<?php foreach ($activity as $a): ?>
						<li><span class="muted"><?= e(fmt_dt($a['created_at'])) ?></span> <?= e((string) ($a['user_name'] ?? 'System')) ?>: <?= e((string) $a['body']) ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
	</div>
</div>
