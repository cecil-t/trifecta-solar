<?php
declare(strict_types=1);

// Under PHP's built-in dev server, let real files (css, images) be served directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Config;
use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\SetupController;
use App\Controllers\UserController;
use App\Migrator;
use App\Router;

if (Config::bool('APP_DEBUG')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

session_name('ts_sess');
session_set_cookie_params(['path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
session_start();

if (Migrator::hasPending()) {
    Migrator::migrate();
}

Auth::init();

$r = new Router();

$r->get('/health', [HealthController::class, 'show'], 'public');

$r->get('/setup', [SetupController::class, 'form'], 'public');
$r->post('/setup', [SetupController::class, 'save'], 'public');

$r->get('/login', [AuthController::class, 'form'], 'public');
$r->post('/login', [AuthController::class, 'login'], 'public');
$r->post('/logout', [AuthController::class, 'logout']);

$r->get('/', [DashboardController::class, 'index']);

$r->get('/account', [AccountController::class, 'show']);
$r->post('/account/password', [AccountController::class, 'password']);
$r->post('/account/devices/{id}/revoke', [AccountController::class, 'revokeDevice']);
$r->post('/account/devices/revoke-others', [AccountController::class, 'revokeOthers']);

$r->get('/users', [UserController::class, 'index'], 'admin');
$r->get('/users/new', [UserController::class, 'create'], 'admin');
$r->post('/users', [UserController::class, 'store'], 'admin');
$r->get('/users/{id}', [UserController::class, 'edit'], 'admin');
$r->post('/users/{id}', [UserController::class, 'update'], 'admin');
$r->post('/users/{id}/password', [UserController::class, 'setPassword'], 'admin');
$r->post('/users/{id}/revoke-devices', [UserController::class, 'revokeDevices'], 'admin');

try {
    $r->dispatch($_SERVER['REQUEST_METHOD'], current_path());
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    echo Config::bool('APP_DEBUG')
        ? '<pre>' . e((string) $e) . '</pre>'
        : 'Something went wrong. The error has been logged.';
}
