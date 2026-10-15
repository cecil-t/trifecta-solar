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
use App\Controllers\CatalogController;
use App\Controllers\CommissionController;
use App\Controllers\ContactController;
use App\Controllers\DashboardController;
use App\Controllers\DesignController;
use App\Controllers\DisplayKeyController;
use App\Controllers\HealthController;
use App\Controllers\MonitorController;
use App\Controllers\MonitorPageController;
use App\Controllers\MunicipalityController;
use App\Controllers\OrganizationController;
use App\Controllers\PresalesController;
use App\Controllers\PresalesRatesController;
use App\Controllers\ProjectController;
use App\Controllers\SearchController;
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

// Content Security Policy: scripts, styles and everything else only from this site, and no inline
// script (page behavior lives in public/assets/js, wired by data- attributes). If a value ever
// slipped past e(), the browser still would not run it.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
	. "font-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; object-src 'none'; "
	. "base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

session_name('ts_sess');
session_set_cookie_params(['path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
session_start();

if (Migrator::hasPending()) {
	Migrator::migrate();
}

Auth::init();

$r = new Router();

$r->get('/health', [HealthController::class, 'show'], 'public');
$r->get('/manifest.webmanifest', [App\Controllers\ManifestController::class, 'show'], 'public');

$r->get('/setup', [SetupController::class, 'form'], 'public');
$r->post('/setup', [SetupController::class, 'save'], 'public');

$r->get('/login', [AuthController::class, 'form'], 'public');
$r->post('/login', [AuthController::class, 'login'], 'public');
$r->post('/logout', [AuthController::class, 'logout']);

$r->get('/', [DashboardController::class, 'index']);

$r->get('/account', [AccountController::class, 'show']);
$r->get('/about', [AboutController::class, 'show']);
$r->post('/account/profile', [AccountController::class, 'profile']);
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

// Search (top bar)
$r->get('/search', [SearchController::class, 'index']);

// Pre-Sales: opportunities and their build sheets, the price catalog and the buildsheet rates
$r->get('/presales', [PresalesController::class, 'index']);
$r->get('/presales/new', [PresalesController::class, 'create']);
$r->post('/presales', [PresalesController::class, 'store']);
$r->get('/presales/catalog', [CatalogController::class, 'index']);
$r->get('/presales/catalog/new', [CatalogController::class, 'create']);
$r->post('/presales/catalog', [CatalogController::class, 'store']);
$r->get('/presales/catalog/{id}', [CatalogController::class, 'edit']);
$r->post('/presales/catalog/{id}', [CatalogController::class, 'update']);
$r->post('/presales/catalog/{id}/confirm', [CatalogController::class, 'confirm']);
$r->get('/presales/rates', [PresalesRatesController::class, 'show']);
$r->post('/presales/rates', [PresalesRatesController::class, 'save']);
$r->get('/presales/{id}', [PresalesController::class, 'show']);
$r->get('/presales/{id}/edit', [PresalesController::class, 'edit']);
$r->post('/presales/{id}', [PresalesController::class, 'update']);
$r->post('/presales/{id}/status', [PresalesController::class, 'status']);
$r->post('/presales/{id}/comments', [PresalesController::class, 'comment']);
$r->post('/presales/{id}/designs', [DesignController::class, 'create']);
$r->get('/presales/{id}/designs/{designId}', [DesignController::class, 'show']);
$r->post('/presales/{id}/designs/{designId}', [DesignController::class, 'save']);
$r->get('/presales/{id}/designs/{designId}/summary', [DesignController::class, 'summary']);
$r->post('/presales/{id}/designs/{designId}/calc', [DesignController::class, 'calc']);
$r->post('/presales/{id}/designs/{designId}/duplicate', [DesignController::class, 'duplicate']);
$r->post('/presales/{id}/designs/{designId}/reprice', [DesignController::class, 'reprice']);
$r->post('/presales/{id}/designs/{designId}/delete', [DesignController::class, 'delete']);

// Projects
$r->get('/projects', [ProjectController::class, 'index']);
$r->get('/projects/new', [ProjectController::class, 'create']);
$r->get('/geo/lookup', [ProjectController::class, 'geoLookup']);
$r->post('/projects', [ProjectController::class, 'store']);
$r->get('/projects/{id}', [ProjectController::class, 'show']);
$r->get('/projects/{id}/edit', [ProjectController::class, 'edit']);
$r->get('/projects/{id}/summary', [ProjectController::class, 'summary']);
$r->post('/projects/{id}', [ProjectController::class, 'update']);
$r->post('/projects/{id}/comments', [ProjectController::class, 'comment']);
$r->post('/projects/{id}/notes', [ProjectController::class, 'saveNotes']);
$r->post('/projects/{id}/geo-check', [ProjectController::class, 'geoCheck']);
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
$r->get('/reports/opportunities', [ReportController::class, 'opportunities']);

$r->post('/activity/{id}/edit', [ActivityController::class, 'edit']);
$r->post('/activity/{id}/delete', [ActivityController::class, 'delete']);

// Customers, third parties, contacts, municipalities
$r->get('/customers', [OrganizationController::class, 'customers']);
$r->get('/directory', [OrganizationController::class, 'directory']);
$r->get('/organizations/search', [OrganizationController::class, 'search']);
$r->get('/commissions', [CommissionController::class, 'index']);
$r->get('/commissions/{id}', [CommissionController::class, 'show']);
$r->get('/commissions/{id}/edit', [CommissionController::class, 'edit']);
$r->post('/commissions/{id}', [CommissionController::class, 'update']);
$r->post('/commissions/{id}/entries', [CommissionController::class, 'addEntry']);
$r->post('/commissions/{id}/entries/{entryId}/delete', [CommissionController::class, 'deleteEntry']);
$r->post('/commissions/{id}/paid', [CommissionController::class, 'markPaid']);
$r->post('/commissions/{id}/delete', [CommissionController::class, 'delete']);
$r->get('/projects/{id}/commission/new', [CommissionController::class, 'create']);
$r->post('/projects/{id}/commission', [CommissionController::class, 'store']);
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

// Monitoring (office display; reads only what monitor:poll stored)
$r->get('/monitor', [MonitorPageController::class, 'show']);
// Office screens: the Monitor page with a display key instead of a sign-in (Lists > Display links)
$r->get('/display', [MonitorPageController::class, 'display'], 'public');
$r->get('/admin/display-links', [DisplayKeyController::class, 'index'], 'admin');
$r->post('/admin/display-links', [DisplayKeyController::class, 'store'], 'admin');
$r->post('/admin/display-links/{id}', [DisplayKeyController::class, 'rename'], 'admin');
$r->post('/admin/display-links/{id}/revoke', [DisplayKeyController::class, 'revoke'], 'admin');

// Admin: monitoring API test (one call per click; Enphase OAuth connect and callback)
$r->get('/admin/monitor-test', [MonitorController::class, 'test'], 'admin');
$r->post('/admin/monitor-test', [MonitorController::class, 'run'], 'admin');
$r->post('/admin/monitor-test/enlighten-forget', [MonitorController::class, 'enlightenForget'], 'admin');
$r->post('/admin/monitor-test/growatt-captcha', [MonitorController::class, 'growattCaptcha'], 'admin');
$r->post('/admin/monitor-test/growatt-login', [MonitorController::class, 'growattLogin'], 'admin');
$r->post('/admin/monitor-test/growatt-forget', [MonitorController::class, 'growattForget'], 'admin');
$r->post('/admin/monitor-test/sma-forget', [MonitorController::class, 'smaForget'], 'admin');

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
