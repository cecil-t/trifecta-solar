<?php
use App\Auth;
use App\Csrf;

$user = Auth::user();
$path = current_path();
$nav = [
    ['/', 'Dashboard', true],
    ['/projects', 'Projects', false],
    ['/service', 'Service', false],
    ['/actions', 'Action Items', false],
    ['/reports', 'Reports', false],
];
$isActive = static fn (string $href) => $href === '/' ? $path === '/' : str_starts_with($path, $href);
?>
<!doctype html>
<html lang="en">
<head>
    <?= App\View::partial('partials/head', ['title' => $title ?? null]) ?>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a href="/" class="brand" aria-label="Trifecta Solar home">
            <img src="<?= asset('assets/img/logo-horizontal.png') ?>" alt="Trifecta Solar" width="143" height="42">
        </a>

        <nav class="mainnav" aria-label="Main">
            <?php foreach ($nav as [$href, $label, $ready]): ?>
                <?php if ($ready): ?>
                    <a href="<?= $href ?>" class="<?= $isActive($href) ? 'active' : '' ?>"><?= e($label) ?></a>
                <?php else: ?>
                    <span class="soon" title="Coming soon"><?= e($label) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (Auth::isAdmin()): ?>
                <a href="/users" class="<?= $isActive('/users') ? 'active' : '' ?>">Users</a>
            <?php endif; ?>
        </nav>

        <details class="usermenu">
            <summary>
                <span class="avatar"><?= e($user['initials'] ?: mb_substr($user['name'], 0, 1)) ?></span>
                <span class="usermenu-name"><?= e($user['name']) ?></span>
            </summary>
            <div class="usermenu-panel">
                <a href="/account">My account</a>
                <form method="post" action="/logout">
                    <?= Csrf::field() ?>
                    <button type="submit" class="linklike">Sign out</button>
                </form>
            </div>
        </details>
    </div>
</header>

<main class="page">
    <?= App\View::partial('partials/flashes') ?>
    <?= $content ?>
</main>

<footer class="footer">
    Trifecta Solar Tracker
</footer>
</body>
</html>
