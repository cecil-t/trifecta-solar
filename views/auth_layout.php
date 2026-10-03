<!doctype html>
<html lang="en">
<head>
	<?= App\View::partial('partials/head', ['title' => $title ?? null]) ?>
</head>
<body class="auth-body">
<main class="auth-wrap">
	<div class="auth-card">
		<img class="auth-logo" src="<?= asset('assets/img/logo-stacked.png') ?>" alt="Trifecta Solar" width="170" height="137">
		<?= App\View::partial('partials/flashes') ?>
		<?= $content ?>
	</div>
</main>
</body>
</html>
