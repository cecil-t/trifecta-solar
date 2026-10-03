<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\View;

final class DashboardController
{
    public function index(): void
    {
        View::render('dashboard', ['title' => 'Dashboard', 'user' => Auth::user()]);
    }
}
