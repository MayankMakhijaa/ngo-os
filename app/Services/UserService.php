<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\DomainException;

/**
 * NGO user management. Every method receives the acting user and the tenant comes from the
 * actor's own record, never from request input. Anti-escalation rules:
 *  - targets must belong to the actor's tenant (else "not found");
 *  - nobody changes their own role, status or permission overrides;
 *  - an actor can only manage users whose permissions are a subset of their own;
 *  - an actor can only assign roles / grant permissions they already hold;
 *  - only an NGO Super Admin can create another NGO Super Admin;
 *  - the last active NGO Super Admin cannot be demoted or deactivated.
 */
final class UserService
{
    /** Load a user of the actor's tenant (with primary role). Returns null for other tenants. */
    public static function findInTenant(int $tenantId, int $userId): ?array
    {
        return Database::instance()->fetch(
            'SELECT u.id, u.tenant_id, u.user_type, u.name, u.email, u.phone, u.status, u.last_login_at, u.last_login_ip,
                    u.created_at, u.updated_at, u.password_changed_at,
                    r.id AS role_id, r.slug AS role_slug, r.name AS role_name
               FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.is_primary = 1
               LEFT JOIN roles r ON r.id = ur.role_id AND r.tenant_id = u.tenant_id
              WHERE u.id = ? AND u.tenant_id = ? AND u.user_type = \'tenant\'',
            [$userId, $tenantId]
        );
    }

    public static function roleInTenant(int $tenantId, int $roleId): ?array
    {
        return Database::instance()->fetch(
            "SELECT * FROM roles WHERE id = ? AND tenant_id = ? AND scope = 'tenant'",
            [$roleId, $tenantId]
        );
    }

