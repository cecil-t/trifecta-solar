<!doctype html>
<html lang="en">
<head>
	<?= App\View::partial('partials/head', ['title' => $title ?? null, 'refresh' => $refresh ?? null]) ?>
	<meta name="robots" content="noindex, nofollow">
	<meta name="referrer" content="no-referrer">
</head>
<body class="kiosk">
<header class="kiosk-bar">
	<img src="<?= asset('assets/img/logo-horizontal.png') ?>" alt="Trifecta Solar" width="143" height="42">
</header>
<main class="page">
	<?= $content ?>
</main>
</body>
</html>
