<?php
/**
 * @var ?array  $version
 * @var string  $repo
 * @var array   $host
 * @var array   $stats  (not $data: View::partial keeps its own $data)
 */
$bytes = static function (?float $n): string {
	if (!$n) {
		return '';
	}
	foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $unit) {
		if ($n < 1024 || $unit === 'TB') {
			return ($i ? number_format($n, 1) : (int) $n) . ' ' . $unit;
		}
		$n /= 1024;
	}
	return '';
};
$when = static fn (?int $ts): string => $ts ? date('m/d/Y g:i A', $ts) : '';
?>
<div class="page-head">
	<div>
		<h1>About</h1>
		<p class="muted">Trifecta Solar Ops Tracker: projects, service and tasks for Trifecta Solar, in place of the old tracker spreadsheets.</p>
	</div>
</div>

<div class="grid-2">
	<section class="card">
		<h2>The app</h2>
		<dl class="kv">
			<dt>Developed by</dt><dd>Greg Hassler and Claude.ai</dd>
			<dt>Repository</dt><dd><a href="<?= e($repo) ?>" rel="noopener" target="_blank">github.com/cecil-t/trifecta-solar</a> <span class="muted small">(private)</span></dd>
			<?php if ($version): ?>
				<dt>Code version</dt>
				<dd>
					<a href="<?= e($repo . '/commit/' . $version['hash']) ?>" rel="noopener" target="_blank"><code><?= e($version['short']) ?></code></a><?= $version['branch'] ? ' on ' . e($version['branch']) : '' ?><?= $version['committed'] ? ', dated ' . e($when($version['committed'])) : '' ?>
					<?php if ($version['subject']): ?><div class="muted small"><?= e($version['subject']) ?></div><?php endif; ?>
				</dd>
				<?php if ($version['updated']): ?><dt>Updated on server</dt><dd><?= e($when($version['updated'])) ?></dd><?php endif; ?>
			<?php else: ?>
				<dt>Code version</dt><dd class="muted">Not available (no .git folder on this server)</dd>
			<?php endif; ?>
			<dt>Built with</dt><dd>PHP, SQLite and Apache in Docker, with no framework and no JavaScript build step</dd>
			<dt>Weather</dt><dd><a href="https://open-meteo.com/" rel="noopener" target="_blank">Open-Meteo</a> <span class="muted small">(CC BY 4.0)</span></dd>
		</dl>
	</section>

	<section class="card">
		<h2>Hosting</h2>
		<dl class="kv">
			<?php if ($host['url'] !== 'http://' && $host['url'] !== 'https://'): ?><dt>Address</dt><dd><?= e($host['url']) ?></dd><?php endif; ?>
			<dt>Runs in</dt><dd><?= $host['docker'] ? 'Docker container' : 'Directly on the server' ?><?= $host['hostname'] ? ' <span class="muted small">(' . e($host['hostname']) . ')</span>' : '' ?></dd>
			<?php if ($host['os']): ?><dt><?= $host['docker'] ? 'Container OS' : 'OS' ?></dt><dd><?= e($host['os']) ?></dd><?php endif; ?>
			<dt>Kernel</dt><dd><span class="literal"><?= e($host['kernel']) ?></span><?= $host['docker'] ? ' <span class="muted small">(the host\'s)</span>' : '' ?></dd>
			<?php if ($host['apache']): ?>
				<dt>Web server</dt><dd>Apache <?= e($host['apache'][0]) ?> <span class="muted small">(package <?= e($host['apache'][1]) ?>)</span></dd>
			<?php elseif ($host['server']): ?>
				<dt>Web server</dt><dd><?= e($host['server']) ?></dd>
			<?php endif; ?>
			<dt>PHP</dt><dd><?= e($host['php']) ?></dd>
			<dt>Database</dt><dd>SQLite <?= e($host['sqlite']) ?>, <?= e($host['journal']) ?> mode, <?= e($bytes((float) $host['db_bytes'])) ?></dd>
			<?php if ($host['disk_free']): ?><dt>Free space</dt><dd><?= e($bytes((float) $host['disk_free'])) ?> <span class="muted small">(database volume)</span></dd><?php endif; ?>
			<dt>Server time</dt><dd><?= e($when($host['server_time'])) ?> <span class="muted small">(<?= e($host['timezone']) ?>)</span></dd>
		</dl>
	</section>

	<section class="card">
		<h2>Data</h2>
		<dl class="kv">
			<dt>Projects</dt><dd><?= number_format($stats['projects']) ?></dd>
			<dt>Service tickets</dt><dd><?= number_format($stats['service']) ?></dd>
			<dt>Tasks</dt><dd><?= number_format($stats['tasks']) ?></dd>
			<dt>Customers</dt><dd><?= number_format($stats['customers']) ?></dd>
			<dt>Users</dt><dd><?= number_format($stats['users']) ?></dd>
			<dt>Schema</dt><dd><?= $stats['schema'] ? e($stats['schema']) . ' <span class="muted small">(' . (int) $stats['migrations'] . ' migrations)</span>' : '' ?></dd>
			<dt>Last backup</dt>
			<dd>
				<?php if ($stats['last_backup']): ?>
					<?= e($when($stats['last_backup']['time'])) ?>, <?= e($bytes((float) $stats['last_backup']['bytes'])) ?> <span class="muted small">(<?= (int) $stats['backup_count'] ?> kept, daily at 4:00 AM)</span>
				<?php else: ?>
					<span class="muted">None found</span>
				<?php endif; ?>
			</dd>
		</dl>
	</section>
</div>
