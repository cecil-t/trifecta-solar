<?php
use App\Csrf;
use App\Projects;
use App\Tasks;
use App\Municipalities;

$ownerOpts = static function ($selected) use ($users): string {
	$h = '<option value="">-</option>';
	foreach ($users as $u) {
		$h .= '<option value="' . (int) $u['id'] . '"' . ((string) $selected === (string) $u['id'] ? ' selected' : '') . '>' . e($u['initials'] ?: $u['name']) . '</option>';
	}
	return $h;
};
$neededSel = static function ($val): string {
	$val = $val === null ? '' : (string) (int) $val;
	$o = ['' => '?', '1' => 'Yes', '0' => 'No'];
	$h = '';
	foreach ($o as $k => $l) {
		$h .= '<option value="' . $k . '"' . ($val === (string) $k ? ' selected' : '') . '>' . $l . '</option>';
	}
	return $h;
};
// Question items (no default answer in the template) keep a Yes / No / ? choice; other
// steps are simply needed unless marked "Not needed" from the menu; sub-tasks get removed instead.
$questionTpl = array_map('intval', array_column(App\Db::all('SELECT id FROM task_templates WHERE default_needed IS NULL'), 'id'));
$isQuestion = static fn (array $t) => $t['template_id'] !== null && in_array((int) $t['template_id'], $questionTpl, true);
$naChip = '<span class="chip chip-na">N/A</span>';
$gateBadge = static fn (?string $g) => match ($g) {
	'start_clock' => '<span class="gate" title="Starts the day counter">clock</span>',
	'install_prereq' => '<span class="gate" title="Required for Clear to install">prereq</span>',
	'install_started' => '<span class="gate" title="Moves the project to Installation">phase</span>',
	'pto' => '<span class="gate" title="Moves the project to Closeout">phase</span>',
	default => '',
};
$provider = static function (array $slot) use ($p): string {
	return match ((string) $p[$slot['by']]) {
		'municipality' => 'Municipality',
		'county' => 'County',
		'third_party' => e($p[str_replace('_id', '_name', $slot['org'])] ?? '') ?: 'Third party (not chosen)',
		default => '<span class="muted">Not set</span>',
	};
};
$today = date('Y-m-d');
// Target cell highlight: past target with no done date (cream), 30+ days past (red).
$overdue = static function (array $t) use ($today): string {
	if ((string) $t['needed'] === '0' || !empty($t['done_date']) || empty($t['target_date']) || $t['target_date'] >= $today) {
		return '';
	}
	$days = (int) ((strtotime($today) - strtotime($t['target_date'])) / 86400);
	return $days >= 30 ? 'is-overdue-30' : 'is-overdue';
};
// Yes / No / not answered for a question-type task, looked up by name.
// Utility work orders: the "Work order #" reference on Interconnection and on each Utility upgrade
$workOrders = [];
foreach ($tree as $tasks) {
	foreach ($tasks as $t) {
		if (stripos((string) $t['ref_label'], 'work order') === 0 && trim((string) $t['reference']) !== '' && (string) $t['needed'] !== '0') {
			$ref = trim((string) $t['reference']);
			$what = strcasecmp($t['name'], 'Interconnection') === 0 ? '' : ucfirst((string) preg_replace('/^utility\s+/i', '', $t['name'])) . ' ';
			$workOrders[] = $what . (preg_match('/^w\.?o\b/i', $ref) ? $ref : 'WO ' . $ref);
		}
	}
}
$answer = static function (string $name) use ($tree): string {
	foreach ($tree as $tasks) {
		foreach ($tasks as $t) {
			if (strcasecmp($t['name'], $name) === 0) {
				return match ((string) $t['needed']) {
					'1' => !empty($t['done_date']) ? 'Yes &middot; done ' . e(fmt_date($t['done_date'])) : 'Yes',
					'0' => 'No',
					default => '<span class="muted">Not answered</span>',
				};
			}
		}
	}
	return '<span class="muted">Not on this project</span>';
};
$siteAddr = trim(implode(', ', array_filter([trim((string) $p['site_street']), trim((string) $p['site_city']), trim(($p['site_state'] ?? '') . ' ' . ($p['site_zip'] ?? ''))])));
$fmtNum = static fn ($n, $d = 1) => rtrim(rtrim(number_format((float) $n, $d), '0'), '.');
$back = '/projects/' . (int) $p['id'];
?>
<div class="project-head" data-project="<?= (int) $p['id'] ?>" data-csrf="<?= e(Csrf::token()) ?>">
	<div>
		<a href="<?= e($listUrl) ?>" class="back">&larr; Projects</a>
		<h1><span class="pnum"><?= e($p['project_number']) ?></span> <?= e($p['name']) ?></h1>
		<div class="badges">
			<span id="phase-badge" class="phase phase-<?= e($status['phase']) ?>"<?= $p['archived_at'] ? ' title="Marked completed ' . e(fmt_dt($p['archived_at'], 'm/d/Y')) . '"' : '' ?>><?= e(Tasks::PHASE_LABELS[$status['phase']]) ?></span>
			<?php if ((string) $p['tax_exempt'] === '1'): ?><span class="chip chip-purple" title="Customer has a tax-exempt certificate">Tax exempt</span><?php endif; ?>
			<?php if ($p['hold_state'] === 'on_hold'): ?><span class="chip chip-orange">On hold<?= $p['hold_reason'] ? ': ' . e($p['hold_reason']) : '' ?></span><?php endif; ?>
			<?php if ($p['hold_state'] === 'cancelled' && $p['hold_reason']): ?><span class="chip"><?= e($p['hold_reason']) ?></span><?php endif; ?>
			<span id="clear-badge" class="chip chip-green" <?= $status['phase'] === 'pre_install' && $status['clear_to_install'] ? '' : 'hidden' ?>>Clear to install</span>
			<span class="chip chip-blue" id="days-badge" <?= $status['days'] === null ? 'hidden' : '' ?>><span id="days-num"><?= (int) $status['days'] ?></span> days<?= $status['pto_date'] ? ' to PTO' : ' in progress' ?></span>
		</div>
		<?php if ($p['status_note']): ?><p class="status-note"><?= e($p['status_note']) ?></p><?php endif; ?>
	</div>
	<div class="head-actions">
		<?php if ($drive = safe_url($p['drive_url'])): ?>
			<a href="<?= e($drive) ?>" target="_blank" rel="noopener" class="btn btn-secondary">Google Drive folder &#8599;</a>
		<?php else: ?>
			<a href="/projects/<?= (int) $p['id'] ?>/edit" class="btn btn-ghost">+ Add Drive folder link</a>
		<?php endif; ?>
		<a href="/projects/<?= (int) $p['id'] ?>/edit" class="btn btn-primary">Edit</a>
	</div>
