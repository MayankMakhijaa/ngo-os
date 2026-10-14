<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Services\AuditLogger;
use App\Services\PermissionService;
use App\Services\StorageService;

/**
 * Private file download. Platform admins need tenants.view; NGO users may only fetch files of
 * their own tenant. Any other request gets 404 so file ids cannot be probed.
 */
final class FileController extends Controller
{
    public function show(): Response
    {
        $user = $this->user();
        $obj = Database::instance()->fetch(
            'SELECT so.*, t.status AS tenant_status FROM storage_objects so JOIN tenants t ON t.id = so.tenant_id
              WHERE so.uuid = ? AND so.deleted_at IS NULL',
            [$this->request->param('uuid')]
        );
        $allowed = false;
        if ($obj) {
            if (Auth::isPlatform()) {
                $allowed = PermissionService::instance()->userHas($user, 'tenants.view');
            } else {
                $allowed = (int) $user['tenant_id'] === (int) $obj['tenant_id'] && $obj['tenant_status'] === 'active'
                    && ($obj['category'] === 'logo' || PermissionService::instance()->userHas($user, 'organization.view'));
            }
        }
        if (!$allowed) {
            AuditLogger::log('authz.denied', 'storage_object', $obj['id'] ?? null, ['uuid' => $this->request->param('uuid')]);
            $this->notFound('file');
        }
        return StorageService::stream($obj);
    }
}
