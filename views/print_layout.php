<!doctype html>
<html lang="en">
<head>
	<?= App\View::partial('partials/head', ['title' => $title ?? null]) ?>
	<meta name="robots" content="noindex, nofollow">
</head>
<body class="print-page">
<main class="sheet">
	<?= $content ?>
</main>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
