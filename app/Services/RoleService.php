<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\DomainException;

/** NGO roles: default roles are copied per NGO; custom roles can be added. */
final class RoleService
{
    public static function listForTenant(int $tenantId): array
    {
        return Database::instance()->fetchAll(
            'SELECT r.*, (SELECT COUNT(*) FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.role_id = r.id AND u.tenant_id = r.tenant_id) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
               FROM roles r WHERE r.tenant_id = ? AND r.scope = \'tenant\' ORDER BY r.is_system DESC, r.id',
            [$tenantId]
        );
    }

    public static function create(array $actor, string $name, string $description, array $permKeys): int
    {
        $tenantId = (int) $actor['tenant_id'];
        $slug = 'custom_' . trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        if ($slug === 'custom_') {
            throw new DomainException('Please enter a role name using letters or numbers.');
        }
        $db = Database::instance();
        if ($db->fetchColumn('SELECT id FROM roles WHERE tenant_id = ? AND (slug = ? OR name = ?)', [$tenantId, $slug, $name])) {
            throw new DomainException('A role with this name already exists.');
        }
        $keys = self::filterDelegable($actor, $permKeys);
        return $db->transaction(function (Database $db) use ($tenantId, $slug, $name, $description, $keys, $actor) {
            $id = $db->insert('roles', [
                'tenant_id' => $tenantId, 'scope' => 'tenant', 'slug' => mb_substr($slug, 0, 60), 'name' => $name,
                'description' => $description ?: null, 'is_system' => 0, 'is_locked' => 0,
            ]);
            RbacSeeder::grant($id, 'tenant', $keys);
            AuditLogger::log('role.created', 'role', $id, ['name' => $name, 'permissions' => $keys], $tenantId, $actor);
            return $id;
        });
    }

    /**
     * Update a role's permissions. Only permissions the actor holds can be toggled; others on
     * the role are left as they are. Actors cannot edit the role they hold themselves.
     */
    public static function update(array $actor, array $role, string $name, string $description, array $permKeys): void
    {
        $tenantId = (int) $actor['tenant_id'];
        if ((int) $role['tenant_id'] !== $tenantId) {
            throw new DomainException('Role not found.');
        }
        if ((int) $role['is_locked']) {
            throw new DomainException('The permissions of this role are fixed and cannot be changed.');
        }
        if ((int) $role['id'] === (int) ($actor['role_id'] ?? 0)) {
            throw new DomainException('You cannot change the permissions of your own role.');
        }
        $perm = PermissionService::instance();
        $db = Database::instance();
        $current = $perm->roleKeys((int) $role['id']);
        $wanted = self::filterDelegable($actor, $permKeys);
        $delegable = array_keys(array_filter($perm->catalogFor('tenant'), static fn ($p) => $perm->canDelegate($actor, $p['perm_key'])));
        // Final set = (current permissions the actor cannot touch) ∪ (requested permissions the actor holds).
        $final = array_values(array_unique(array_merge(array_diff($current, $delegable), $wanted)));
        sort($final);
        if (!(int) $role['is_system']) {
            if ($db->fetchColumn('SELECT id FROM roles WHERE tenant_id = ? AND name = ? AND id <> ?', [$tenantId, $name, (int) $role['id']])) {
                throw new DomainException('A role with this name already exists.');
            }
        } else {
            $name = $role['name']; // default role names are fixed
        }
        $db->transaction(function (Database $db) use ($role, $name, $description, $final, $current, $actor, $tenantId): void {
            $db->update('roles', ['name' => $name, 'description' => $description ?: null], ['id' => (int) $role['id'], 'tenant_id' => $tenantId]);
            $db->execute('DELETE FROM role_permissions WHERE role_id = ?', [(int) $role['id']]);
            RbacSeeder::grant((int) $role['id'], 'tenant', $final);
            AuditLogger::log('role.updated', 'role', $role['id'], [
                'added' => array_values(array_diff($final, $current)),
                'removed' => array_values(array_diff($current, $final)),
            ], $tenantId, $actor);
        });
        PermissionService::flush();
    }

    public static function delete(array $actor, array $role): void
    {
        $tenantId = (int) $actor['tenant_id'];
        if ((int) $role['tenant_id'] !== $tenantId || (int) $role['is_system']) {
            throw new DomainException('Default roles cannot be deleted.');
        }
        $db = Database::instance();
        if ((int) $db->fetchColumn('SELECT COUNT(*) FROM user_roles WHERE role_id = ?', [(int) $role['id']]) > 0) {
            throw new DomainException('This role is assigned to users. Move them to another role first.');
        }
        $db->execute('DELETE FROM roles WHERE id = ? AND tenant_id = ? AND is_system = 0', [(int) $role['id'], $tenantId]);
        AuditLogger::log('role.deleted', 'role', $role['id'], ['name' => $role['name']], $tenantId, $actor);
    }

    /** Requested keys limited to tenant permissions the actor may delegate. */
    private static function filterDelegable(array $actor, array $keys): array
    {
        $perm = PermissionService::instance();
        $out = [];
        foreach ($keys as $k) {
            if (!is_string($k)) {
                continue;
            }
            if (!$perm->canDelegate($actor, $k)) {
                $label = $perm->catalog()[$k]['label'] ?? $k;
                throw new DomainException('You cannot grant "' . $label . '" because you do not have that permission.');
            }
            $out[] = $k;
        }
        return array_values(array_unique($out));
    }
}
