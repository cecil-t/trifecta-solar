<?php
use App\Csrf;

/**
 * @var bool   $installed the venv's Python exists
 * @var string $python    path to the venv's Python
 * @var string $libFolder folder with SAM's CEC libraries
 * @var ?array $result    reply from bin/sam.py (check), plus ms and at
 */
$test = $result['test_run'] ?? null;
?>
<div class="page-head">
	<div>
		<h1>SAM</h1>
		<p class="muted">SAM (System Advisor Model, National Laboratory of the Rockies) on this server, for production estimates. Admins only; nothing here is saved or shown to anyone else.</p>
	</div>
</div>

<?php if ($result && !$result['ok']): ?>
	<div class="flash flash-error"><?= e($result['error']) ?></div>
	<?php if (!empty($result['trace'])): ?>
		<section class="card">
			<h2>Details</h2>
			<pre class="small"><?= e((string) $result['trace']) ?></pre>
		</section>
	<?php endif; ?>
<?php endif; ?>

<?php if ($result && $result['ok']): ?>
	<section class="card">
		<h2>Check passed</h2>
		<p class="muted small"><?= e($result['at']) ?></p>
		<dl class="kv">
			<dt>PySAM</dt><dd><?= e((string) $result['pysam']) ?> on Python <?= e((string) $result['python']) ?></dd>
			<dt>SAM libraries</dt><dd><?= e((string) ($result['library_release'] ?? 'Release unknown (no VERSION file)')) ?></dd>
			<?php foreach ($result['libraries'] as $lib): ?>
				<dt><code><?= e((string) $lib['file']) ?></code></dt>
				<dd><?= $lib['found'] ? e(number_format((int) $lib['rows'])) . ' entries' : 'Missing' ?></dd>
			<?php endforeach; ?>
			<?php if ($test): ?>
				<dt>Test run</dt><dd><?= e((string) $test['model']) ?></dd>
				<dt>Result</dt><dd><?= e(number_format((float) $test['system_kw_dc'], 1)) ?> kW DC, <?= e(number_format((int) $test['annual_kwh'])) ?> kWh a year (made-up weather, so the number means nothing)</dd>
				<dt>SAM run time</dt><dd><?= e(number_format((float) $test['seconds'], 2)) ?> s</dd>
			<?php endif; ?>
			<dt>Whole call</dt><dd><?= e(number_format((int) $result['ms'] / 1000, 2)) ?> s, peak memory <?= (int) $result['peak_memory_mb'] ?> MB</dd>
		</dl>
	</section>
<?php endif; ?>

<section class="card mt">
	<h2>Setup</h2>
	<dl class="kv">
		<dt>Installed</dt><dd><?= $installed ? 'Yes' : 'No: rebuild the Docker image' ?></dd>
		<dt>Python</dt><dd><code><?= e($python) ?></code></dd>
		<dt>Libraries</dt><dd><code><?= e($libFolder) ?></code></dd>
	</dl>
	<form method="post" action="/admin/sam" class="mt">
		<?= Csrf::field() ?>
		<button type="submit" class="btn btn-primary">Run check</button>
		<span class="muted small">Starts SAM, reads the library files and runs the detailed PV model once (about a second).</span>
	</form>
</section>
