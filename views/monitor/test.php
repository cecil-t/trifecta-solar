<?php
use App\Csrf;

/**
 * @var array   $vendors          per manufacturer: label, source, configured, env, check [reads, cost], last (run row), last_ok
 * @var bool    $enlightenSession a saved Enlighten session exists
 * @var ?array  $growattStatus    connected_at, last_ok, session_until (no token)
 * @var ?string $growattCaptcha   captcha image (data URI) while connecting
 * @var ?array  $smaStatus        signed_in_at, refresh_until (no tokens)
 * @var int     $seCreditsLeft    SolarEdge credits left this cycle
 * @var ?array  $result           a check's reply plus url, call and at
 * @var ?string $error
 * @var array   $rows             sites or systems parsed from the check
 */
$when = static fn (int $ts): string => $ts ? date('m/d/Y g:i A', $ts) : '';
$utc = static fn (?string $t): string => $t ? date('m/d/Y g:i A', (int) strtotime($t)) : '';
?>
<div class="page-head">
	<div>
		<h1>Monitor APIs</h1>
		<p class="muted">Connections the monitoring collector uses. Each check makes one call from the server and lists what came back; nothing is saved.</p>
	</div>
</div>

<?php if ($error): ?>
	<div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
	<section class="card card-flush">
		<div class="card-head">
			<h2><?= e($result['call']) ?></h2>
			<span class="muted small"><?= e($result['at']) ?></span>
		</div>
		<div class="monitor-result">
			<dl class="kv">
				<dt>Result</dt><dd><?= $result['status'] ? 'HTTP ' . (int) $result['status'] : 'No response' ?>, <?= (int) $result['ms'] ?> ms</dd>
				<?php if (!empty($result['note'])): ?><dt>Note</dt><dd><?= e($result['note']) ?></dd><?php endif; ?>
				<?php if ($result['error'] && $result['error'] !== ($result['note'] ?? null)): ?><dt>Error</dt><dd><?= e($result['error']) ?></dd><?php endif; ?>
				<?php if (is_array($result['json'] ?? null) && !$rows): ?><dt>Reply</dt><dd><code class="monitor-wrap"><?= e(substr((string) json_encode($result['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 400)) ?></code></dd><?php endif; ?>
			</dl>
		</div>
		<?php if ($rows): ?>
			<table class="table">
				<thead><tr><th>ID</th><th>Name</th><th>Size</th><th>Status</th></tr></thead>
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
			<p class="muted small monitor-result"><?= count($rows) ?> returned.</p>
		<?php endif; ?>
	</section>
<?php endif; ?>

<div class="grid-2 mt">
	<?php foreach ($vendors as $key => $v): ?>
		<section class="card" id="<?= e($key) ?>">
			<h2><span class="mon-dot mon-dot-<?= e($key) ?>"></span><?= e($v['label']) ?></h2>
			<p class="muted small"><?= e($v['source']) ?>.</p>
			<?php if (!$v['configured']): ?>
				<p class="muted">Not set up. Add <?php foreach ($v['env'] as $i => $name): ?><?= $i ? ' and ' : '' ?><code><?= e($name) ?></code><?php endforeach; ?> to the server .env file.</p>
			<?php else: ?>
				<dl class="kv">
					<dt>Last pull</dt>
					<dd>
						<?php if ($v['last']): ?>
							<?= $v['last']['ok'] ? 'OK' : 'Failed' ?> <?= e($utc($v['last']['finished_at'])) ?>, <?= (int) $v['last']['calls'] ?> <?= (int) $v['last']['calls'] === 1 ? 'call' : 'calls' ?>
							<?php if (!$v['last']['ok']): ?><div class="mon-fail small"><?= e((string) $v['last']['message']) ?></div><?php if ($v['last_ok']): ?><div class="muted small">Last good pull <?= e($utc((string) $v['last_ok'])) ?></div><?php endif; ?><?php endif; ?>
						<?php else: ?>
							None yet
						<?php endif; ?>
					</dd>
					<?php if ($key === 'enphase'): ?>
						<dt>Session</dt><dd><?= $enlightenSession ? 'Saved on this server' : 'None (the next pull signs in)' ?></dd>
					<?php elseif ($key === 'sma'): ?>
						<dt>Session</dt><dd><?= $smaStatus ? 'Signed in ' . e($when($smaStatus['signed_in_at'])) . '<div class="muted small">Renews on its own until ' . e($when($smaStatus['refresh_until'])) . ', then the next pull signs in again.</div>' : 'None (the next pull signs in)' ?></dd>
					<?php elseif ($key === 'growatt'): ?>
						<dt>Session</dt>
						<dd>
							<?php if ($growattStatus): ?>
								Connected <?= e($when($growattStatus['connected_at'])) ?>
								<?php if ($growattStatus['session_until']): ?><div class="muted small">Growatt's 30-day session runs until <?= e($when($growattStatus['session_until'])) ?>; connect again before then.</div><?php endif; ?>
							<?php else: ?>
								Not connected
							<?php endif; ?>
						</dd>
					<?php elseif ($key === 'solaredge'): ?>
						<dt>Credits</dt><dd><?= number_format($seCreditsLeft) ?> left this cycle</dd>
					<?php endif; ?>
				</dl>

				<?php if ($key === 'growatt' && $growattCaptcha): ?>
					<form method="post" action="/admin/monitor-test/growatt-login" class="monitor-test-form mt-sm">
						<?= Csrf::field() ?>
						<img class="monitor-captcha" src="<?= e($growattCaptcha) ?>" alt="Growatt captcha">
						<label>Type the characters in the picture
							<input type="text" name="captcha" maxlength="8" autocomplete="off" required autofocus>
						</label>
						<div><button type="submit" class="btn btn-primary">Sign in to Growatt</button></div>
					</form>
				<?php else: ?>
					<div class="monitor-test-buttons mt-sm">
						<?php if ($key === 'growatt'): ?>
							<form method="post" action="/admin/monitor-test/growatt-captcha">
								<?= Csrf::field() ?>
								<button type="submit" class="btn btn-primary"><?= $growattStatus ? 'Reconnect' : 'Connect' ?> Growatt</button>
							</form>
						<?php endif; ?>
						<?php if ($key !== 'growatt' || $growattStatus): ?>
							<form method="post" action="/admin/monitor-test">
								<?= Csrf::field() ?>
								<button type="submit" name="check" value="<?= e($key) ?>" class="btn btn-secondary" title="<?= e(ucfirst($v['check'][1])) ?>">Check <?= e($v['check'][0]) ?></button>
							</form>
						<?php endif; ?>
						<?php if ($key === 'enphase' && $enlightenSession): ?>
							<form method="post" action="/admin/monitor-test/enlighten-forget">
								<?= Csrf::field() ?>
								<button type="submit" class="btn btn-ghost">Forget session</button>
							</form>
						<?php elseif ($key === 'sma' && $smaStatus): ?>
							<form method="post" action="/admin/monitor-test/sma-forget">
								<?= Csrf::field() ?>
								<button type="submit" class="btn btn-ghost">Forget session</button>
							</form>
						<?php elseif ($key === 'growatt' && $growattStatus): ?>
							<form method="post" action="/admin/monitor-test/growatt-forget" data-confirm="Remove the saved Growatt session from this server? Pulls stop until someone connects again.">
								<?= Csrf::field() ?>
								<button type="submit" class="btn btn-ghost">Disconnect</button>
							</form>
						<?php endif; ?>
					</div>
					<p class="muted small mt-sm">The check costs <?= e($v['check'][1]) ?>.<?= $key === 'growatt' ? ' Connect shows Growatt\'s captcha; type it once and the session lasts about 30 days.' : '' ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>
