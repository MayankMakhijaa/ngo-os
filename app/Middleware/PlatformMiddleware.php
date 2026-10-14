<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\AuditLogger;

/**
 * Fameweb admin area. The check uses the user row from the database (user_type = platform and
 * no tenant), which the database CHECK constraint keeps consistent. No session or request field
 * is consulted, so it cannot be forged.
 */
final class PlatformMiddleware implements Middleware
{
    public function handle(Request $request, ?string $param): void
    {
        if (!Auth::isPlatform()) {
            AuditLogger::log('authz.denied', 'route', null, ['path' => $request->path, 'reason' => 'not_platform_user']);
            throw new HttpException(403, 'This area is only available to Fameweb administrators.');
        }
    }
}
