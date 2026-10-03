<?php foreach (take_flashes() as [$type, $message]): ?>
    <div class="flash flash-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($message) ?></div>
<?php endforeach; ?>
