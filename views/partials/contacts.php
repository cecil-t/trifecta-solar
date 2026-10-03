<?php
/**
 * @var array  $contacts
 * @var string $ownerType organization|municipality
 * @var int    $ownerId
 * @var bool   $canManage
 */
use App\Csrf;
?>
<ul class="contacts">
	<?php if (!$contacts): ?><li class="muted">No contacts yet.</li><?php endif; ?>
	<?php foreach ($contacts as $c): ?>
		<li id="contact-<?= (int) $c['id'] ?>" class="<?= $c['is_active'] ? '' : 'row-muted' ?>">
			<div class="contact-main">
				<strong><?= e($c['name']) ?></strong>
				<?php if ($c['role']): ?><span class="muted">&middot; <?= e($c['role']) ?></span><?php endif; ?>
				<?php if ($c['is_primary']): ?><span class="chip chip-blue">Primary</span><?php endif; ?>
				<?php if (!$c['is_active']): ?><span class="chip">Inactive</span><?php endif; ?>
				<div class="small">
					<?php if ($c['phone']): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a><?php endif; ?>
					<?php if ($c['phone'] && $c['email']): ?> &middot; <?php endif; ?>
					<?php if ($c['email']): ?><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a><?php endif; ?>
				</div>
				<?php if ($c['notes']): ?><div class="muted small"><?= e($c['notes']) ?></div><?php endif; ?>
			</div>
			<?php if ($canManage): ?>
				<details class="contact-edit">
					<summary class="btn btn-ghost btn-small">Edit</summary>
					<form method="post" action="/contacts/<?= (int) $c['id'] ?>" class="grid-form">
						<?= Csrf::field() ?>
						<label>Name <input type="text" name="name" value="<?= e($c['name']) ?>" required></label>
						<label>Role <input type="text" name="role" value="<?= e($c['role']) ?>"></label>
						<label>Phone <input type="text" name="phone" value="<?= e($c['phone']) ?>"></label>
						<label>Email <input type="email" name="email" value="<?= e($c['email']) ?>"></label>
						<label class="span-2">Notes <input type="text" name="notes" value="<?= e($c['notes']) ?>"></label>
						<label class="check"><input type="checkbox" name="is_primary" value="1" <?= $c['is_primary'] ? 'checked' : '' ?>> Primary</label>
						<input type="hidden" name="is_active" value="0">
						<label class="check"><input type="checkbox" name="is_active" value="1" <?= $c['is_active'] ? 'checked' : '' ?>> Active</label>
						<div><button class="btn btn-primary btn-small">Save contact</button></div>
					</form>
				</details>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
<?php if ($canManage): ?>
	<details class="add-contact">
		<summary class="btn btn-secondary btn-small">+ Add contact</summary>
		<form method="post" action="/contacts" class="grid-form mt-sm">
			<?= Csrf::field() ?>
			<input type="hidden" name="owner_type" value="<?= e($ownerType) ?>">
			<input type="hidden" name="owner_id" value="<?= (int) $ownerId ?>">
			<label>Name <input type="text" name="name" required></label>
			<label>Role <input type="text" name="role" placeholder="e.g. Owner, Inspector, Zoning officer"></label>
			<label>Phone <input type="text" name="phone"></label>
			<label>Email <input type="email" name="email"></label>
			<label class="span-2">Notes <input type="text" name="notes"></label>
			<label class="check"><input type="checkbox" name="is_primary" value="1"> Primary</label>
			<div><button class="btn btn-primary btn-small">Add contact</button></div>
		</form>
	</details>
<?php endif; ?>
