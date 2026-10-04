<?php use App\Controllers\ReportController; ?>
<div class="page-head">
	<div>
		<h1>Reports</h1>
		<p class="muted small">Each report has its own date range and prints on its own.</p>
	</div>
</div>
<p class="report-phone-note">Reports are best viewed on a desktop browser. On a phone, wide tables may need sideways scrolling.</p>
<div class="grid-cards">
	<?php foreach (ReportController::REPORTS as $key => [$label, $desc]): ?>
		<a class="card card-module report-card" href="/reports/<?= $key ?>">
			<h2><?= e($label) ?></h2>
			<p><?= e($desc) ?></p>
		</a>
	<?php endforeach; ?>
</div>
