<?php
/**
 * @var ?array  $version
 * @var string  $repo
 * @var array   $host
 * @var array   $server  from App\ServerInfo, plus disk space
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
$span = static function (float $secs): string {
	$d = intdiv((int) $secs, 86400);
	$h = intdiv((int) $secs % 86400, 3600);
	$m = intdiv((int) $secs % 3600, 60);
	$plural = static fn (int $n, string $w) => $n . ' ' . $w . ($n === 1 ? '' : 's');
	return $d ? $plural($d, 'day') . ', ' . $plural($h, 'hour') : ($h ? $plural($h, 'hour') . ', ' . $plural($m, 'minute') : $plural($m, 'minute'));
};
$cores = (int) ($server['cpu']['cores'] ?? 0);
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
			<?php if ($host['apache']): ?>
				<dt>Web server</dt><dd>Apache <?= e($host['apache'][0]) ?> <span class="muted small">(package <?= e($host['apache'][1]) ?>)</span></dd>
			<?php elseif ($host['server']): ?>
				<dt>Web server</dt><dd><?= e($host['server']) ?></dd>
			<?php endif; ?>
			<dt>PHP</dt><dd><?= e($host['php']) ?></dd>
			<dt>Database</dt><dd>SQLite <?= e($host['sqlite']) ?>, <?= e($host['journal']) ?> mode, <?= e($bytes((float) $host['db_bytes'])) ?></dd>
		</dl>
	</section>

	<section class="card">
		<h2>Server</h2>
		<dl class="kv">
			<?php if ($server['model']): ?><dt>Model</dt><dd><?= e($server['model']) ?></dd><?php endif; ?>
			<?php if ($server['cpu']['name'] ?? null): ?>
				<dt>CPU</dt><dd><?= e($server['cpu']['name']) ?><?= $cores ? ' <span class="muted small">(' . $cores . ' core' . ($cores === 1 ? '' : 's') . ')</span>' : '' ?></dd>
			<?php endif; ?>
			<?php if ($server['temp_c'] !== null): ?>
				<dt>CPU temperature</dt><dd><?= e(number_format($server['temp_c'], 0)) ?> &deg;C <span class="muted small">(<?= e(number_format($server['temp_c'] * 9 / 5 + 32, 0)) ?> &deg;F)</span></dd>
			<?php endif; ?>
			<?php if ($server['load']): ?>
				<dt>Load average</dt>
				<dd><?= e(implode(', ', array_map(static fn ($l) => number_format($l, 2), $server['load']))) ?> <span class="muted small">(1, 5 and 15 min<?= $cores ? '; ' . $cores . ' = all cores busy' : '' ?>)</span></dd>
			<?php endif; ?>
			<?php if ($server['memory']): ?>
				<dt>Memory</dt>
				<dd><?= e($bytes((float) $server['memory']['total'])) ?><?= $server['memory']['available'] !== null ? ' <span class="muted small">(' . e($bytes((float) $server['memory']['available'])) . ' available)</span>' : '' ?></dd>
			<?php endif; ?>
			<?php if ($server['container_memory']): ?>
				<dt>This container</dt>
				<dd><?= e($bytes((float) $server['container_memory']['used'])) ?> of memory <span class="muted small">(<?= $server['container_memory']['limit'] ? 'limit ' . e($bytes((float) $server['container_memory']['limit'])) : 'no limit' ?>)</span><?= $server['processes'] ? ', ' . (int) $server['processes'] . ' processes' : '' ?></dd>
			<?php endif; ?>
			<?php if ($server['uptime'] !== null): ?>
				<dt>Uptime</dt><dd><?= e($span($server['uptime'])) ?> <span class="muted small">(since <?= e($when((int) round(time() - $server['uptime']))) ?>)</span></dd>
			<?php endif; ?>
			<?php if ($server['container_started']): ?>
				<dt>Container started</dt><dd><?= e($when($server['container_started'])) ?> <span class="muted small">(<?= e($span(time() - $server['container_started'])) ?> ago)</span></dd>
			<?php endif; ?>
			<dt>Kernel</dt><dd><span class="literal"><?= e($server['kernel']) ?></span></dd>
			<?php if ($server['disk_free']): ?>
				<dt>Free space</dt><dd><?= e($bytes((float) $server['disk_free'])) ?><?= $server['disk_total'] ? ' of ' . e($bytes((float) $server['disk_total'])) : '' ?> <span class="muted small">(database volume)</span></dd>
			<?php endif; ?>
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
