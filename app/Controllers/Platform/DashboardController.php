<?php
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Services\DashboardService;

final class DashboardController extends PlatformController
{
    public function index(): string
    {
        return $this->view('platform/dashboard', ['title' => 'SaaS Dashboard', 'stats' => DashboardService::platform(), 'charts' => true]);
    }
}
