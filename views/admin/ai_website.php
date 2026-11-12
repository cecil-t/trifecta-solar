<?php
use App\Claude;
use App\Csrf;

/**
 * @var bool    $gfSet     the GF_ lines are in .env
 * @var int     $formId
 * @var ?array  $lastPoll  newest website_polls row
 * @var ?array  $lastOk    newest successful one
 * @var array   $counts    web_requests by status
 * @var bool    $claudeSet ANTHROPIC_API_KEY is in .env
 * @var string  $model
 * @var array   $perBill  model id => dollars per bill read (App\Claude::perBill)
 * @var array   $usage30   App\Claude::usage(30)
 * @var array   $usageAll
 * @var ?bool   $heic      photo conversion with HEIC installed (null: no Python here)
 * @var ?array  $result    the last check's result
 * @var array   $activity
 */
$chip = static fn (bool $ok, string $yes = 'Set up', string $no = 'Not set up'): string => '<span class="chip ' . ($ok ? 'chip-green' : 'chip-orange') . '">' . e($ok ? $yes : $no) . '</span>';
$usageRows = static function (array $u): array {
	$t = ['calls' => 0, 'failed' => 0, 'cost' => 0.0];
	foreach ($u as $r) {
		$t['calls'] += $r['calls'];
		$t['failed'] += $r['failed'];
		$t['cost'] += $r['cost'];
	}
	return $t;
};
$t30 = $usageRows($usage30);
$tAll = $usageRows($usageAll);
?>
<div class="page-head">
	<div>
		<a href="/about#services" class="back">&larr; About</a>
		<h1>Website and AI<?= help_link('ai-website') ?></h1>
		<p class="muted">Quote requests from the company website's form, and Claude, which reads electric bills. Keys live in the server's .env file and show only as set or not set.</p>
	</div>
</div>

