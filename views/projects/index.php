<?php
use App\Tasks;
use App\Projects;

$tabs = [
	'active' => 'Active', 'pre_install' => 'Pre-Install', 'clear' => 'Clear to install',
	'installation' => 'Installation', 'closeout' => 'Closeout', 'on_hold' => 'On hold',
	'complete' => 'Completed', 'cancelled' => 'Cancelled', 'all' => 'All',
];
// Completed and Cancelled fold earlier years under a click-to-open header: the project # year,
// or the contract signed year for early jobs numbered without a year code (01, 02...)
$foldYears = $filter['q'] === '' && in_array($filter['phase'], ['complete', 'cancelled'], true);
$thisYear = (int) date('Y');
$groups = [];
foreach ($projects as $p) {
	$yr = match (true) {
		(bool) preg_match('/^(\d{2})\d{3}/', (string) $p['project_number'], $m) => 2000 + (int) $m[1],
		!empty($p['status']['start_date']) => (int) substr($p['status']['start_date'], 0, 4),
		default => $thisYear,
	};
	$groups[$foldYears && $yr < $thisYear ? $yr : $thisYear][] = $p;
}
krsort($groups);
$qs = static fn (array $over) => '/projects?' . http_build_query(array_filter(array_merge($filter, $over), static fn ($v) => $v !== '' && $v !== 0 && $v !== null));
?>
<div class="page-head">
	<div>
		<h1>Projects</h1>
	</div>
	<a href="/projects/new" class="btn btn-primary">New project</a>
</div>

<div class="tabs tabs-grouped">
	<?php foreach ($tabs as $key => $label): ?>
		<?php if ($key === 'active'): ?><div class="tab-group" title="Active is Pre-Install, Installation and Closeout together"><?php endif; ?>
		<a href="<?= e($qs(['phase' => $key, 'q' => null])) ?>" class="tab <?= $key === 'clear' ? 'tab-sub' : '' ?> <?= $filter['phase'] === $key ? 'active' : '' ?>"<?= $key === 'clear' ? ' title="Part of Pre-Install: building permit received and interconnection approved"' : '' ?>>
			<?= $key === 'clear' ? '<span class="tab-sub-mark" aria-hidden="true">&#8627;</span>' : '' ?><?= e($label) ?> <span class="tab-count"><?= (int) ($counts[$key] ?? 0) ?></span>
		</a>
		<?php if ($key === 'closeout'): ?></div><?php endif; ?>
	<?php endforeach; ?>
</div>

<form class="filters" method="get" action="/projects">
	<input type="search" name="q" value="<?= e($filter['q']) ?>" placeholder="Search name, #, customer, municipality">
	<select name="sales" onchange="this.form.submit()">
		<option value="">All salespeople</option>
		<?php foreach ($users as $u): ?>
			<option value="<?= (int) $u['id'] ?>" <?= $filter['sales'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
		<?php endforeach; ?>
	</select>
	<button class="btn btn-secondary btn-small">Filter</button>
</form>

<div class="card card-flush card-sticky-head">
	<table class="table table-projects">
		<thead>
		<tr><th>#</th><th>Project</th><th>Sales</th><th class="num">kW DC</th><th>Type</th><th>Municipality</th><th>Status</th><th class="num">Days</th></tr>
		</thead>
		<?php if (!$projects): ?><tbody>
			<tr><td colspan="8" class="empty">No projects match.</td></tr>
		</tbody><?php endif; ?>
		<?php foreach ($groups as $yr => $list): ?>
		<?php if ($yr < $thisYear): ?>
			<tbody class="year-head"><tr><td colspan="8"><button type="button" class="year-toggle" aria-expanded="false" onclick="var b=this.closest('tbody').nextElementSibling;b.hidden=!b.hidden;this.setAttribute('aria-expanded',!b.hidden)"><span class="year-caret">&#9656;</span> <?= $yr ?> projects <span class="tab-count"><?= count($list) ?></span></button></td></tr></tbody>
		<?php endif; ?>
		<tbody <?= $yr < $thisYear ? 'hidden' : '' ?>>
		<?php foreach ($list as $p): $st = $p['status']; ?>
			<tr id="project-<?= (int) $p['id'] ?>" onclick="if(!event.target.closest('a'))location='/projects/<?= (int) $p['id'] ?>'" class="clickable">
				<td><strong><?= e($p['project_number']) ?></strong><?php if ($icons = App\Icons::scope($p)): ?><div class="scope-icons"><?= $icons ?></div><?php endif; ?></td>
				<td>
					<a href="/projects/<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a>
					<?php if ($drive = safe_url($p['drive_url'])): ?><a href="<?= e($drive) ?>" target="_blank" rel="noopener" class="drive-link" title="Open Google Drive folder">Drive &#8599;</a><?php endif; ?>
					<?php if ($p['status_note']): ?><div class="muted small cell-note"><?= e($p['status_note']) ?></div><?php endif; ?>
				</td>
				<td><?= e($p['sales_initials'] ?? '') ?></td>
				<td class="num"><?= $p['dc_kw'] !== null ? number_format($p['dc_kw'], 1) : '' ?></td>
				<?php
				$scope = array_filter([
					$p['install_type'] ? Projects::INSTALL_TYPES[$p['install_type']] : null,
					$p['has_batteries'] ? 'Batteries' . ($p['batt_kwh'] ? ' ' . rtrim(rtrim(number_format($p['batt_kwh'], 1), '0'), '.') . ' kWh' : '') : null,
				]);
				?>
				<td class="small"><?= e(Projects::CUSTOMER_TYPES[$p['customer_type']] ?? '') ?><?= $scope ? '<div class="muted">' . e(implode(' + ', $scope)) . '</div>' : '' ?></td>
				<td class="small"><?= e($p['municipality_name'] ?? '') ?><?= $p['county_name'] ? '<div class="muted">' . e($p['county_name']) . ' Co.</div>' : '' ?></td>
				<td>
					<span class="phase phase-<?= e($st['phase']) ?>"><?= e(Tasks::PHASE_LABELS[$st['phase']]) ?></span>
					<?php if ($p['hold_state'] === 'on_hold'): ?><span class="chip chip-orange" title="<?= e($p['hold_reason']) ?>">On hold</span><?php endif; ?>
					<?php if ($st['phase'] === 'pre_install' && $st['clear_to_install']): ?><span class="chip chip-green">Clear to install</span><?php endif; ?>
				</td>
				<td class="num"><?= $st['days'] ?? '' ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
		<?php endforeach; ?>
	</table>
</div>
