<meta charset="utf-8">
<?php if (!empty($refresh)): ?><meta http-equiv="refresh" content="<?= (int) $refresh ?>">
<?php endif; ?>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= isset($title) ? e($title) . ' - ' : '' ?>Trifecta Ops</title>
<meta name="theme-color" content="#025586">
<link rel="manifest" href="/manifest.webmanifest?v=<?= App\Controllers\ManifestController::version() ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/img/favicon-32.png') ?>">
<link rel="apple-touch-icon" href="<?= asset('assets/img/apple-touch-icon.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Trifecta">
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
