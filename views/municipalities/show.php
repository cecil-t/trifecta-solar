<?php
use App\Auth;
use App\Csrf;
use App\Municipalities;

$admin = Auth::isAdmin();
$dis = $admin ? '' : 'disabled';
?>
<div class="page-head">
    <div>
        <a href="/municipalities" class="back">&larr; Municipalities</a>
        <h1><?= e($m['name']) ?></h1>
        <p class="muted"><?= e($m['county'] . ' County, ' . $m['state_code']) ?></p>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Usual providers</h2>
        <p class="hint">New projects here start with these. <?= $admin ? '' : 'Only admins can change them.' ?></p>
        <form method="post" action="/municipalities/<?= (int) $m['id'] ?>" class="grid-form">
            <?= Csrf::field() ?>
            <label class="span-2">Name <input type="text" name="name" value="<?= e($m['name']) ?>" required <?= $dis ?>></label>
            <label>County
                <select name="county_id" <?= $dis ?>>
                    <?php foreach ($counties as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $m['county_id'] ? 'selected' : '' ?>><?= e($c['name'] . ' (' . $c['state_code'] . ')') ?></option><?php endforeach; ?>
                </select>
            </label>
            <?php foreach (Municipalities::SLOTS as $key => $slot): ?>
                <div class="provider span-all">
                    <label><?= e($slot['label']) ?> by
                        <select name="<?= $slot['by'] ?>" data-reveal="third_party" data-target="#<?= $key ?>-org" <?= $dis ?>>
                            <?php foreach (Municipalities::BY as $k => $l): ?><option value="<?= $k ?>" <?= (string) $m[$slot['by']] === (string) $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label id="<?= $key ?>-org" hidden>Third party
                        <select name="<?= $slot['org'] ?>" <?= $dis ?>>
                            <option value="">Choose</option>
                            <?php foreach ($agencies as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === (int) $m[$slot['org']] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                </div>
            <?php endforeach; ?>
            <label class="span-all">Notes <textarea name="notes" rows="2" <?= $dis ?> placeholder="e.g. Rolls up to county for building permits; zoning hearings 2nd Tuesday"><?= e($m['notes']) ?></textarea></label>
            <?php if ($admin): ?><div><button class="btn btn-primary">Save</button></div><?php endif; ?>
        </form>
    </section>

    <section class="card">
        <h2>Contacts</h2>
        <?= App\View::partial('partials/contacts', ['contacts' => $contacts, 'ownerType' => 'municipality', 'ownerId' => (int) $m['id'], 'canManage' => $admin]) ?>
        <h2 class="mt">Projects</h2>
        <?php if (!$projects): ?><p class="muted">None yet.</p><?php endif; ?>
        <ul class="plain">
            <?php foreach ($projects as $pr): ?><li><a href="/projects/<?= (int) $pr['id'] ?>"><strong><?= e($pr['project_number']) ?></strong> <?= e($pr['name']) ?></a></li><?php endforeach; ?>
        </ul>
    </section>

    <section class="card span-2">
        <h2>Activity</h2>
        <?= App\View::partial('partials/activity', ['entries' => $activity]) ?>
    </section>
</div>
<script src="<?= asset('assets/js/forms.js') ?>"></script>