</div>

<div class="summary-grid">
	<section class="card">
		<h2>Customer</h2>
		<dl class="kv">
			<dt>Customer</dt><dd><?= $p['customer_id'] ? '<a href="/organizations/' . (int) $p['customer_id'] . '">' . e($p['customer_name']) . '</a>' : '<span class="muted">Not set</span>' ?></dd>
			<?php foreach (array_slice($contacts, 0, 3) as $c): ?>
				<dt><?= e($c['role'] ?: 'Contact') ?></dt>
				<dd><?= e($c['name']) ?><?php if ($c['phone']): ?> &middot; <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a><?php endif; ?><?php if ($c['email']): ?><br><a href="mailto:<?= e($c['email']) ?>" class="small"><?= e($c['email']) ?></a><?php endif; ?></dd>
			<?php endforeach; ?>
			<dt>Site</dt><dd><?= $siteAddr !== '' ? '<a href="https://www.google.com/maps/search/?api=1&amp;query=' . e(rawurlencode($siteAddr)) . '" target="_blank" rel="noopener" title="Open in Google Maps">' . e($siteAddr) . ' &#8599;</a>' : '<span class="muted">Not set</span>' ?></dd>
			<dt>Type</dt><dd><?= e(Projects::CUSTOMER_TYPES[$p['customer_type']] ?? '') ?><?= $p['is_agricultural'] ? ' &middot; Agricultural' : '' ?></dd>
			<dt>Salesperson</dt><dd><?= e($p['salesperson_name'] ?? '') ?></dd>
		</dl>
	</section>

	<section class="card">
		<h2>Jurisdiction</h2>
		<dl class="kv">
			<dt>Municipality</dt><dd><?= $p['municipality_id'] ? '<a href="/municipalities/' . (int) $p['municipality_id'] . '">' . e($p['municipality_name']) . '</a><div class="muted small">' . e($p['county_name'] . ' Co., ' . $p['county_state']) . '</div>' : '<span class="muted">Not set</span>' ?></dd>
			<?php foreach (Municipalities::SLOTS as $slot): ?>
				<dt><?= e($slot['label']) ?></dt><dd><?= $provider($slot) ?></dd>
			<?php endforeach; ?>
			<dt>Utility</dt><dd><strong><?= e($p['utility_name'] ?? '') ?></strong><?php foreach ($workOrders as $wo): ?>&nbsp;&middot; <span class="work-order"><?= e($wo) ?></span><?php endforeach; ?></dd>
			<dt>Designer</dt><dd><?= e($p['designer_name'] ?? '') ?></dd>
			<dt>Installer</dt><dd><?= e($p['installer_name'] ?? 'Trifecta (in-house)') ?></dd>
		</dl>
	</section>

	<section class="card">
		<h2>System</h2>
		<div class="metrics">
			<div><span class="m-num"><?= $fmtNum($totals['dc_kw'], 2) ?></span><span class="m-label">kW DC</span></div>
			<div><span class="m-num"><?= $fmtNum($totals['ac_kw'], 2) ?></span><span class="m-label">kW AC</span></div>
			<div><span class="m-num"><?= $totals['ratio'] ? number_format($totals['ratio'], 2) : '-' ?></span><span class="m-label">DC/AC</span></div>
			<?php if ($p['has_batteries']): ?><div><span class="m-num"><?= $fmtNum($totals['storage_kwh'], 1) ?></span><span class="m-label">kWh storage</span></div><?php endif; ?>
		</div>
		<dl class="kv">
			<?php if ($eq['modules']): ?>
				<dt>Modules</dt><dd><?php foreach ($eq['modules'] as $m): ?><div><?= (int) $m['qty'] ?> &times; <?= $fmtNum($m['watts']) ?> W<?= $m['description'] ? ' <span class="muted">' . e($m['description']) . '</span>' : '' ?></div><?php endforeach; ?></dd>
			<?php endif; ?>
			<?php if ($eq['inverters']): ?>
				<dt>Inverters</dt><dd><?php foreach ($eq['inverters'] as $i): ?><div><?= (int) $i['qty'] ?> &times; <?= $fmtNum($i['ac_kw'], 2) ?> kW AC<?= $i['description'] ? ' <span class="muted">' . e($i['description']) . '</span>' : '' ?></div><?php endforeach; ?></dd>
			<?php endif; ?>
			<?php if ($eq['batteries']): ?>
				<dt>Batteries</dt><dd><?php foreach ($eq['batteries'] as $b): ?><div><?= (int) $b['qty'] ?> &times; <?= $b['kwh'] ? $fmtNum($b['kwh']) . ' kWh' : 'battery' ?><?= $b['kw'] ? ' / ' . $fmtNum($b['kw']) . ' kW' : '' ?><?= $b['description'] ? ' <span class="muted">' . e($b['description']) . '</span>' : '' ?></div><?php endforeach; ?></dd>
			<?php endif; ?>
			<dt>Install</dt><dd><?= e(Projects::INSTALL_TYPES[$p['install_type']] ?? '') ?><?= $p['racking'] ? ' &middot; ' . e($p['racking']) : '' ?></dd>
			<?php if ($p['est_annual_kwh']): ?><dt>Est. annual</dt><dd><?= number_format((int) $p['est_annual_kwh']) ?> kWh</dd><?php endif; ?>
		</dl>
	</section>

	<section class="card">
		<h2>Contract</h2>
		<dl class="kv">
			<dt>Price</dt><dd><?= $p['contract_price_cents'] !== null ? '<strong class="amount">' . e(Projects::money((int) $p['contract_price_cents'])) . '</strong>' : '<span class="muted">Not set</span>' ?></dd>
			<?php if ($totals['price_per_watt']): ?><dt>$/W</dt><dd><strong class="amount">$<?= number_format($totals['price_per_watt'], 2) ?></strong></dd><?php endif; ?>
			<dt>Funding</dt><dd><?= e($funding) ?: '<span class="muted">Not set</span>' ?><?= $p['funding_note'] ? '<div class="muted small">' . e($p['funding_note']) . '</div>' : '' ?></dd>
			<dt>Tax exempt</dt><dd><?= $p['tax_exempt'] === null ? '<span class="muted">Unknown</span>' : ((int) $p['tax_exempt'] ? 'Yes' : 'No') ?></dd>
			<dt>Signed</dt><dd><?= e(fmt_date($status['start_date'])) ?: '<span class="muted">Not set</span>' ?></dd>
			<dt>SolarInsure</dt><dd><?= $answer('SolarInsure registration') ?></dd>
			<dt>Utility rebate</dt><dd><?= $answer('Utility rebate') ?></dd>
			<dt>VNM</dt><dd><?= $answer('Virtual net metering') ?></dd>
			<?php if ($p['quote_number']): ?><dt>Quote #</dt><dd><?= e($p['quote_number']) ?></dd><?php endif; ?>
		</dl>
	</section>
