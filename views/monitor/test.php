<?php
use App\Csrf;

/**
 * @var bool    $configured
 * @var array   $calls    key => label
 * @var ?array  $result   from App\SolarEdge::get plus call and at
 * @var ?string $error
 * @var string  $siteId
 * @var array   $sites    parsed site list when the last call was the site list
 */
$pretty = '';
if ($result) {
	$pretty = is_array($result['json'])
		? (string) json_encode($result['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		: (string) $result['body'];
	if (strlen($pretty) > 60000) {
		$pretty = substr($pretty, 0, 60000) . "\n... (cut off at 60,000 characters)";
	}
}
?>
<div class="page-head">
	<div>
		<h1>Monitoring API test</h1>
		<p class="muted">Admin test page for SolarEdge Monitoring API V2. Each button makes exactly <strong>one</strong> call from the server; check the developer dashboard's usage afterward to see what it cost. Nothing is saved.</p>
	</div>
</div>

<?php if (!$configured): ?>
	<div class="flash flash-error">No SolarEdge key is set. Add <code>SOLAREDGE_API_KEY</code> to the server .env file; it is read on the next page load.</div>
<?php endif; ?>
<?php if ($error): ?>
	<div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<section class="card">
	<h2>Make one call</h2>
	<form method="post" action="/admin/monitor-test" class="monitor-test-form">
		<?= Csrf::field() ?>
		<label>SolarEdge site ID <span class="muted small">(only for the one-site calls)</span>
			<input type="text" name="site_id" value="<?= e($siteId) ?>" inputmode="numeric" pattern="\d{1,12}" autocomplete="off">
		</label>
		<div class="monitor-test-buttons">
			<?php foreach ($calls as $key => $label): ?>
				<button type="submit" name="call" value="<?= e($key) ?>" class="btn btn-secondary"<?= $configured ? '' : ' disabled' ?>><?= e($label) ?></button>
			<?php endforeach; ?>
		</div>
	</form>
</section>

<?php if ($result): ?>
	<section class="card mt">
		<h2><?= e($result['call']) ?></h2>
		<dl class="kv">
			<dt>Called at</dt><dd><?= e($result['at']) ?></dd>
			<dt>URL</dt><dd><code class="monitor-wrap"><?= e($result['url']) ?></code></dd>
			<dt>HTTP status</dt><dd><?= $result['status'] ? (int) $result['status'] : 'No response' ?></dd>
			<dt>Time</dt><dd><?= (int) $result['ms'] ?> ms</dd>
			<?php if ($result['error']): ?><dt>Error</dt><dd><?= e($result['error']) ?></dd><?php endif; ?>
			<dt>Body size</dt><dd><?= number_format(strlen($result['body'])) ?> bytes</dd>
		</dl>
	</section>

	<?php if ($sites): ?>
		<section class="card card-flush mt">
			<div class="card-head"><h2>Sites returned: <?= count($sites) ?></h2></div>
			<table class="table">
				<thead><tr><th>Site ID</th><th>Name</th><th>Peak power</th><th>Status</th></tr></thead>
				<tbody>
				<?php foreach ($sites as $s): ?>
					<tr>
						<td><code><?= e((string) ($s['siteId'] ?? $s['id'] ?? '')) ?></code></td>
						<td><?= e((string) ($s['name'] ?? '')) ?></td>
						<td><?= e((string) ($s['peakPower'] ?? '')) ?></td>
						<td><?= e((string) ($s['activationStatus'] ?? $s['status'] ?? '')) ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	<?php endif; ?>

	<section class="card mt">
		<h2>Response headers</h2>
		<pre class="monitor-pre"><?= e(implode("\n", $result['headers'])) ?></pre>
	</section>

	<section class="card mt">
		<h2>Response body</h2>
		<pre class="monitor-pre"><?= e($pretty) ?></pre>
	</section>
<?php endif; ?>
