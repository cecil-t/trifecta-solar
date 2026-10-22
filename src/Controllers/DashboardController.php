<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Tasks;
use App\View;

final class DashboardController
{
	public function index(): void
	{
		\App\CustomerNotices::syncAll();
		View::render('dashboard', [
			'title' => 'Dashboard', 'user' => Auth::user(), 'counts' => \App\Projects::phaseCounts(), 'service' => \App\Service::counts(),
			'myTasks' => \App\Todos::openFor((int) Auth::id()),
			'items' => Tasks::openItemsFor((int) Auth::id(), 2000),
			'weather' => \App\Weather::forecast(),
			'recent' => \App\Activity::recentProjects(5),
			'monitor' => MonitorPageController::dashboardTiles(),
			'notices' => \App\CustomerNotices::allPending(),
		]);
	}
}