</div>

<section class="card task-strip" id="todos">
	<div class="task-strip-head">
		<h2>Tasks <span class="muted small">(<?= count($todos) ?> open)</span></h2>
		<a href="/tasks/new?project=<?= (int) $p['id'] ?>&amp;back=<?= e(rawurlencode('/projects/' . (int) $p['id'] . '#todos')) ?>" class="btn btn-ghost btn-small">+ Assign a task</a>
	</div>
	<?php if ($todos): ?><?= App\View::partial('tasks/_list', ['rows' => $todos, 'back' => '/projects/' . (int) $p['id'] . '#todos', 'showLink' => false]) ?><?php endif; ?>
</section>

<section class="card task-strip last-comment">
	<div class="task-strip-head">
		<h2>Latest comment</h2>
		<a href="#log" class="btn btn-ghost btn-small">Project log &darr;</a>
	</div>
	<?php if ($lastComment): $c = $lastComment; ?>
		<div class="activity-item">
			<span class="avatar avatar-sm"><?= e($c['user_initials'] ?: ($c['user_name'] ? mb_substr($c['user_name'], 0, 1) : 'S')) ?></span>
			<div class="activity-body">
				<div class="activity-meta">
					<strong><?= e($c['user_name'] ?? 'System') ?></strong>
					<time datetime="<?= e($c['created_at']) ?>" title="<?= e(fmt_dt($c['created_at'])) ?>"><?= e(time_ago($c['created_at'])) ?></time>
					<?php if ($c['edited_at']): ?><span class="muted">(edited)</span><?php endif; ?>
				</div>
				<div class="comment-text"><?= nl2br(e($c['body']), false) ?></div>
			</div>
		</div>
	<?php else: ?>
		<p class="muted small last-comment-empty">No comments yet. <a href="#log">Add one in the Project log</a>.</p>
	<?php endif; ?>
