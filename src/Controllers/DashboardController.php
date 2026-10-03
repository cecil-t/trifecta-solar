<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Tasks;
use App\View;

final class DashboardController
{
    public function index(): void
    {
        $projects = Db::all('SELECT * FROM projects');
        $tasks = Tasks::forProjects(array_column($projects, 'id'));
        $counts = ['pre_install' => 0, 'clear' => 0, 'installation' => 0, 'closeout' => 0, 'on_hold' => 0];
        foreach ($projects as $p) {
            $st = Tasks::status($p, $tasks[(int) $p['id']] ?? []);
            if (in_array($st['phase'], ['complete', 'cancelled'], true)) {
                continue;
            }
            if ($p['hold_state'] === 'on_hold') {
                $counts['on_hold']++;
                continue; // on-hold jobs are not counted in the phase totals
            }
            $counts[$st['phase']]++;
            if ($st['phase'] === 'pre_install' && $st['clear_to_install']) {
                $counts['clear']++;
            }
        }
        $service = ['open' => 0, 'to_invoice' => 0];
        foreach (Db::all('SELECT coverage, completed_on, scheduled_on, invoiced FROM service_tickets') as $t) {
            $st = \App\Service::status($t);
            if ($st === 'open' || $st === 'scheduled') { $service['open']++; }
            if ($st === 'to_invoice') { $service['to_invoice']++; }
        }
        View::render('dashboard', [
            'title' => 'Dashboard', 'user' => Auth::user(), 'counts' => $counts, 'service' => $service,
            'myTasks' => \App\Todos::openFor((int) Auth::id()),
            'items' => Tasks::openItemsFor((int) Auth::id(), 2000),
            'weather' => \App\Weather::forecast(),
        ]);
    }
}
