<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Migrator;

/** Unauthenticated liveness check used by the Docker healthcheck. Reveals nothing sensitive. */
final class HealthController
{
	public function show(): void
	{
		header('Content-Type: application/json');
		header('Cache-Control: no-store');
		try {
			Db::value('SELECT 1');
			echo json_encode(['status' => 'ok', 'migrations' => count(Migrator::applied()), 'time' => now_utc()]);
		} catch (\Throwable $e) {
			http_response_code(503);
			echo json_encode(['status' => 'error']);
		}
	}
}
