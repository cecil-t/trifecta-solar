<div class="page-head">
    <div><h1>Customers</h1><p class="muted">Anyone can add or edit customers and their contacts.</p></div>
    <a href="/organizations/new?type=customer" class="btn btn-primary">Add customer</a>
</div>
<form class="filters" method="get" action="/customers">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search customers">
    <button class="btn btn-secondary btn-small">Search</button>
</form>
<div class="card card-flush">
    <table class="table">
        <thead><tr><th>Customer</th><th>Phone</th><th>Email</th><th class="num">Contacts</th><th class="num">Projects</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="empty">No customers yet. They're also added from the New project form.</td></tr><?php endif; ?>
        <?php foreach ($rows as $o): ?>
            <tr class="<?= $o['is_active'] ? '' : 'row-muted' ?>">
                <td><a href="/organizations/<?= (int) $o['id'] ?>"><?= e($o['name']) ?></a></td>
                <td><?= e($o['phone']) ?></td>
                <td><?= e($o['email']) ?></td>
                <td class="num"><?= (int) $o['contact_count'] ?></td>
                <td class="num"><?= (int) $o['project_count'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
