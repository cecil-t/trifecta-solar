<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Help;
use App\View;

/** Help pages: the topic list and each topic on its own (the panel beside a page shows the same text). */
final class HelpController
{
	public function index(): void
	{
		View::render('help/index', ['title' => 'Help', 'groups' => Help::grouped()]);
	}

	public function show(): void
	{
		$slug = basename(current_path());
		if (!Help::exists($slug)) {
			View::render('errors/404', ['title' => 'Not found'], 'layout', 404);
			return;
		}
		View::render('help/show', ['title' => Help::title($slug) . ' help', 'slug' => $slug, 'body' => Help::html($slug, 2)]);
	}
}
