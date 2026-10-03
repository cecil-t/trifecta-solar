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
            $counts[$st['phase']]++;
            if ($p['hold_state'] === 'on_hold') {
                $counts['on_hold']++;
            } elseif ($st['phase'] === 'pre_install' && $st['clear_to_install']) {
                $counts['clear']++;
            }
        }
        View::render('dashboard', [
            'title' => 'Dashboard', 'user' => Auth::user(), 'counts' => $counts,
            'items' => Tasks::openItemsFor((int) Auth::id(), 60),
        ]);
    }
}
