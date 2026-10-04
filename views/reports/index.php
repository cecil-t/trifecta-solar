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

<?php
$line = static fn (string $label, int $n, ?string $href, string $class = '') =>
	'<li class="' . $class . '">' . ($href ? '<a href="' . e($href) . '">' . e($label) . '</a>' : '<span>' . e($label) . '</span>') . '<span class="summary-num">' . $n . '</span></li>';
?>
<h2 class="section-title">At a glance</h2>
<div class="summary-grid">
	<section class="card summary-card">
		<h3><a href="/projects?phase=all">Projects</a> <span class="summary-total"><?= $projects['all'] ?></span></h3>
		<ul class="summary-list">
			<?= $line('Active', $projects['active'], '/projects?phase=active') ?>
			<li class="summary-sub"><ul class="summary-list">
				<?= $line('Pre-Install', $projects['pre_install'], '/projects?phase=pre_install') ?>
				<?= $line('Installation', $projects['installation'], '/projects?phase=installation') ?>
				<?= $line('Closeout', $projects['closeout'], '/projects?phase=closeout') ?>
			</ul></li>
			<?= $line('On hold', $projects['on_hold'], '/projects?phase=on_hold') ?>
			<?= $line('Completed', $projects['complete'], '/projects?phase=complete') ?>
			<?= $line('Cancelled', $projects['cancelled'], '/projects?phase=cancelled') ?>
		</ul>
	</section>
	<section class="card summary-card">
		<h3><a href="/service?tab=all">Service tickets</a> <span class="summary-total"><?= $service['all'] ?></span></h3>
		<ul class="summary-list">
			<?= $line('Open', $service['open'], '/service') ?>
			<?= $line('Ready to invoice', $service['to_invoice'], '/service?tab=to_invoice') ?>
			<?= $line('Completed', $service['done'], '/service?tab=done') ?>
		</ul>
	</section>
	<section class="card summary-card">
		<h3><a href="/tasks?tab=all">Tasks</a> <span class="summary-total"><?= $tasks['all'] ?></span></h3>
		<ul class="summary-list">
			<?= $line('Open', $tasks['open'], '/tasks?tab=open') ?>
			<li class="summary-sub"><ul class="summary-list">
				<?= $line('Overdue', $tasks['overdue'], null, $tasks['overdue'] ? 'text-red' : '') ?>
			</ul></li>
			<?= $line('Completed', $tasks['done'], '/tasks?tab=done') ?>
		</ul>
	</section>
</div>

<section class="card mt" id="latest-activity">
	<h2>Latest activity</h2>
	<?= App\View::partial('partials/activity', ['entries' => $recent, 'showProject' => true]) ?>
</section>
