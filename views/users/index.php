<div class="page-head">
    <div>
        <h1>Users</h1>
        <p class="muted">Staff logins. Admins can manage users and the shared directories.</p>
    </div>
    <a href="/users/new" class="btn btn-primary">Add user</a>
</div>

<div class="card card-flush">
    <table class="table">
        <thead>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Devices</th><th>Last active</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr class="<?= $u['is_active'] ? '' : 'row-muted' ?>">
                <td><a href="/users/<?= (int) $u['id'] ?>"><span class="avatar avatar-sm"><?= e($u['initials'] ?: mb_substr($u['name'], 0, 1)) ?></span> <?= e($u['name']) ?></a></td>
                <td><?= e($u['email']) ?></td>
                <td><?= $u['is_admin'] ? '<span class="chip chip-blue">Admin</span>' : 'User' ?></td>
                <td>
                    <?php if (!$u['is_active']): ?><span class="chip">Inactive</span>
                    <?php elseif (!$u['password_hash']): ?><span class="chip chip-orange">No password yet</span>
                    <?php else: ?><span class="chip chip-green">Active</span><?php endif; ?>
                </td>
                <td><?= (int) $u['device_count'] ?></td>
                <td><?= e($u['last_seen_at'] ? time_ago($u['last_seen_at']) : 'Never') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
