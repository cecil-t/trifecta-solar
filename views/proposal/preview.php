<?php
/**
 * The PDF preview: the salesperson's toolbar and "Before sending" list, then the PDF as the
 * customer gets it (the browser's PDF viewer).
 *
 * @var string $base  the build sheet's address
 * Plus everything from App\Proposal::data().
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Proposal <?= e($quote) ?> - <?= e($customerName) ?></title>
	<link rel="stylesheet" href="<?= asset('assets/css/proposal.css') ?>">
</head>
<body class="proposal is-preview">
	<div class="toolbar">
		<a href="<?= e($base) ?>" class="tb-back">&larr; Build sheet</a>
		<span class="tb-title">Proposal <?= e($quote) ?></span>
		<a href="<?= e($base) ?>/proposal/pdf" class="tb-btn">Download PDF</a>
	</div>
	<?php if ($warnings): ?>
		<div class="tb-warn" role="status"><strong>Before sending:</strong><ul><?php foreach ($warnings as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div>
	<?php endif; ?>
	<iframe class="pdf-frame" src="<?= e($base) ?>/proposal/pdf?inline=1" title="Proposal <?= e($quote) ?> (PDF)"></iframe>
	<p class="pdf-fallback">The PDF takes a few seconds to make. If it doesn't show here, <a href="<?= e($base) ?>/proposal/pdf?inline=1">open it in its own tab</a>.</p>
</body>
</html>
