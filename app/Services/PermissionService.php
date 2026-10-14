<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Deny-by-default permission resolution.
 *
 * effective(user) = (permissions of the user's roles  ∪  explicit grants)  −  explicit denies
 * restricted to permissions whose scope matches the user type (platform vs tenant), and for NGO
 * users further restricted to modules enabled on the NGO's current subscription plan.
 */
final class PermissionService
{
    private static ?PermissionService $instance = null;
    private ?array $catalog = null;
    private array $effective = [];

    public static function instance(): PermissionService
    {
        return self::$instance ??= new PermissionService();
    }

    /** Clear per-request caches (after a permission change, and between tests). */
    public static function flush(): void
    {
        self::$instance = null;
    }

    /** @return array<string, array> permission rows keyed by perm_key */
    public function catalog(): array
    {
        if ($this->catalog === null) {
            $rows = Database::instance()->fetchAll('SELECT * FROM permissions ORDER BY scope, module, sort_order, id');
            $this->catalog = array_column($rows, null, 'perm_key');
        }
        return $this->catalog;
    }

    public function catalogFor(string $scope): array
    {
        return array_filter($this->catalog(), static fn ($p) => $p['scope'] === $scope);
    }

    /** @return string[] */
    public function effective(array $user): array
    {
        $uid = (int) $user['id'];
        if (isset($this->effective[$uid])) {
            return $this->effective[$uid];
        }
        $db = Database::instance();
        $scope = $user['user_type'] === 'platform' ? 'platform' : 'tenant';
        $tenantId = $user['tenant_id'] === null ? null : (int) $user['tenant_id'];

        // Roles must belong to the user's own tenant (or be platform roles for platform users).
        $roleSql = 'SELECT DISTINCT p.perm_key
                      FROM user_roles ur
                      JOIN roles r ON r.id = ur.role_id
                      JOIN role_permissions rp ON rp.role_id = r.id
                      JOIN permissions p ON p.id = rp.permission_id
                     WHERE ur.user_id = :uid AND p.scope = :scope AND r.scope = :rscope AND '
                   . ($tenantId === null ? 'r.tenant_id IS NULL' : 'r.tenant_id = :tid');
        $params = ['uid' => $uid, 'scope' => $scope, 'rscope' => $scope];
        if ($tenantId !== null) {
            $params['tid'] = $tenantId;
        }
        $perms = array_flip(array_column($db->fetchAll($roleSql, $params), 'perm_key'));

        $overrides = $db->fetchAll(
            'SELECT p.perm_key, up.effect FROM user_permissions up
               JOIN permissions p ON p.id = up.permission_id
              WHERE up.user_id = ? AND p.scope = ?',
            [$uid, $scope]
        );
        foreach ($overrides as $o) {
            if ($o['effect'] === 'grant') {
                $perms[$o['perm_key']] = true;
            }
        }
        foreach ($overrides as $o) {
            if ($o['effect'] === 'deny') {
                unset($perms[$o['perm_key']]);
            }
        }

        // NGO users: drop permissions whose module is not enabled on the plan.
        if ($scope === 'tenant' && $tenantId !== null) {
            $enabled = SubscriptionService::enabledModules($tenantId);
            $map = (array) config('permissions.permission_modules', []);
            foreach (array_keys($perms) as $key) {
                if (isset($map[$key]) && !array_intersect((array) $map[$key], $enabled)) {
                    unset($perms[$key]);
                }
            }
        }
        $keys = array_keys($perms);
        sort($keys);
        return $this->effective[$uid] = $keys;
    }

    public function userHas(array $user, string $permission): bool
    {
        $perm = $this->catalog()[$permission] ?? null;
        if ($perm === null) {
            return false; // unknown permission: deny
        }
        $scope = $user['user_type'] === 'platform' ? 'platform' : 'tenant';
        if ($perm['scope'] !== $scope) {
            return false; // NGO users can never hold platform permissions and vice versa
        }
        return in_array($permission, $this->effective($user), true);
    }

    /** Raw permission keys attached to a role (without module filtering). */
    public function roleKeys(int $roleId): array
    {
        return array_column(Database::instance()->fetchAll(
            'SELECT p.perm_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.perm_key',
            [$roleId]
        ), 'perm_key');
    }

    /** Permission keys the role grants after the tenant's plan modules are applied. */
    public function roleKeysForTenant(int $roleId, int $tenantId): array
    {
        $enabled = SubscriptionService::enabledModules($tenantId);
        $map = (array) config('permissions.permission_modules', []);
        return array_values(array_filter(
            $this->roleKeys($roleId),
            static fn ($k) => !isset($map[$k]) || in_array($map[$k], $enabled, true)
        ));
    }

    /**
     * Anti-escalation rule: an actor may assign a role only when the role grants nothing the
     * actor does not already hold, and only NGO Super Admins may create other NGO Super Admins.
     */
    public function canAssignRole(array $actor, array $role): bool
    {
        if ($actor['user_type'] !== 'tenant' || $role['scope'] !== 'tenant'
            || (int) $role['tenant_id'] !== (int) $actor['tenant_id']) {
            return false;
        }
        if ($role['slug'] === 'ngo_super_admin' && ($actor['role_slug'] ?? null) !== 'ngo_super_admin') {
            return false;
        }
        $mine = $this->effective($actor);
        return array_diff($this->roleKeys((int) $role['id']), $mine) === [] || ($actor['role_slug'] ?? null) === 'ngo_super_admin';
    }

    /** An actor may grant or deny a permission only if they hold it themselves. */
    public function canDelegate(array $actor, string $permission): bool
    {
        $perm = $this->catalog()[$permission] ?? null;
        return $perm !== null && $perm['scope'] === 'tenant' && $this->userHas($actor, $permission);
    }

    /**
     * May the actor manage (edit, change role, change status, override permissions of) the target?
     * Same tenant, not themselves, and the target holds nothing the actor lacks.
     */
    public function canManageUser(array $actor, array $target): bool
    {
        if ($actor['user_type'] !== 'tenant' || $target['user_type'] !== 'tenant') {
            return false;
        }
        if ((int) $actor['tenant_id'] !== (int) $target['tenant_id'] || (int) $actor['id'] === (int) $target['id']) {
            return false;
        }
        if (($actor['role_slug'] ?? null) === 'ngo_super_admin') {
            return true;
        }
        if (($target['role_slug'] ?? null) === 'ngo_super_admin') {
            return false;
        }
        return array_diff($this->effective($target), $this->effective($actor)) === [];
    }
}
