<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Exceptions\DomainException;

/** NGO (tenant) lifecycle managed by Fameweb administrators. */
final class TenantService
{
    public const STATUSES = ['active', 'suspended', 'deactivated'];
    public const PROFILE_FIELDS = ['name', 'address', 'contact_name', 'email', 'phone', 'website', 'registration_number'];

    public const DEFAULT_SETTINGS = [
        'timezone' => 'Asia/Kolkata',
        'date_format' => 'd M Y',
        'notification_email' => '',
        'display_name' => '',
        'primary_color' => '#2b59c3',
        // Member ID prefixes (empty = MEM / VOL / INT)
        'member_id_prefix' => '',
        'volunteer_id_prefix' => '',
        'intern_id_prefix' => '',
    ];

    /**
     * Register an NGO in one transaction: tenant row, unique reference code, workspace settings,
     * default roles, invited Super Admin, subscription and a single-use onboarding link.
     * @return array{tenant_id:int, invite_link:string, warnings:string[]}
     */
    public static function create(array $profile, array $subscription, array $admin, ?array $logoFile = null, ?array $actor = null): array
    {
        $actor ??= Auth::user();
        $db = Database::instance();
        $adminEmail = mb_strtolower(trim($admin['email']));
        if ($db->fetchColumn('SELECT id FROM users WHERE email = ?', [$adminEmail])) {
            throw new DomainException('A user with the Super Admin email already exists. Use a different email address.');
        }
        $status = $profile['status'] ?? 'active';
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new DomainException('A new NGO must start as Active or Suspended.');
        }

        $ttlHours = SettingsService::int('invite_ttl_hours') ?: 72;
        $result = $db->transaction(function (Database $db) use ($profile, $subscription, $admin, $adminEmail, $status, $actor, $ttlHours) {
            $uuid = uuid4();
            $tenantId = $db->insert('tenants', [
                'uuid' => $uuid,
                'reference_code' => 'TMP-' . substr(bin2hex(random_bytes(6)), 0, 12),
                'name' => $profile['name'],
                'address' => $profile['address'],
                'contact_name' => $profile['contact_name'],
                'email' => mb_strtolower($profile['email']),
                'phone' => $profile['phone'],
                'website' => $profile['website'] ?: null,
                'registration_number' => $profile['registration_number'] ?: null,
                'notes' => $profile['notes'] ?: null,
                'status' => $status,
                'status_changed_at' => date('Y-m-d H:i:s'),
                'is_demo' => !empty($profile['is_demo']) ? 1 : 0,
                'created_by' => $actor['id'] ?? null,
            ]);
            $reference = sprintf('FW-NGO-%05d', $tenantId);
            $db->update('tenants', ['reference_code' => $reference], ['id' => $tenantId]);

            foreach (self::DEFAULT_SETTINGS as $k => $v) {
                $db->insert('tenant_settings', ['tenant_id' => $tenantId, 'setting_key' => $k,
                    'setting_value' => $k === 'display_name' ? $profile['name'] : ($k === 'notification_email' ? mb_strtolower($profile['email']) : $v)]);
            }

            $roles = RbacSeeder::provisionTenantRoles($tenantId);

            $userId = $db->insert('users', [
                'tenant_id' => $tenantId,
                'user_type' => 'tenant',
                'name' => $admin['name'],
                'email' => $adminEmail,
                'phone' => $admin['phone'] ?? null ?: null,
                'status' => 'invited',
                'created_by' => $actor['id'] ?? null,
            ]);
            $db->insert('user_roles', ['user_id' => $userId, 'role_id' => $roles['ngo_super_admin'], 'is_primary' => 1, 'assigned_by' => $actor['id'] ?? null]);

            [$subId, $warnings] = SubscriptionService::assign($tenantId, (int) $subscription['plan_id'], $subscription, $actor);

            $token = TokenService::issue($userId, 'invite', $ttlHours * 3600, $actor['id'] ?? null);

            AuditLogger::log('tenant.created', 'tenant', $tenantId, [
                'name' => $profile['name'], 'reference_code' => $reference, 'status' => $status, 'subscription_id' => $subId,
            ], $tenantId, $actor);
            AuditLogger::log('user.created', 'user', $userId, ['role' => 'ngo_super_admin', 'email' => $adminEmail], $tenantId, $actor);

            return ['tenant_id' => $tenantId, 'uuid' => $uuid, 'user_id' => $userId, 'token' => $token, 'warnings' => $warnings];
        });

        StorageService::ensureWorkspace($result['uuid']);
        $warnings = $result['warnings'];
        if ($logoFile) {
            try {
                self::replaceLogo($result['tenant_id'], $logoFile, $actor);
            } catch (DomainException|\App\Exceptions\LimitExceededException $e) {
                $warnings[] = 'The NGO was created, but the logo was not saved: ' . $e->getMessage();
            }
        }
        $link = absolute_url('invite/' . $result['token']);
        Mailer::send($adminEmail, 'You have been invited to ' . SettingsService::get('platform_name'), sprintf(
            "Hello %s,\n\n%s has been registered on %s and you are its Super Admin.\n\n"
            . "Set your password and activate your account with this single-use link (valid for %d hours):\n\n%s\n",
            $admin['name'], $profile['name'], SettingsService::get('platform_name'), $ttlHours, $link
        ), $result['tenant_id'], 'invite-email:' . $result['user_id'] . ':' . substr(hash('sha256', $result['token']), 0, 16));

