<?php
use App\Csrf;

/**
 * @var array  $keys  display_keys rows plus created_by_name, active first
 * @var string $base  this site's address
 */
?>
<div class="page-head">
	<div>
		<h1>Display links<?= help_link('display-links') ?></h1>
		<p class="muted">Links for office screens. Each one opens the Monitor page without the menus and without signing in, and nothing else in the tracker. Anyone with a link can see that page, so revoke a link when its screen is retired or the link gets out.</p>
	</div>
</div>

<section class="card">
	<form method="post" action="/admin/display-links" class="display-key-new">
		<?= Csrf::field() ?>
		<label>Name for a new link
			<input type="text" name="name" maxlength="80" placeholder="Office TV" required>
		</label>
		<button type="submit" class="btn btn-primary">Create link</button>
	</form>
</section>

<?php if ($keys): ?>
	<div class="card card-flush mt scroll-x-phone">
		<table class="table">
			<thead>
			<tr><th>Name</th><th>Link</th><th>Created</th><th>Last opened</th><th></th></tr>
			</thead>
			<tbody>
			<?php foreach ($keys as $k): $url = $base . '/display?key=' . $k['token']; $revoked = $k['revoked_at'] !== null; ?>
				<tr class="<?= $revoked ? 'row-muted' : '' ?>">
					<td>
						<?php if ($revoked): ?>
							<?= e($k['name']) ?> <span class="chip">Revoked</span>
						<?php else: ?>
							<form method="post" action="/admin/display-links/<?= (int) $k['id'] ?>" class="display-key-rename">
								<?= Csrf::field() ?>
								<input type="text" name="name" value="<?= e($k['name']) ?>" maxlength="80" required aria-label="Name">
								<button type="submit" class="btn btn-ghost btn-small">Rename</button>
							</form>
						<?php endif; ?>
					</td>
					<td>
						<?php if ($revoked): ?>
							<span class="muted small">Revoked <?= e(fmt_dt((string) $k['revoked_at'])) ?></span>
						<?php else: ?>
							<div class="display-key-link">
								<input type="text" class="display-key-url" value="<?= e($url) ?>" readonly aria-label="Link for <?= e($k['name']) ?>">
								<a href="<?= e($url) ?>" target="_blank" rel="noopener" class="small">Open</a>
							</div>
						<?php endif; ?>
					</td>
					<td><?= e(fmt_dt((string) $k['created_at'], 'm/d/Y')) ?><?= $k['created_by_name'] ? '<div class="muted small">' . e($k['created_by_name']) . '</div>' : '' ?></td>
					<td><?= $k['last_used_at'] ? e(time_ago((string) $k['last_used_at'])) . ($k['last_used_ip'] ? '<div class="muted small">' . e($k['last_used_ip']) . '</div>' : '') : '<span class="muted">Never</span>' ?></td>
					<td>
						<?php if (!$revoked): ?>
							<form method="post" action="/admin/display-links/<?= (int) $k['id'] ?>/revoke" data-confirm="Revoke &quot;<?= e($k['name']) ?>&quot;? Its screen stops updating at the next reload, and the link cannot be turned back on.">
								<?= Csrf::field() ?>
								<button type="submit" class="btn btn-ghost btn-small">Revoke</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
