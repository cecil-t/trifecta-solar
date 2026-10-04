<?php
declare(strict_types=1);

namespace App;

/**
 * Minimal router. Paths may contain {name} placeholders (digits only).
 * Access levels: 'public', 'auth' (any logged-in user), 'admin'.
 */
final class Router
{
	private array $routes = [];

	public function get(string $path, callable|array $handler, string $access = 'auth'): void
	{
		$this->add('GET', $path, $handler, $access);
	}

	public function post(string $path, callable|array $handler, string $access = 'auth'): void
	{
		$this->add('POST', $path, $handler, $access);
	}

	private function add(string $method, string $path, callable|array $handler, string $access): void
	{
		$regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $path) . '$#';
		$this->routes[] = compact('method', 'regex', 'handler', 'access');
	}

	public function dispatch(string $method, string $path): void
	{
		$path = rtrim($path, '/') ?: '/';
		$pathMatched = false;

		foreach ($this->routes as $route) {
			if (!preg_match($route['regex'], $path, $m)) {
				continue;
			}
			$pathMatched = true;
			if ($route['method'] !== $method) {
				continue;
			}

			if ($route['access'] !== 'public' && !Auth::user()) {
				if ($method === 'GET') {
					$_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
				}
				redirect('/login');
			}
			if ($route['access'] === 'admin' && !Auth::isAdmin()) {
				View::render('errors/403', ['title' => 'Not allowed'], 'layout', 403);
				return;
			}
			if ($method === 'POST' && !Csrf::valid()) {
				flash('error', 'Your form expired. Please try again.');
				redirect($path);
			}

			$params = array_map('intval', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
			$handler = $route['handler'];
			if (is_array($handler)) {
				$handler = [new $handler[0](), $handler[1]];
			}
			$handler(...$params);
			return;
		}

		if ($pathMatched) {
			http_response_code(405);
			echo 'Method not allowed';
			return;
		}
		View::render('errors/404', ['title' => 'Not found'], Auth::user() ? 'layout' : 'auth_layout', 404);
	}
}
