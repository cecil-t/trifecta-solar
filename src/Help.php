<?php
declare(strict_types=1);

namespace App;

/**
 * In-app help: one short page per section, written in Markdown in help/<slug>.md.
 * A page heading calls help_link('<slug>'); the layout then carries that page's help in a
 * panel that slides in from the right. /help lists every topic, /help/<slug> shows one on its own.
 */
final class Help
{
	/** slug => [title, group, who: all | sales | admin] in index order. */
	public const TOPICS = [
		'getting-around'  => ['Getting around', 'Start here', 'all'],
		'dashboard'       => ['Dashboard', 'Main pages', 'all'],
		'sales'           => ['Sales opportunities', 'Main pages', 'sales'],
		'build-sheet'     => ['Build sheet', 'Main pages', 'sales'],
		'site-layout'     => ['Site layout', 'Main pages', 'sales'],
		'proposal'        => ['Proposal', 'Main pages', 'sales'],
		'projects'        => ['Projects list', 'Main pages', 'all'],
		'project'         => ['Project page', 'Main pages', 'all'],
		'service'         => ['Service', 'Main pages', 'all'],
		'tasks'           => ['Tasks', 'Main pages', 'all'],
		'reports'         => ['Reports', 'Main pages', 'all'],
		'monitor'         => ['Monitor', 'Main pages', 'all'],
		'customers'       => ['Customers', 'Lists', 'all'],
		'municipalities'  => ['Municipalities', 'Lists', 'all'],
		'directory'       => ['Directory', 'Lists', 'all'],
		'catalog'         => ['Sales catalog', 'Lists', 'all'],
		'sales-rates'     => ['Sales rates', 'Lists', 'all'],
		'proposal-terms'  => ['Proposal terms', 'Lists', 'all'],
		'commissions'     => ['Sales commissions', 'Lists', 'sales'],
		'draws'           => ['Draws against commission', 'Lists', 'sales'],
		'account'         => ['My account', 'Lists', 'all'],
		'task-template'   => ['Task template', 'Admin', 'admin'],
		'users'           => ['Users', 'Admin', 'admin'],
		'monitor-apis'    => ['Monitor APIs', 'Admin', 'admin'],
		'proposal-apis'   => ['Proposal APIs', 'Admin', 'admin'],
		'reference-jobs'  => ['Reference jobs', 'Admin', 'admin'],
		'display-links'   => ['Display links', 'Admin', 'admin'],
	];

	/** The topic whose help this page carries (set by help_link while the view renders). */
	public static ?string $current = null;

	public static function exists(string $slug): bool
	{
		return isset(self::TOPICS[$slug]) && is_file(self::file($slug));
	}

	public static function title(string $slug): string
	{
		return self::TOPICS[$slug][0] ?? $slug;
	}

	/** Topics this user would use, grouped for the index. Every page still opens for anyone signed in. */
	public static function grouped(): array
	{
		$out = [];
		foreach (self::TOPICS as $slug => [$title, $group, $who]) {
			if (($who === 'admin' && !Auth::isAdmin()) || ($who === 'sales' && !Presales::canUse() && !Commissions::canUse())) {
				continue;
			}
			$out[$group][$slug] = $title;
		}
		return $out;
	}

	/** $level: the HTML heading level for "## " lines (3 in the panel under its h2, 2 on a help page). */
	public static function html(string $slug, int $level = 3): string
	{
		return self::exists($slug) ? self::markdown((string) file_get_contents(self::file($slug)), $level) : '';
	}

	private static function file(string $slug): string
	{
		return APP_ROOT . '/help/' . $slug . '.md';
	}

	/**
	 * The small part of Markdown the help pages use: "## " headings, "- " bullets, paragraphs,
	 * **bold** and [links](/path) to pages in the app only. Everything is escaped first.
	 */
	public static function markdown(string $md, int $level = 3): string
	{
		$html = '';
		$para = [];
		$list = [];
		$flush = static function () use (&$html, &$para, &$list): void {
			if ($para) {
				$html .= '<p>' . self::inline(implode(' ', $para)) . "</p>\n";
				$para = [];
			}
			if ($list) {
				$html .= "<ul>\n" . implode('', array_map(static fn ($li) => '<li>' . self::inline($li) . "</li>\n", $list)) . "</ul>\n";
				$list = [];
			}
		};
		foreach (preg_split('/\R/', trim($md)) as $line) {
			$t = trim($line);
			if ($t === '') {
				$flush();
			} elseif (str_starts_with($t, '## ')) {
				$flush();
				$html .= "<h$level>" . self::inline(substr($t, 3)) . "</h$level>\n";
			} elseif (str_starts_with($t, '- ')) {
				if ($para) {
					$flush();
				}
				$list[] = substr($t, 2);
			} elseif ($list && preg_match('/^\s{2,}\S/', $line)) {
				$list[count($list) - 1] .= ' ' . $t; // a bullet's wrapped second line
			} else {
				if ($list) {
					$flush();
				}
				$para[] = $t;
			}
		}
		$flush();
		return $html;
	}

	private static function inline(string $s): string
	{
		$s = e($s);
		$s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
		return preg_replace('/\[([^\]]+)\]\((\/[^)\s"<>]*)\)/', '<a href="$2">$1</a>', $s);
	}
}
