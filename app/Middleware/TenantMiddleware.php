<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Session;
use App\Core\TenantContext;
use App\Core\View;
use App\Services\AuditLogger;
use App\Services\SubscriptionService;
use App\Services\SupportAccessService;

/**
 * Resolves the tenant for NGO routes from the authenticated user's own database record.
 * Any tenant_id in the URL, form or cookies is ignored. Blocks suspended / deactivated NGOs.
 */
final class TenantMiddleware implements Middleware
{
    public function handle(Request $request, ?string $param): void
    {
        $user = Auth::user();
        if ($user === null || $user['user_type'] !== 'tenant' || $user['tenant_id'] === null) {
            AuditLogger::log('authz.denied', 'route', null, ['path' => $request->path, 'reason' => 'not_tenant_user']);
            throw new HttpException(403, 'This area is only available to NGO users.');
        }
        $tenant = Database::instance()->fetch('SELECT * FROM tenants WHERE id = ?', [(int) $user['tenant_id']]);
        if (!$tenant || $tenant['status'] !== 'active') {
            AuditLogger::log('auth.session_blocked', 'tenant', $user['tenant_id'], ['status' => $tenant['status'] ?? 'missing']);
            if (SupportAccessService::active() && SupportAccessService::stop('ngo_inactive') !== null) {
                Session::flash('warning', 'The support session ended because this NGO is no longer active.');
                throw new RedirectException(url('admin/ngos/' . (int) $user['tenant_id']));
            }
            Auth::logout();
            Session::flash('error', ($tenant['status'] ?? '') === 'suspended'
                ? 'Your organization\'s account is suspended. Please contact Fameweb support.'
                : 'Your organization\'s account is not active. Please contact Fameweb support.');
            throw new RedirectException(url('login'));
        }
        $subscription = SubscriptionService::current((int) $tenant['id']);
        TenantContext::set($tenant, $subscription);
        View::share('tenant', $tenant);
        View::share('subscription', $subscription);
    }
}
