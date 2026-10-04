<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'App\\')) {
		return;
	}
	$file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
	if (is_file($file)) {
		require $file;
	}
});

require APP_ROOT . '/src/helpers.php';

App\Config::load(APP_ROOT . '/.env');
date_default_timezone_set(App\Config::get('APP_TIMEZONE', 'America/New_York'));
