<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Core\TenantContext;
use App\Services\UsageService;

final class UsageController extends NgoController
{
    public function index(): string
    {
        return $this->view('ngo/usage', [
            'title' => 'Usage',
            'usage' => UsageService::summary($this->tenantId(), TenantContext::subscription()),
            'sub' => TenantContext::subscription(),
        ]);
    }
}
