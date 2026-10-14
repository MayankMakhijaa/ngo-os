<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Core\TenantContext;
use App\Services\SubscriptionService;

final class SubscriptionController extends NgoController
{
    public function show(): string
    {
        $tid = $this->tenantId();
        return $this->view('ngo/subscription', [
            'title' => 'Subscription',
            'sub' => TenantContext::subscription(),
            'history' => SubscriptionService::history($tid),
            'payments' => SubscriptionService::payments($tid),
            'modules' => SubscriptionService::enabledModules($tid),
        ]);
    }
}
