<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\TenantContext;

/**
 * Base for NGO portal controllers. tenantId() always comes from TenantContext, which
 * TenantMiddleware fills from the signed-in user's database record.
 */
abstract class NgoController extends Controller
{
    /** Separate layouts for NGO Super Admin, Manager and Operations (custom roles use Operations). */
    public static function layoutFor(?array $user): string
    {
        return match ($user['role_slug'] ?? '') {
            'ngo_super_admin' => 'layouts/ngo_super_admin',
            'ngo_manager' => 'layouts/ngo_manager',
            default => 'layouts/ngo_operations',
        };
    }

    protected function layout(): string
    {
        return self::layoutFor(Auth::user());
    }

    protected function tenantId(): int
    {
        return TenantContext::id();
    }
}