<?php if ($result): ?>
	<section class="card mb">
		<?php if ($result['kind'] === 'form'): ?>
			<h2>Website form check</h2>
			<?php if (!$result['ok']): ?>
				<p class="text-orange"><?= e((string) $result['error']) ?></p>
			<?php else: ?>
				<p>Form <?= (int) $formId ?>: <strong><?= e($result['title']) ?></strong>, <?= (int) $result['total'] ?> entr<?= $result['total'] === 1 ? 'y' : 'ies' ?> on the website.</p>
				<div class="scroll-x-phone">
					<table class="table table-tight">
						<thead><tr><th>Field ID</th><th>Label</th><th>Type</th><th>Used as</th></tr></thead>
						<tbody>
						<?php foreach ($result['fields'] as $f): ?>
							<tr><td><?= e($f['id']) ?></td><td><?= e($f['label']) ?></td><td><?= e($f['type']) ?></td><td><?= $f['ours'] !== '' ? e($f['ours']) : '<span class="muted">Not used</span>' ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php if ($result['newest']): ?>
					<h3 class="mt">Newest entries</h3>
					<ul class="plain small">
						<?php foreach ($result['newest'] as $n): ?>
							<li>#<?= (int) $n['id'] ?>, <?= e(fmt_dt(gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($n['date'] . ' UTC')))) ?>: <?= e($n['name'] !== '' ? $n['name'] : '(no name)') ?><?= $n['files'] ? ', ' . (int) $n['files'] . ' file(s)' : '' ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		<?php elseif ($result['kind'] === 'poll'): ?>
			<h2>Website check</h2>
			<p class="<?= $result['ok'] ? '' : 'text-orange' ?>"><?= $result['ok'] ? (int) $result['new'] . ' new request(s); ' . (int) $result['read'] . ' bill(s) read.' : e((string) $result['error']) ?></p>
			<?php if ($result['log'] ?? []): ?><ul class="plain small"><?php foreach ($result['log'] as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul><?php endif; ?>
		<?php else: ?>
			<h2>Bill test</h2>
			<?php if (!$result['ok']): ?>
				<p class="text-orange"><?= e((string) $result['error']) ?></p>
			<?php else: ?>
				<p class="small muted"><?= e(Claude::MODELS[$result['model']][0] ?? $result['model']) ?>, this read cost <?= e(Claude::cents((float) $result['cost'])) ?>. Nothing was saved.</p>
				<?php foreach ($result['raws'] as $bi => $raw): ?>
					<?php if (count($result['raws']) > 1): ?><h3 class="mt">Bill <?= $bi + 1 ?></h3><?php endif; ?>
					<dl class="kv">
						<?php foreach ($raw as $k => $val): if ($val === '' || $val === []) { continue; } ?>
							<dt><?= e(str_replace('_', ' ', $k)) ?></dt>
							<dd><?= e(is_array($val) ? implode(', ', array_map(static fn ($x) => is_array($x) ? $x['month'] . ': ' . number_format((float) $x['kwh']) : (string) $x, $val)) : (string) $val) ?></dd>
						<?php endforeach; ?>
					</dl>
				<?php endforeach; ?>
			<?php endif; ?>
		<?php endif; ?>
	</section>
<?php endif; ?>

<div class="grid-2">
	<section class="card">
		<h2>Website quote form <?= $chip($gfSet) ?></h2>
		<dl class="kv">
			<dt>Connection</dt><dd>Gravity Forms REST API v2, read-only key, form <?= (int) $formId ?></dd>
			<dt>Schedule</dt><dd>Weekdays every 15 minutes, 8 AM to 6 PM; Saturday and Sunday once, at noon</dd>
			<dt>Last check</dt>
			<dd>
				<?php if ($lastPoll): ?>
					<?= e(fmt_dt($lastPoll['started_at'])) ?><?= $lastPoll['forced'] ? ' <span class="muted small">(by hand)</span>' : '' ?>:
					<?= $lastPoll['ok'] ? (int) $lastPoll['new_count'] . ' new' : '<span class="text-orange">' . e((string) $lastPoll['error']) . '</span>' ?>
				<?php else: ?><span class="muted">Never</span><?php endif; ?>
			</dd>
			<?php if ($lastPoll && !$lastPoll['ok'] && $lastOk): ?><dt>Last good check</dt><dd><?= e(fmt_dt($lastOk['started_at'])) ?></dd><?php endif; ?>
			<dt>Requests</dt><dd><?= (int) ($counts['pending'] ?? 0) ?> pending, <?= (int) ($counts['created'] ?? 0) ?> made into opportunities, <?= (int) ($counts['dismissed'] ?? 0) ?> dismissed</dd>
		</dl>
		<p class="hint mt-xs">The schedule runs from the server's cron (website:poll every 15 minutes; it skips the times that are not due).</p>
		<div class="btn-row">
			<form method="post" action="/admin/ai-website/form"><?= Csrf::field() ?><button class="btn btn-secondary btn-small" <?= $gfSet ? '' : 'disabled' ?>>Check the form</button></form>
			<form method="post" action="/admin/ai-website/poll" data-busy="Checking..."><?= Csrf::field() ?><button class="btn btn-secondary btn-small" <?= $gfSet ? '' : 'disabled' ?>>Check the website now</button></form>
		</div>
		<p class="hint">Check the form lists its fields and newest entries and saves nothing. Check the website now brings in new requests and reads their bills, like a scheduled check.</p>
	</section>

	<section class="card">
		<h2>Claude <?= $chip($claudeSet) ?></h2>
		<form method="post" action="/admin/ai-website/model" class="model-form">
			<?= Csrf::field() ?>
			<label>Model
				<select name="model">
					<?php foreach (Claude::MODELS as $id => [$label]): ?>
						<option value="<?= e($id) ?>" <?= $id === $model ? 'selected' : '' ?>><?= e($label) ?> (<?= e(Claude::cents($perBill[$id] ?? 0)) ?> a bill)</option>
					<?php endforeach; ?>
				</select>
			</label>
			<button class="btn btn-primary btn-small">Save</button>
		</form>
		<p class="hint">Sonnet reads bills well; try Haiku on a few real bills with the test below, and switch if it reads them as well for a fraction of the cost.</p>
		<dl class="kv mt">
			<dt>Connection</dt><dd>Claude API (Messages, version <?= e(Claude::API_VERSION) ?>), from the server only</dd>
			<dt>Photos</dt><dd><?= $heic === true ? 'PDF, JPG, PNG, WebP and HEIC' : ($heic === false ? 'PDF, JPG, PNG and WebP; HEIC after the server image is rebuilt' : 'PDF; photos need the server image') ?></dd>
			<dt>Last 30 days</dt><dd><?= (int) $t30['calls'] ?> call(s)<?= $t30['failed'] ? ', ' . (int) $t30['failed'] . ' failed' : '' ?>, $<?= e(number_format($t30['cost'], 2)) ?></dd>
			<dt>All time</dt><dd><?= (int) $tAll['calls'] ?> call(s), $<?= e(number_format($tAll['cost'], 2)) ?></dd>
		</dl>
		<?php if ($usage30): ?>
			<table class="table table-tight ai-usage mt-xs">
				<thead><tr><th>Last 30 days</th><th class="num">Calls</th><th class="num">Each</th><th class="num">Cost</th></tr></thead>
				<tbody>
				<?php foreach ($usage30 as $p => $u): ?>
					<tr><td><?= e(Claude::PURPOSES[$p] ?? $p) ?></td><td class="num"><?= (int) $u['calls'] ?></td><td class="num"><?= e(Claude::cents($u['cost'] / max(1, $u['calls']))) ?></td><td class="num">$<?= e(number_format($u['cost'], 2)) ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="hint">Worked out from Anthropic's list prices; the Claude Console has the billed amounts.</p>
		<?php endif; ?>
		<h3 class="mt">Test a bill</h3>
		<form method="post" action="/admin/ai-website/bill" enctype="multipart/form-data" data-busy="Reading...">
			<?= Csrf::field() ?>
			<div class="bill-read-row">
				<input type="file" name="bill[]" class="bill-files" multiple accept=".pdf,.jpg,.jpeg,.png,.heic,.heif,.webp,application/pdf,image/*" aria-label="Bill files" required>
				<button class="btn btn-secondary btn-small" <?= $claudeSet ? '' : 'disabled' ?>>Read it</button>
			</div>
			<p class="hint">Shows everything Claude found on the bill, with the cost. Nothing is saved, and the bill is not kept.</p>
		</form>
	</section>
</div>

<?php if ($activity): ?>
	<section class="card mt">
		<h2>Log</h2>
		<ul class="log-list small">
			<?php foreach ($activity as $a): ?>
				<li><span class="muted"><?= e(fmt_dt($a['created_at'])) ?></span> <?= e((string) ($a['user_name'] ?? 'System')) ?>: <?= e((string) $a['body']) ?></li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>
