<?php
/**
 * Test document for Lists > Proposal APIs > Test PDF: the logo, page footer with page numbers, a table, an SVG
 * chart, a two-column grid and a page break. Made-up numbers.
 *
 * @var string $at when it was made
 */
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$kwh = [620, 780, 1090, 1260, 1420, 1480, 1510, 1390, 1180, 950, 650, 540];
$max = max($kwh);
$w = 760;
$h = 150;
$bw = $w / 12;
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>PDF test</title>
	<link rel="stylesheet" href="assets/css/pdf.css">
</head>
<body>
	<div class="pdf-head">
		<img src="assets/img/logo-horizontal.png" alt="Trifecta Solar">
		<div class="pdf-muted">Made <?= e($at) ?></div>
	</div>
	<h1>PDF test</h1>
	<p class="pdf-muted">A test page for the PDF tool (WeasyPrint). The numbers are made up.</p>

	<h2>Table</h2>
	<table class="pdf-table">
		<thead><tr><th>Month</th><th class="num">kWh</th><th class="num">Share</th></tr></thead>
		<tbody>
		<?php foreach ($months as $i => $m): ?>
			<tr><td><?= e($m) ?></td><td class="num"><?= e(number_format($kwh[$i])) ?></td><td class="num"><?= e(number_format($kwh[$i] / array_sum($kwh) * 100, 1)) ?>%</td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2>Chart (SVG drawn on the server)</h2>
	<svg class="pdf-chart" viewBox="0 0 <?= $w ?> <?= $h + 20 ?>" xmlns="http://www.w3.org/2000/svg">
		<?php foreach ($kwh as $i => $v): $bh = $v / $max * $h; ?>
			<rect x="<?= round($i * $bw + 4, 1) ?>" y="<?= round($h - $bh, 1) ?>" width="<?= round($bw - 8, 1) ?>" height="<?= round($bh, 1) ?>" fill="#69b545"/>
			<text x="<?= round($i * $bw + $bw / 2, 1) ?>" y="<?= $h + 14 ?>" font-size="13" text-anchor="middle" fill="#5d6a72"><?= e($months[$i]) ?></text>
		<?php endforeach; ?>
	</svg>

	<div class="pdf-page-break"></div>
	<h2>Two columns (CSS grid)</h2>
	<div class="pdf-grid">
		<div class="pdf-box"><strong>Left box.</strong> Text wraps inside the box and the boxes sit side by side.</div>
		<div class="pdf-box"><strong>Right box.</strong> The footer below shows the page number and the total pages.</div>
	</div>
</body>
</html>
