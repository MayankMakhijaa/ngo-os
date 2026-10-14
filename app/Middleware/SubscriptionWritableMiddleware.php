<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\TenantContext;
use App\Services\SubscriptionService;

/**
 * NGO data can be changed only while the subscription is Trial, Active or Past Due (grace).
 * Expired, Suspended, Cancelled or missing subscriptions leave the workspace read-only:
 * nothing is deleted and access returns as soon as the subscription is renewed.
 */
final class SubscriptionWritableMiddleware implements Middleware
{
    public function handle(Request $request, ?string $param): void
    {
        $sub = TenantContext::subscription();
        if (!SubscriptionService::isWritable($sub)) {
            $status = $sub ? str_replace('_', ' ', $sub['status']) : 'missing';
            throw new HttpException(403, "Your organization's subscription is $status, so the workspace is read-only. "
                . 'Your data is safe. Please contact Fameweb to renew.');
        }
    }
}
