<?php
/**
 * @var string $slug
 * @var string $body  rendered from help/<slug>.md
 */
?>
<div class="page-head">
	<div>
		<a href="/help" class="back">&larr; All help topics</a>
		<div class="page-kicker">Help</div>
		<h1><?= e(App\Help::title($slug)) ?></h1>
	</div>
</div>
<article class="card help-text help-page"><?= $body ?></article>