        return ['tenant_id' => $result['tenant_id'], 'invite_link' => $link, 'warnings' => $warnings];
    }

    public static function update(int $tenantId, array $profile, ?string $notes, ?array $actor = null): void
    {
        $actor ??= Auth::user();
        $db = Database::instance();
        $before = $db->fetch('SELECT * FROM tenants WHERE id = ?', [$tenantId]) ?? throw new DomainException('NGO not found.');
        $data = [];
        foreach (self::PROFILE_FIELDS as $f) {
            if (array_key_exists($f, $profile)) {
                $v = $profile[$f] === '' && in_array($f, ['website', 'registration_number'], true) ? null : $profile[$f];
                $data[$f] = $f === 'email' ? mb_strtolower((string) $v) : $v;
            }
        }
        if ($notes !== null) {
            $data['notes'] = $notes === '' ? null : $notes;
        }
        $changed = [];
        foreach ($data as $k => $v) {
            if ((string) ($before[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[] = $k;
            }
        }
        if (!$changed) {
            return;
        }
        $db->update('tenants', $data, ['id' => $tenantId]);
        AuditLogger::log('tenant.updated', 'tenant', $tenantId, ['fields' => $changed], $tenantId, $actor);
    }

    /**
     * Activate / suspend / deactivate. Suspension and deactivation end every active session of
     * the NGO's users immediately (session_version bump) but keep all records untouched;
     * reactivation restores access with no data loss.
     */
    public static function changeStatus(int $tenantId, string $status, string $reason, ?array $actor = null): void
    {
        $actor ??= Auth::user();
        if (!in_array($status, self::STATUSES, true)) {
            throw new DomainException('Invalid status.');
        }
        $db = Database::instance();
        $db->transaction(function (Database $db) use ($tenantId, $status, $reason, $actor): void {
            $t = $db->fetch('SELECT * FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]) ?? throw new DomainException('NGO not found.');
            if ($t['status'] === $status) {
                throw new DomainException('The NGO is already ' . $status . '.');
            }
            $db->update('tenants', [
                'status' => $status,
                'status_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'status_changed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $tenantId]);
            if ($status !== 'active') {
                $db->execute('UPDATE users SET session_version = session_version + 1 WHERE tenant_id = ?', [$tenantId]);
            }
            $action = match ($status) {
                'active' => 'tenant.activated',
                'suspended' => 'tenant.suspended',
                'deactivated' => 'tenant.deactivated',
            };
            AuditLogger::log($action, 'tenant', $tenantId, ['from' => $t['status'], 'to' => $status, 'reason' => $reason], $tenantId, $actor);
        });
    }

    public static function replaceLogo(int $tenantId, array $file, ?array $actor = null): void
    {
        $actor ??= Auth::user();
        $db = Database::instance();
        $tenant = $db->fetch('SELECT * FROM tenants WHERE id = ?', [$tenantId]) ?? throw new DomainException('NGO not found.');
        $stored = StorageService::storeUpload(
            $tenant, $file, 'logo',
            (array) config('security.uploads.logo_mimes'),
            (int) config('security.uploads.max_logo_bytes'),
            isset($actor['id']) ? (int) $actor['id'] : null
        );
        $db->update('tenants', ['logo_object_id' => $stored['id']], ['id' => $tenantId]);
        if ($tenant['logo_object_id']) {
            StorageService::delete((int) $tenant['logo_object_id'], $tenantId, isset($actor['id']) ? (int) $actor['id'] : null);
        }
    }

    /** Issue a fresh single-use onboarding link for the NGO's (still invited) Super Admin. */
    public static function reissueInvite(int $tenantId, ?array $actor = null): string
    {
        $actor ??= Auth::user();
        $db = Database::instance();
        $user = $db->fetch(
            "SELECT u.* FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
              WHERE u.tenant_id = ? AND r.tenant_id = ? AND r.slug = 'ngo_super_admin' AND u.status = 'invited'
              ORDER BY u.id LIMIT 1",
            [$tenantId, $tenantId]
        );
        if (!$user) {
            throw new DomainException('This NGO has no Super Admin waiting for an invitation. Active admins can use "Forgot password" instead.');
        }
        $ttlHours = SettingsService::int('invite_ttl_hours') ?: 72;
        $token = TokenService::issue((int) $user['id'], 'invite', $ttlHours * 3600, $actor['id'] ?? null);
        $link = absolute_url('invite/' . $token);
        Mailer::send($user['email'], 'Your invitation to ' . SettingsService::get('platform_name'), sprintf(
            "Hello %s,\n\nHere is a new single-use link to activate your account (valid for %d hours):\n\n%s\n\nEarlier links no longer work.\n",
            $user['name'], $ttlHours, $link
        ), $tenantId, 'invite-email:' . $user['id'] . ':' . substr(hash('sha256', $token), 0, 16));
        AuditLogger::log('user.invite_reissued', 'user', $user['id'], [], $tenantId, $actor);
        return $link;
    }

    public static function settings(int $tenantId): array
    {
        $rows = Database::instance()->fetchAll('SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?', [$tenantId]);
        return array_merge(self::DEFAULT_SETTINGS, array_column($rows, 'setting_value', 'setting_key'));
    }

    public static function saveSettings(int $tenantId, array $settings, ?array $actor = null): void
    {
        $actor ??= Auth::user();
        $db = Database::instance();
        $before = self::settings($tenantId);
        $changed = [];
        foreach ($settings as $k => $v) {
            if (!array_key_exists($k, self::DEFAULT_SETTINGS)) {
                continue;
            }
            if ((string) $before[$k] !== (string) $v) {
                $changed[$k] = ['from' => $before[$k], 'to' => $v];
            }
            $db->execute(
                'INSERT INTO tenant_settings (tenant_id, setting_key, setting_value, updated_by) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
                [$tenantId, $k, $v, $actor['id'] ?? null]
            );
        }
        if ($changed) {
            AuditLogger::log('tenant.settings_updated', 'tenant', $tenantId, ['changes' => $changed], $tenantId, $actor);
        }
    }
}
