<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\TenantContext;
use App\Services\SubscriptionService;

/** Blocks NGO routes whose module is not part of the NGO's subscription plan ("a|b" = either). */
final class ModuleMiddleware implements Middleware
{
    public function handle(Request $request, ?string $param): void
    {
        $modules = SubscriptionService::enabledModules(TenantContext::id());
        // "a|b" = any of these modules.
        $wanted = explode('|', (string) $param);
        if (!array_intersect($wanted, $modules)) {
            $label = config('modules.' . $wanted[0] . '.label', $wanted[0]);
            throw new HttpException(403, 'The ' . $label . ' module is not included in your organization\'s subscription plan.');
        }
    }
}
