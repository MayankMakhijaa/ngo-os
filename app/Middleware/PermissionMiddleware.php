<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\AuditLogger;
use App\Services\PermissionService;

/** Deny-by-default permission gate: route declares "perm:key" (several keys = any of them, "a|b"). */
final class PermissionMiddleware implements Middleware
{
    public function handle(Request $request, ?string $param): void
    {
        $user = Auth::user();
        if ($user === null || $param === null || $param === '') {
            throw new HttpException(403, 'You do not have permission to access this page.');
        }
        $service = PermissionService::instance();
        foreach (explode('|', $param) as $perm) {
            if ($service->userHas($user, $perm)) {
                return;
            }
        }
        AuditLogger::log('authz.denied', 'route', null, ['path' => $request->path, 'method' => $request->method, 'permission' => $param]);
        throw new HttpException(403, 'You do not have permission to perform this action. Ask your administrator if you need access.');
    }
}
