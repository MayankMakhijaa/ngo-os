<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Keeps the permission catalogue and default roles in sync with config/permissions.php.
 * Safe to run repeatedly (installer, every migration run). It never removes permissions a
 * customer has granted; it only adds new catalogue entries and gives "locked" all-access roles
 * (Fameweb Super Admin, every NGO Super Admin) any newly added permission.
 */
final class RbacSeeder
{
    public static function sync(): void
    {
        $db = Database::instance();
        $cfg = (array) config('permissions');

        $order = 0;
        foreach ($cfg['permissions'] as [$key, $scope, $module, $label]) {
            $db->execute(
                'INSERT INTO permissions (perm_key, scope, module, label, sort_order) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE scope = VALUES(scope), module = VALUES(module), label = VALUES(label), sort_order = VALUES(sort_order)',
                [$key, $scope, $module, $label, $order++]
            );
        }

        foreach ($cfg['platform_roles'] as $slug => $def) {
            $roleId = $db->fetchColumn('SELECT id FROM roles WHERE tenant_id IS NULL AND slug = ?', [$slug]);
            $isNew = !$roleId;
            if ($isNew) {
                $roleId = $db->insert('roles', [
                    'tenant_id' => null, 'scope' => 'platform', 'slug' => $slug, 'name' => $def['name'],
                    'description' => $def['description'], 'is_system' => 1, 'is_locked' => $def['locked'] ? 1 : 0,
                ]);
            }
            if ($isNew || $def['permissions'] === '*') {
                self::grant((int) $roleId, 'platform', $def['permissions']);
            }
        }

        // Every locked tenant role (NGO Super Admin) receives every tenant permission.
        foreach ($db->fetchAll("SELECT id FROM roles WHERE scope = 'tenant' AND is_locked = 1") as $r) {
            self::grant((int) $r['id'], 'tenant', '*');
        }
        PermissionService::flush();
    }

    /** Create the default NGO roles for a newly registered tenant. Returns slug => role id. */
    public static function provisionTenantRoles(int $tenantId): array
    {
        $db = Database::instance();
        $ids = [];
        foreach ((array) config('permissions.tenant_role_templates') as $slug => $def) {
            $id = $db->insert('roles', [
                'tenant_id' => $tenantId, 'scope' => 'tenant', 'slug' => $slug, 'name' => $def['name'],
                'description' => $def['description'], 'is_system' => 1, 'is_locked' => $def['locked'] ? 1 : 0,
            ]);
            self::grant($id, 'tenant', $def['permissions']);
            $ids[$slug] = $id;
        }
        return $ids;
    }

    /** @param string[]|string $permissions list of keys or "*" for every permission of the scope */
    public static function grant(int $roleId, string $scope, array|string $permissions): void
    {
        $db = Database::instance();
        if ($permissions === '*') {
            $db->execute(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE scope = ?',
                [$roleId, $scope]
            );
            return;
        }
        foreach ($permissions as $key) {
            $db->execute(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE perm_key = ? AND scope = ?',
                [$roleId, $key, $scope]
            );
        }
    }
}
