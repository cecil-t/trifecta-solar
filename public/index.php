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
use App\Controllers\AboutController;
use App\Controllers\AccountController;
use App\Controllers\ActivityController;
use App\Controllers\AuthController;
use App\Controllers\ContactController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\MunicipalityController;
use App\Controllers\OrganizationController;
use App\Controllers\ProjectController;
use App\Controllers\SetupController;
use App\Controllers\TemplateController;
use App\Controllers\UserController;
use App\Migrator;
use App\Router;
use App\Controllers\ServiceController;
use App\Controllers\ReportController;
use App\Controllers\TodoController;

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
$r->get('/about', [AboutController::class, 'show']);
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

// Projects
$r->get('/projects', [ProjectController::class, 'index']);
$r->get('/projects/new', [ProjectController::class, 'create']);
$r->post('/projects', [ProjectController::class, 'store']);
$r->get('/projects/{id}', [ProjectController::class, 'show']);
$r->get('/projects/{id}/edit', [ProjectController::class, 'edit']);
$r->post('/projects/{id}', [ProjectController::class, 'update']);
$r->post('/projects/{id}/comments', [ProjectController::class, 'comment']);
$r->post('/projects/{id}/tasks', [ProjectController::class, 'addTask']);
$r->post('/projects/{id}/tasks/from-template', [ProjectController::class, 'addFromTemplate']);
$r->post('/projects/{id}/tasks/{taskId}', [ProjectController::class, 'updateTask']);
$r->post('/projects/{id}/tasks/{taskId}/duplicate', [ProjectController::class, 'duplicateTask']);
$r->post('/projects/{id}/tasks/{taskId}/delete', [ProjectController::class, 'deleteTask']);

// Comments on any log
$r->get('/service', [ServiceController::class, 'index']);
$r->get('/service/new', [ServiceController::class, 'create']);
$r->post('/service', [ServiceController::class, 'store']);
$r->get('/service/{id}', [ServiceController::class, 'show']);
$r->get('/service/{id}/edit', [ServiceController::class, 'edit']);
$r->post('/service/{id}', [ServiceController::class, 'update']);
$r->post('/service/{id}/quick', [ServiceController::class, 'quick']);
$r->post('/service/{id}/comments', [ServiceController::class, 'comment']);
$r->post('/service/{id}/visits', [ServiceController::class, 'addVisit']);
$r->post('/service/{id}/visits/{visitId}', [ServiceController::class, 'updateVisit']);
$r->post('/service/{id}/visits/{visitId}/delete', [ServiceController::class, 'deleteVisit']);

$r->get('/tasks', [TodoController::class, 'index']);
$r->get('/tasks/new', [TodoController::class, 'create']);
$r->post('/tasks', [TodoController::class, 'store']);
$r->get('/tasks/{id}', [TodoController::class, 'show']);
$r->get('/tasks/{id}/edit', [TodoController::class, 'edit']);
$r->post('/tasks/{id}', [TodoController::class, 'update']);
$r->post('/tasks/{id}/toggle', [TodoController::class, 'toggle']);
$r->post('/tasks/{id}/comments', [TodoController::class, 'comment']);
$r->post('/tasks/{id}/delete', [TodoController::class, 'delete']);

$r->get('/reports', [ReportController::class, 'index']);
$r->get('/reports/sales', [ReportController::class, 'sales']);
$r->get('/reports/projection', [ReportController::class, 'projection']);
$r->get('/reports/time', [ReportController::class, 'time']);
$r->get('/reports/service', [ReportController::class, 'service']);

$r->post('/activity/{id}/edit', [ActivityController::class, 'edit']);
$r->post('/activity/{id}/delete', [ActivityController::class, 'delete']);

// Customers, third parties, contacts, municipalities
$r->get('/customers', [OrganizationController::class, 'customers']);
$r->get('/directory', [OrganizationController::class, 'directory']);
$r->get('/organizations/new', [OrganizationController::class, 'create']);
$r->post('/organizations', [OrganizationController::class, 'store']);
$r->get('/organizations/{id}', [OrganizationController::class, 'show']);
$r->post('/organizations/{id}', [OrganizationController::class, 'update']);
$r->post('/contacts', [ContactController::class, 'store']);
$r->post('/contacts/{id}', [ContactController::class, 'update']);
$r->get('/municipalities', [MunicipalityController::class, 'index']);
$r->post('/municipalities', [MunicipalityController::class, 'store']);
$r->get('/municipalities/{id}', [MunicipalityController::class, 'show']);
$r->post('/municipalities/{id}', [MunicipalityController::class, 'update']);

// Admin: task template
$r->get('/admin/template', [TemplateController::class, 'index'], 'admin');
$r->get('/admin/template/new', [TemplateController::class, 'create'], 'admin');
$r->post('/admin/template', [TemplateController::class, 'store'], 'admin');
$r->get('/admin/template/{id}', [TemplateController::class, 'edit'], 'admin');
$r->post('/admin/template/{id}', [TemplateController::class, 'update'], 'admin');

try {
    $r->dispatch($_SERVER['REQUEST_METHOD'], current_path());
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    echo Config::bool('APP_DEBUG')
        ? '<pre>' . e((string) $e) . '</pre>'
        : 'Something went wrong. The error has been logged.';
}