</section>

<div id="tasks" class="tasks-wrap" data-today="<?= $today ?>" data-current-phase="<?= e($status['phase']) ?>">
	<?php foreach (Tasks::PHASES as $phaseKey => $phaseLabel): $tasks = $tree[$phaseKey]; ?>
		<?php
		$done = count(array_filter($tasks, static fn ($t) => $t['resolved']));
		$phaseTemplates = array_filter($templateTasks, static fn ($t) => $t['phase'] === $phaseKey);
		?>
		<section class="card card-flush phase-block" id="phase-<?= $phaseKey ?>">
			<div class="phase-title" data-phase-toggle>
				<span class="phase-caret" aria-hidden="true"></span>
				<h2><?= e($phaseLabel) ?></h2>
				<span class="muted small"><?= $done ?> of <?= count($tasks) ?> resolved</span>
			</div>
			<div class="tgrid">
				<div class="trow thead">
					<span><?= e($phaseLabel) ?></span><span>Needed</span><span>Completed</span><span class="th-target">Target</span><span>Ref #</span><span>Assigned</span><span>Note</span><span></span>
				</div>
				<?php foreach ($tasks as $t): $hasSubs = (bool) $t['subs']; ?>
					<div class="task" id="task-<?= (int) $t['id'] ?>">
						<div class="trow trow-task <?= $t['resolved'] ? 'is-resolved' : '' ?> <?= (string) $t['needed'] === '0' ? 'is-na' : '' ?>" data-id="<?= (int) $t['id'] ?>">
							<span class="tname"><span class="dot"></span><?= e($t['name']) ?> <?= $gateBadge($t['gate']) ?></span>
							<?php if ($isQuestion($t)): ?>
								<select data-field="needed" aria-label="Needed"><?= $neededSel($t['needed']) ?></select>
							<?php else: ?>
								<span class="needed-cell" data-needed="<?= e((string) $t['needed']) ?>"><?= (string) $t['needed'] === '0' ? $naChip : '' ?></span>
							<?php endif; ?>
							<?php if ($hasSubs): ?>
								<?php
								$active = array_filter($t['subs'], static fn ($s) => (string) $s['needed'] !== '0');
								$sd = count(array_filter($active, static fn ($s) => !empty($s['done_date'])));
								?>
								<span class="rollup"><?= $sd ?>/<?= count($active) ?> completed</span>
							<?php else: ?>
								<div class="dwrap dw-done"><span class="mlabel">Completed</span><span class="date-wrap"><input type="date" data-field="done_date" value="<?= e($t['done_date']) ?>" aria-label="Completed date"><button type="button" class="today-btn" data-set-today title="Completed today" aria-label="Mark completed today">&#10003;</button><button type="button" class="clear-date-btn" data-clear-date title="Clear this date" aria-label="Clear completed date">&times;</button></span></div>
								<label class="dwrap dw-target"><span class="mlabel">Target</span><input type="date" data-field="target_date" value="<?= e($t['target_date']) ?>" aria-label="Target date" class="<?= $overdue($t) ?>"></label>
							<?php endif; ?>
							<?php if ($t['ref_label']): ?>
								<input type="text" data-field="reference" value="<?= e($t['reference']) ?>" placeholder="<?= e($t['ref_label']) ?>" aria-label="<?= e($t['ref_label']) ?>">
							<?php else: ?><span></span><?php endif; ?>
							<label class="dwrap dw-owner"><span class="mlabel">Assigned to</span><select data-field="owner_id" aria-label="Assigned to"><?= $ownerOpts($t['owner_id']) ?></select></label>
							<input type="text" data-field="note" value="<?= e($t['note']) ?>" placeholder="Note" aria-label="Note" title="<?= $t['note_updated_at'] ? 'Edited ' . e(fmt_dt($t['note_updated_at'])) : '' ?>">
							<details class="tmenu">
								<summary aria-label="More">&#8943;</summary>
								<div class="usermenu-panel">
									<button type="button" class="linklike" data-action="rename" data-name="<?= e($t['name']) ?>">Rename</button>
									<?php if (!$isQuestion($t)): ?>
										<?php if ((string) $t['needed'] === '0'): ?>
											<button type="button" class="linklike" data-action="set-needed" data-value="1">Needed after all</button>
										<?php else: ?>
											<button type="button" class="linklike" data-action="set-needed" data-value="0">Not needed</button>
										<?php endif; ?>
									<?php endif; ?>
									<button type="button" class="linklike" data-action="add-sub" data-parent="<?= (int) $t['id'] ?>">Add sub-task</button>
									<form method="post" action="/projects/<?= (int) $p['id'] ?>/tasks/<?= (int) $t['id'] ?>/duplicate"><?= Csrf::field() ?><button class="linklike">Duplicate</button></form>
									<form method="post" action="/projects/<?= (int) $p['id'] ?>/tasks/<?= (int) $t['id'] ?>/delete" onsubmit="return confirm('Remove &quot;<?= e($t['name']) ?>&quot;<?= $hasSubs ? ' and its sub-tasks' : '' ?> from this project?')"><?= Csrf::field() ?><button class="linklike text-red">Remove</button></form>
								</div>
							</details>
						</div>
						<?php foreach ($t['subs'] as $s): ?>
							<div class="trow trow-sub <?= $s['resolved'] ? 'is-resolved' : '' ?> <?= (string) $s['needed'] === '0' ? 'is-na' : '' ?>" data-id="<?= (int) $s['id'] ?>" id="task-<?= (int) $s['id'] ?>">
								<span class="tname"><span class="dot"></span><?= e($s['name']) ?> <?= $gateBadge($s['gate']) ?></span>
								<span class="needed-cell" data-needed="<?= e((string) $s['needed']) ?>"><?= (string) $s['needed'] === '0' ? $naChip : '' ?></span>
								<div class="dwrap dw-done"><span class="mlabel">Completed</span><span class="date-wrap"><input type="date" data-field="done_date" value="<?= e($s['done_date']) ?>" aria-label="Completed date"><button type="button" class="today-btn" data-set-today title="Completed today" aria-label="Mark completed today">&#10003;</button><button type="button" class="clear-date-btn" data-clear-date title="Clear this date" aria-label="Clear completed date">&times;</button></span></div>
								<label class="dwrap dw-target"><span class="mlabel">Target</span><input type="date" data-field="target_date" value="<?= e($s['target_date']) ?>" aria-label="Target date" class="<?= (string) $t['needed'] === '0' ? '' : $overdue($s) ?>"></label>
								<span></span>
								<label class="dwrap dw-owner"><span class="mlabel">Assigned to</span><select data-field="owner_id" aria-label="Assigned to"><?= $ownerOpts($s['owner_id']) ?></select></label>
								<input type="text" data-field="note" value="<?= e($s['note']) ?>" placeholder="Note" aria-label="Note" title="<?= $s['note_updated_at'] ? 'Edited ' . e(fmt_dt($s['note_updated_at'])) : '' ?>">
								<details class="tmenu">
									<summary aria-label="More">&#8943;</summary>
									<div class="usermenu-panel">
										<button type="button" class="linklike" data-action="rename" data-name="<?= e($s['name']) ?>">Rename</button>
										<?php if ((string) $s['needed'] === '0'): ?><button type="button" class="linklike" data-action="set-needed" data-value="1">Needed after all</button><?php endif; ?>
										<form method="post" action="/projects/<?= (int) $p['id'] ?>/tasks/<?= (int) $s['id'] ?>/delete" onsubmit="return confirm('Remove &quot;<?= e($s['name']) ?>&quot;?')"><?= Csrf::field() ?><button class="linklike text-red">Remove</button></form>
									</div>
								</details>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="phase-add">
				<form method="post" action="/projects/<?= (int) $p['id'] ?>/tasks" class="inline-add">
					<?= Csrf::field() ?>
					<input type="hidden" name="phase" value="<?= $phaseKey ?>">
					<input type="text" name="name" placeholder="Add a custom <?= $phaseKey === 'payments' ? 'payment' : 'task' ?> (e.g. <?= $phaseKey === 'payments' ? 'Materials payment' : 'Connect well pump' ?>)" required>
					<button class="btn btn-ghost btn-small">Add</button>
				</form>
				<?php if ($phaseTemplates): ?>
					<form method="post" action="/projects/<?= (int) $p['id'] ?>/tasks/from-template" class="inline-add">
						<?= Csrf::field() ?>
						<select name="template_id" required>
							<option value="">Add from template...</option>
							<?php foreach ($phaseTemplates as $tt): ?><option value="<?= (int) $tt['id'] ?>"><?= e($tt['name']) ?></option><?php endforeach; ?>
						</select>
						<button class="btn btn-ghost btn-small">Add</button>
					</form>
				<?php endif; ?>
			</div>
		</section>
	<?php endforeach; ?>
</div>

<form method="post" action="/projects/<?= (int) $p['id'] ?>/tasks" id="add-sub-form" hidden>
	<?= Csrf::field() ?>
	<input type="hidden" name="parent_id">
	<input type="hidden" name="name">
</form>

<section class="card mt" id="log">
	<div class="log-head">
		<h2>Project log</h2>
		<div class="seg">
			<a href="<?= $back ?>#log" class="<?= $commentsOnly ? '' : 'active' ?>">All activity</a>
			<a href="<?= $back ?>?log=comments#log" class="<?= $commentsOnly ? 'active' : '' ?>">Comments only</a>
		</div>
	</div>
	<form method="post" action="/projects/<?= (int) $p['id'] ?>/comments" class="comment-form">
		<?= Csrf::field() ?>
		<textarea name="body" rows="2" placeholder="Add a comment... (you can edit or delete it for 7 days)" required></textarea>
		<button class="btn btn-primary btn-small">Post</button>
	</form>
	<?= App\View::partial('partials/activity', ['entries' => $activity, 'back' => $back . '#log']) ?>
</section>

<script src="<?= asset('assets/js/tasks.js') ?>"></script>
