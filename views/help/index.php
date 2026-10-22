<?php
/** @var array $groups  group => [slug => title] */
?>
<div class="page-head">
	<div>
		<h1>Help</h1>
		<p class="muted">What each page is for and how to use it. The <span class="help-btn help-btn-static" aria-hidden="true">?</span> beside a page's title opens its help right there.</p>
	</div>
</div>
<div class="help-index">
	<?php foreach ($groups as $group => $topics): ?>
		<section class="card">
			<h2><?= e($group) ?></h2>
			<ul class="help-topics">
				<?php foreach ($topics as $slug => $title): ?>
					<li><a href="/help/<?= e($slug) ?>"><?= e($title) ?></a></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endforeach; ?>
</div>
