<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Core\TenantContext;
use App\Services\DashboardService;
use App\Services\UsageService;

final class DashboardController extends NgoController
{
    public function index(): string
    {
        $tid = $this->tenantId();
        return $this->view('ngo/dashboard', [
            'title' => 'Dashboard',
            'stats' => DashboardService::tenant($tid),
            'usage' => can('usage.view') ? UsageService::summary($tid, TenantContext::subscription()) : null,
        ]);
    }
}
