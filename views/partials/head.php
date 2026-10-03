<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= isset($title) ? e($title) . ' - ' : '' ?>Trifecta Tracker</title>
<meta name="theme-color" content="#025586">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" type="image/png" sizes="32x32" href="<?= asset('assets/img/favicon-32.png') ?>">
<link rel="apple-touch-icon" href="<?= asset('assets/img/apple-touch-icon.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Trifecta">
<link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
<script>
    if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
        navigator.serviceWorker.register('/sw.js');
    }
</script>
