<?php
use App\Csrf;

/**
 * @var array   $calls          key => label
 * @var bool    $solaredge      SolarEdge key set
 * @var bool    $enphase        Enphase app credentials set
 * @var bool    $enlighten       Enlighten sign-in set in .env
 * @var bool    $enlightenSession a saved Enlighten session exists
 * @var ?array  $enphaseStatus  saved token times (no tokens), from App\Enphase::status
 * @var string  $callbackUrl
 * @var string  $fallbackUrl     approval page that returns to Enphase's own code page
 * @var ?array  $result         from App\Http::request plus url, call and at
 * @var ?string $error
 * @var string  $siteId
 * @var string  $systemId
 * @var array   $rows           sites or systems parsed from a list call
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
$when = static fn (int $ts): string => $ts ? date('m/d/Y g:i A', $ts) : '';
$buttons = static function (array $keys) use ($calls): string {
	$out = '';
	foreach ($keys as $key) {
		$out .= '<button type="submit" name="call" value="' . e($key) . '" class="btn btn-secondary">' . e($calls[$key]) . '</button>';
	}
	return $out;
};
?>
<div class="page-head">
	<div>
		<h1>Monitoring API test</h1>
		<p class="muted">Admin test page for the inverter monitoring APIs. Each call button makes exactly <strong>one</strong> call from the server; check the vendor's usage page afterward to see what it cost. API responses are not saved.</p>
	</div>
</div>

<?php if ($error): ?>
	<div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<div class="grid-2">
	<section class="card">
		<h2>SolarEdge <span class="muted small">(Monitoring API V2)</span></h2>
		<?php if (!$solaredge): ?>
			<p class="muted">Not set up. Add <code>SOLAREDGE_API_KEY</code> to the server .env file.</p>
		<?php else: ?>
			<form method="post" action="/admin/monitor-test" class="monitor-test-form">
				<?= Csrf::field() ?>
				<label>SolarEdge site ID <span class="muted small">(only for the one-site calls)</span>
					<input type="text" name="site_id" value="<?= e($siteId) ?>" inputmode="numeric" pattern="\d{1,12}" autocomplete="off">
				</label>
				<div class="monitor-test-buttons"><?= $buttons(['sites', 'alerts', 'overview', 'energy']) ?></div>
			</form>
		<?php endif; ?>
	</section>

	<section class="card">
		<h2>Enphase <span class="muted small">(Enlighten API v4, Watt plan)</span></h2>
		<?php if (!$enphase): ?>
			<p class="muted">Not set up. Add <code>ENPHASE_API_KEY</code>, <code>ENPHASE_CLIENT_ID</code> and <code>ENPHASE_CLIENT_SECRET</code> to the server .env file.</p>
		<?php else: ?>
			<dl class="kv">
				<dt>Connection</dt>
				<dd>
					<?php if ($enphaseStatus): ?>
						Connected <?= e($when($enphaseStatus['connected_at'])) ?>
						<div class="muted small">Access token good until <?= e($when($enphaseStatus['expires_at'])) ?>; last refreshed <?= e($when($enphaseStatus['refreshed_at'])) ?>. The refresh token lasts about a month from then.</div>
					<?php else: ?>
						Not connected
					<?php endif; ?>
				</dd>
				<dt>Returns to</dt><dd><code class="monitor-wrap"><?= e($callbackUrl) ?></code></dd>
			</dl>
			<div class="monitor-test-buttons mt-sm">
				<a href="/admin/monitor-test/enphase-connect" class="btn btn-primary"><?= $enphaseStatus ? 'Reconnect' : 'Connect' ?> Enphase</a>
				<?php if ($enphaseStatus): ?>
					<form method="post" action="/admin/monitor-test/enphase-disconnect" data-confirm="Remove the saved Enphase tokens from this server?">
						<?= Csrf::field() ?>
						<button type="submit" class="btn btn-ghost">Disconnect</button>
					</form>
				<?php endif; ?>
			</div>
			<p class="muted small mt-sm">Connect opens Enphase's approval page; sign in with the Enlighten account that should grant access, approve, and you come back here.</p>

			<?php if ($enphaseStatus): ?>
				<form method="post" action="/admin/monitor-test" class="monitor-test-form mt">
					<?= Csrf::field() ?>
					<label>Enphase system ID <span class="muted small">(only for the one-system calls)</span>
						<input type="text" name="system_id" value="<?= e($systemId) ?>" inputmode="numeric" pattern="\d{1,12}" autocomplete="off">
					</label>
					<div class="monitor-test-buttons"><?= $buttons(['enphase_systems', 'enphase_summary', 'enphase_energy', 'enphase_refresh']) ?></div>
				</form>
			<?php endif; ?>

			<details class="mt">
				<summary class="small">Approval page cannot come back here?</summary>
				<p class="muted small">Approve through Enphase's own landing page instead: <a href="<?= e($fallbackUrl) ?>" rel="noopener" target="_blank">open the approval page</a> (it returns to Enphase's page, which shows a code), then paste the code here.</p>
				<form method="post" action="/admin/monitor-test/enphase-code" class="monitor-test-form">
					<?= Csrf::field() ?>
					<label>Authorization code
						<input type="text" name="code" autocomplete="off" required>
					</label>
					<div><button type="submit" class="btn btn-secondary">Trade code for tokens</button></div>
				</form>
			</details>
		<?php endif; ?>
	</section>
</div>

<section class="card mt">
	<h2>Enphase installer data <span class="muted small">(Enlighten Manager sign-in, not an API)</span></h2>
	<?php if (!$enlighten): ?>
		<p class="muted">Not set up. Add <code>ENPHASE_ENLIGHTEN_EMAIL</code> and <code>ENPHASE_ENLIGHTEN_PASSWORD</code> to the server .env file.</p>
	<?php else: ?>
		<p class="muted small">Signs in to Enlighten as the installer user and reads the same systems table Enlighten Manager shows. Session: <?= $enlightenSession ? 'saved on this server' : 'none yet (the first call signs in)' ?>.</p>
		<div class="monitor-test-buttons">
			<form method="post" action="/admin/monitor-test">
				<?= Csrf::field() ?>
				<?= $buttons(['enlighten_systems', 'enlighten_login']) ?>
			</form>
			<?php if ($enlightenSession): ?>
				<form method="post" action="/admin/monitor-test/enlighten-forget">
					<?= Csrf::field() ?>
					<button type="submit" class="btn btn-ghost">Forget session</button>
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</section>

<?php if ($result): ?>
	<section class="card mt">
		<h2><?= e($result['call']) ?></h2>
		<dl class="kv">
			<dt>Called at</dt><dd><?= e($result['at']) ?></dd>
			<dt>URL</dt><dd><code class="monitor-wrap"><?= e($result['url']) ?></code></dd>
			<dt>HTTP status</dt><dd><?= $result['status'] ? (int) $result['status'] : 'No response' ?></dd>
			<dt>Time</dt><dd><?= (int) $result['ms'] ?> ms</dd>
			<?php if (!empty($result['note'])): ?><dt>Note</dt><dd><?= e($result['note']) ?></dd><?php endif; ?>
			<?php if ($result['error']): ?><dt>Error</dt><dd><?= e($result['error']) ?></dd><?php endif; ?>
			<?php if (isset($result['cookies'])): ?><dt>Session cookies</dt><dd><?= $result['cookies'] ? e(implode(', ', $result['cookies'])) : 'none' ?> <span class="muted small">(names only)</span></dd><?php endif; ?>
			<dt>Body size</dt><dd><?= number_format(strlen($result['body'])) ?> bytes</dd>
		</dl>
	</section>

	<?php if ($rows): ?>
		<section class="card card-flush mt">
			<div class="card-head"><h2>Returned: <?= count($rows) ?></h2></div>
			<table class="table">
				<thead><tr><th>ID</th><th>Name</th><th>Size or today (Wh)</th><th>Status</th></tr></thead>
				<tbody>
				<?php foreach ($rows as $row): ?>
					<tr>
						<td><code><?= e($row['id']) ?></code></td>
						<td><?= e($row['name']) ?></td>
						<td><?= e($row['size']) ?></td>
						<td><?= e($row['status']) ?></td>
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