    /** @return array{user_id:int, invite_link:string} */
    public static function create(array $actor, array $data, int $roleId): array
    {
        $tenantId = (int) $actor['tenant_id'];
        $perm = PermissionService::instance();
        $role = self::roleInTenant($tenantId, $roleId) ?? throw new DomainException('Please choose a valid role.');
        if (!$perm->canAssignRole($actor, $role)) {
            throw new DomainException('You cannot assign the "' . $role['name'] . '" role because it includes permissions you do not have.');
        }
        $email = mb_strtolower(trim($data['email']));
        $db = Database::instance();
        $ttlHours = SettingsService::int('invite_ttl_hours') ?: 72;

        $res = $db->transaction(function (Database $db) use ($tenantId, $actor, $data, $email, $roleId, $role, $ttlHours) {
            // Serialise user creation per tenant so two parallel requests cannot both pass the limit check.
            $db->fetch('SELECT id FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
            if ($db->fetchColumn('SELECT id FROM users WHERE email = ?', [$email])) {
                throw new DomainException('A user with this email address already exists.');
            }
            UsageService::assertCanAdd($tenantId, 'users', 1);
            $userId = $db->insert('users', [
                'tenant_id' => $tenantId,
                'user_type' => 'tenant',
                'name' => $data['name'],
                'email' => $email,
                'phone' => ($data['phone'] ?? '') ?: null,
                'status' => 'invited',
                'created_by' => (int) $actor['id'],
            ]);
            $db->insert('user_roles', ['user_id' => $userId, 'role_id' => $roleId, 'is_primary' => 1, 'assigned_by' => (int) $actor['id']]);
            $token = TokenService::issue($userId, 'invite', $ttlHours * 3600, (int) $actor['id']);
            AuditLogger::log('user.created', 'user', $userId, ['email' => $email, 'role' => $role['slug']], $tenantId, $actor);
            return ['user_id' => $userId, 'token' => $token];
        });

        $link = absolute_url('invite/' . $res['token']);
        $tenantName = (string) $db->fetchColumn('SELECT name FROM tenants WHERE id = ?', [$tenantId]);
        Mailer::send($email, 'You have been invited to ' . $tenantName, sprintf(
            "Hello %s,\n\n%s invited you to %s on %s as %s.\n\nActivate your account with this single-use link (valid for %d hours):\n\n%s\n",
            $data['name'], $actor['name'], $tenantName, SettingsService::get('platform_name'), $role['name'], $ttlHours, $link
        ), $tenantId, 'invite-email:' . $res['user_id'] . ':' . substr(hash('sha256', $res['token']), 0, 16));
        return ['user_id' => $res['user_id'], 'invite_link' => $link];
    }

    public static function updateDetails(array $actor, array $target, array $data): void
    {
        self::assertManageable($actor, $target);
        $email = mb_strtolower(trim($data['email']));
        $db = Database::instance();
        if ($email !== $target['email'] && $db->fetchColumn('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, (int) $target['id']])) {
            throw new DomainException('Another user already uses this email address.');
        }
        $update = ['name' => $data['name'], 'email' => $email, 'phone' => ($data['phone'] ?? '') ?: null];
        if ($email !== $target['email']) {
            // Changing the login email ends the user's sessions.
            $db->execute('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [(int) $target['id']]);
        }
        $db->update('users', $update, ['id' => (int) $target['id'], 'tenant_id' => (int) $actor['tenant_id']]);
        $changed = array_keys(array_filter($update, static fn ($v, $k) => (string) $v !== (string) ($target[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            AuditLogger::log('user.updated', 'user', $target['id'], ['fields' => $changed], (int) $actor['tenant_id'], $actor);
        }
    }

    public static function changeRole(array $actor, array $target, int $roleId): void
    {
        self::assertManageable($actor, $target);
        $tenantId = (int) $actor['tenant_id'];
        $role = self::roleInTenant($tenantId, $roleId) ?? throw new DomainException('Please choose a valid role.');
        if ((int) $target['role_id'] === $roleId) {
            return;
        }
        if (!PermissionService::instance()->canAssignRole($actor, $role)) {
            throw new DomainException('You cannot assign the "' . $role['name'] . '" role because it includes permissions you do not have.');
        }
        $db = Database::instance();
        $db->transaction(function (Database $db) use ($actor, $target, $role, $roleId, $tenantId): void {
            if ($target['role_slug'] === 'ngo_super_admin' && $role['slug'] !== 'ngo_super_admin') {
                self::assertNotLastSuperAdmin($tenantId, (int) $target['id']);
            }
            $db->execute('DELETE FROM user_roles WHERE user_id = ?', [(int) $target['id']]);
            $db->insert('user_roles', ['user_id' => (int) $target['id'], 'role_id' => $roleId, 'is_primary' => 1, 'assigned_by' => (int) $actor['id']]);
            AuditLogger::log('user.role_changed', 'user', $target['id'], ['from' => $target['role_slug'], 'to' => $role['slug']], $tenantId, $actor);
        });
        PermissionService::flush();
    }

    public static function setStatus(array $actor, array $target, string $status): void
    {
        self::assertManageable($actor, $target);
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new DomainException('Invalid status.');
        }
        $tenantId = (int) $actor['tenant_id'];
        $db = Database::instance();
        $db->transaction(function (Database $db) use ($actor, $target, $status, $tenantId): void {
            $db->fetch('SELECT id FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
            if ($status === 'inactive') {
                if ($target['status'] === 'inactive') {
                    throw new DomainException('This user is already inactive.');
                }
                if ($target['role_slug'] === 'ngo_super_admin') {
                    self::assertNotLastSuperAdmin($tenantId, (int) $target['id']);
                }
                $db->execute('UPDATE users SET status = \'inactive\', session_version = session_version + 1 WHERE id = ? AND tenant_id = ?', [(int) $target['id'], $tenantId]);
                TokenService::revokeAll((int) $target['id']);
                AuditLogger::log('user.deactivated', 'user', $target['id'], [], $tenantId, $actor);
            } else {
                if ($target['status'] !== 'inactive') {
                    throw new DomainException('This user is not inactive.');
                }
                UsageService::assertCanAdd($tenantId, 'users', 1);
                // A user who never set a password goes back to "invited".
                $hasPassword = (bool) $db->fetchColumn('SELECT password_hash IS NOT NULL FROM users WHERE id = ?', [(int) $target['id']]);
                $db->execute('UPDATE users SET status = ? WHERE id = ? AND tenant_id = ?', [$hasPassword ? 'active' : 'invited', (int) $target['id'], $tenantId]);
                AuditLogger::log('user.reactivated', 'user', $target['id'], [], $tenantId, $actor);
            }
        });
    }

    /** New invitation (invited users) or password-reset link (active users). Returns the link. */
    public static function sendAccessLink(array $actor, array $target): string
    {
        self::assertManageable($actor, $target);
        $tenantId = (int) $actor['tenant_id'];
        if ($target['status'] === 'inactive') {
            throw new DomainException('Reactivate the user before sending an access link.');
        }
        $purpose = $target['status'] === 'invited' ? 'invite' : 'reset';
        $ttl = $purpose === 'invite' ? (SettingsService::int('invite_ttl_hours') ?: 72) * 3600 : (SettingsService::int('reset_ttl_minutes') ?: 60) * 60;
        $token = TokenService::issue((int) $target['id'], $purpose, $ttl, (int) $actor['id']);
        $link = absolute_url(($purpose === 'invite' ? 'invite/' : 'reset-password/') . $token);
        Mailer::send($target['email'], $purpose === 'invite' ? 'Your invitation' : 'Reset your password', sprintf(
            "Hello %s,\n\n%s sent you a single-use link to %s:\n\n%s\n\nEarlier links no longer work.\n",
            $target['name'], $actor['name'], $purpose === 'invite' ? 'activate your account' : 'set a new password', $link
        ), $tenantId, 'access-email:' . $target['id'] . ':' . substr(hash('sha256', $token), 0, 16));
        AuditLogger::log($purpose === 'invite' ? 'user.invite_reissued' : 'user.reset_link_issued', 'user', $target['id'], [], $tenantId, $actor);
        return $link;
    }

    /**
     * Replace the user's permission overrides for the permissions the actor is allowed to
     * delegate. $overrides: perm_key => 'grant' | 'deny' | 'inherit'.
     */
    public static function setOverrides(array $actor, array $target, array $overrides): void
    {
        self::assertManageable($actor, $target);
        $perm = PermissionService::instance();
        $db = Database::instance();
        $tenantId = (int) $actor['tenant_id'];
        $catalog = $perm->catalogFor('tenant');
        $changes = [];
        $db->transaction(function (Database $db) use ($overrides, $perm, $actor, $target, $catalog, &$changes): void {
            foreach ($overrides as $key => $effect) {
                if (!is_string($key) || !isset($catalog[$key]) || !in_array($effect, ['grant', 'deny', 'inherit'], true)) {
                    continue;
                }
                if (!$perm->canDelegate($actor, $key)) {
                    throw new DomainException('You cannot grant or deny "' . $catalog[$key]['label'] . '" because you do not have that permission.');
                }
                $pid = (int) $catalog[$key]['id'];
                $current = $db->fetchColumn('SELECT effect FROM user_permissions WHERE user_id = ? AND permission_id = ?', [(int) $target['id'], $pid]);
                $current = $current ?: 'inherit';
                if ($current === $effect) {
                    continue;
                }
                if ($effect === 'inherit') {
                    $db->execute('DELETE FROM user_permissions WHERE user_id = ? AND permission_id = ?', [(int) $target['id'], $pid]);
                } else {
                    $db->execute(
                        'INSERT INTO user_permissions (user_id, permission_id, effect, granted_by) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE effect = VALUES(effect), granted_by = VALUES(granted_by)',
                        [(int) $target['id'], $pid, $effect, (int) $actor['id']]
                    );
                }
                $changes[$key] = ['from' => $current, 'to' => $effect];
            }
        });
        if ($changes) {
            AuditLogger::log('user.permissions_changed', 'user', $target['id'], ['changes' => $changes], $tenantId, $actor);
        }
        PermissionService::flush();
    }

    public static function overrides(int $userId): array
    {
        return array_column(Database::instance()->fetchAll(
            'SELECT p.perm_key, up.effect FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = ?',
            [$userId]
        ), 'effect', 'perm_key');
    }

    public static function assertManageable(array $actor, array $target): void
    {
        if ((int) $actor['id'] === (int) $target['id']) {
            throw new DomainException('You cannot change your own role, status or permissions. Ask another administrator.');
        }
        if (!PermissionService::instance()->canManageUser($actor, $target)) {
            throw new DomainException('You cannot manage this user because they have permissions you do not have.');
        }
    }

    private static function assertNotLastSuperAdmin(int $tenantId, int $userId): void
    {
        $others = (int) Database::instance()->fetchColumn(
            "SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
              WHERE u.tenant_id = ? AND r.tenant_id = ? AND r.slug = 'ngo_super_admin' AND u.status = 'active' AND u.id <> ?",
            [$tenantId, $tenantId, $userId]
        );
        if ($others === 0) {
            throw new DomainException('This is the only active NGO Super Admin. Make another user Super Admin first.');
        }
    }
}
