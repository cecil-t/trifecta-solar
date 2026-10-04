<?php
declare(strict_types=1);

namespace App;

final class View
{
	/** Render views/$template.php inside views/$layout.php. */
	public static function render(string $template, array $data = [], string $layout = 'layout', int $status = 200): void
	{
		http_response_code($status);
		$content = self::partial($template, $data);
		echo HtmlIndent::tidy(self::partial($layout, $data + ['content' => $content]));
	}

	public static function partial(string $template, array $data = []): string
	{
		extract($data, EXTR_SKIP);
		ob_start();
		require APP_ROOT . '/views/' . $template . '.php';
		return (string) ob_get_clean();
	}
}
