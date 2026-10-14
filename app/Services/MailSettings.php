<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\DomainException;
use App\Support\Crypto;

/**
 * Resolves which mail transport and sender an email uses.
 *  - Platform emails (and NGO emails by default) use the Fameweb settings from Admin > Email,
 *    falling back to MAIL_* in .env.
 *  - An NGO with the Email Automation module can switch on its own SMTP server, so its emails
 *    come from its own domain. Without it, NGO emails go out through the platform server with
 *    the NGO's name as sender name and its notification address as Reply-To.
 * SMTP passwords are stored encrypted with APP_KEY and never shown again after saving.
 */
final class MailSettings
{
    public const ENCRYPTIONS = ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None (local relay only)'];
    public const DRIVERS = ['log' => 'Log only (no email leaves the server)', 'mail' => 'PHP mail()', 'smtp' => 'SMTP'];

    /** NGO-level keys in tenant_settings. */
    public const TENANT_KEYS = [
        'mail_smtp_enabled' => '0', 'mail_smtp_host' => '', 'mail_smtp_port' => '587', 'mail_smtp_encryption' => 'tls',
        'mail_smtp_username' => '', 'mail_smtp_password_enc' => '', 'mail_from_address' => '', 'mail_from_name' => '',
        'mail_reply_to' => '',
        // Automatic emails (Email Automation module)
        'auto_member_welcome' => '0', 'auto_certificate_issued' => '0', 'auto_receipt_donor' => '1',
    ];

    public const AUTOMATIONS = [
        'auto_member_welcome' => 'Welcome email when a member, volunteer or intern is registered (with their member ID)',
        'auto_certificate_issued' => 'Email the certificate PDF to the person when it is issued',
        'auto_receipt_donor' => 'Email the 80G receipt PDF to the donor when it is issued',
    ];

    /** @return array{driver:string, host:string, port:int, encryption:string, username:string, password:string, from:string, from_name:string, reply_to:?string, source:string} */
    public static function platform(): array
    {
        $s = SettingsService::all();
        $driver = $s['mail_driver'] !== '' ? $s['mail_driver'] : (string) config('mail.driver', 'log');
        $useDb = $s['mail_driver'] !== '';
        $password = '';
        if ($useDb && $s['smtp_password_enc'] !== '') {
            try {
                $password = Crypto::decrypt($s['smtp_password_enc']);
            } catch (\Throwable) {
                $password = '';
            }
        } elseif (!$useDb) {
            $password = (string) config('mail.password', '');
        }
        return [
            'driver' => in_array($driver, ['log', 'mail', 'smtp'], true) ? $driver : 'log',
            'host' => $useDb ? $s['smtp_host'] : (string) config('mail.host', ''),
            'port' => (int) ($useDb ? ($s['smtp_port'] ?: 587) : config('mail.port', 587)),
            'encryption' => $useDb ? ($s['smtp_encryption'] ?: 'tls') : (string) config('mail.encryption', 'tls'),
            'username' => $useDb ? $s['smtp_username'] : (string) config('mail.username', ''),
            'password' => $password,
            'from' => $s['mail_from_address'] !== '' ? $s['mail_from_address'] : (string) config('mail.from'),
            'from_name' => $s['mail_from_name'] !== '' ? $s['mail_from_name'] : (string) config('mail.from_name'),
            'reply_to' => SettingsService::get('support_email') ?: null,
            'timeout' => (int) config('mail.timeout', 15),
            'source' => $useDb ? 'settings' : 'env',
        ];
    }

    /** Transport and sender for an email sent on behalf of an NGO. */
    public static function forTenant(int $tenantId): array
    {
        $platform = self::platform();
        $t = self::tenantRaw($tenantId);
        $tenant = Database::instance()->fetch('SELECT name FROM tenants WHERE id = ?', [$tenantId]);
        $display = TenantService::settings($tenantId)['display_name'] ?: ($tenant['name'] ?? '');
        $replyTo = $t['mail_reply_to'] ?: (TenantService::settings($tenantId)['notification_email'] ?: null);
        $ownSmtp = $t['mail_smtp_enabled'] === '1' && $t['mail_smtp_host'] !== ''
            && in_array('email_automation', SubscriptionService::enabledModules($tenantId), true);
        if (!$ownSmtp) {
            return array_merge($platform, [
                'from_name' => $t['mail_from_name'] ?: ($display !== '' ? $display : $platform['from_name']),
                'reply_to' => $replyTo,
                'source' => 'platform',
            ]);
        }
        $password = '';
        if ($t['mail_smtp_password_enc'] !== '') {
            try {
                $password = Crypto::decrypt($t['mail_smtp_password_enc']);
            } catch (\Throwable) {
            }
        }
        return [
            'driver' => 'smtp',
            'host' => $t['mail_smtp_host'],
            'port' => (int) ($t['mail_smtp_port'] ?: 587),
            'encryption' => $t['mail_smtp_encryption'] ?: 'tls',
            'username' => $t['mail_smtp_username'],
            'password' => $password,
            'from' => $t['mail_from_address'] ?: $platform['from'],
            'from_name' => $t['mail_from_name'] ?: $display,
            'reply_to' => $replyTo,
            'timeout' => (int) config('mail.timeout', 15),
            'source' => 'tenant',
        ];
    }

    /** NGO mail settings with defaults (password only as "is set"). */
    public static function tenantRaw(int $tenantId): array
    {
        $rows = Database::instance()->fetchAll(
            'SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ? AND (setting_key LIKE \'mail\\_%\' OR setting_key LIKE \'auto\\_%\')',
            [$tenantId]
        );
        return array_merge(self::TENANT_KEYS, array_intersect_key(array_column($rows, 'setting_value', 'setting_key'), self::TENANT_KEYS));
    }

    public static function automationOn(int $tenantId, string $key): bool
    {
        return in_array('email_automation', SubscriptionService::enabledModules($tenantId), true)
            && (self::tenantRaw($tenantId)[$key] ?? '0') === '1';
    }

    /** @param ?string $password null = keep the stored password, '' = clear it */
    public static function savePlatform(array $data, ?string $password, array $actor): void
    {
        $before = SettingsService::all();
        $changes = [];
        foreach (['mail_driver', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'mail_from_address', 'mail_from_name'] as $k) {
            $v = (string) ($data[$k] ?? '');
            if ($v !== (string) $before[$k]) {
                $changes[] = $k;
                SettingsService::set($k, $v, (int) $actor['id']);
            }
        }
        if ($password !== null) {
            SettingsService::set('smtp_password_enc', $password === '' ? '' : Crypto::encrypt($password), (int) $actor['id']);
            $changes[] = 'smtp_password';
        }
        if ($changes) {
            AuditLogger::log('email.settings_updated', 'system_settings', null, ['fields' => $changes], null, $actor);
        }
    }

    public static function saveTenant(int $tenantId, array $data, ?string $password, array $actor): void
    {
        $db = Database::instance();
        $before = self::tenantRaw($tenantId);
        $changes = [];
        foreach (self::TENANT_KEYS as $k => $default) {
            if ($k === 'mail_smtp_password_enc' || !array_key_exists($k, $data)) {
                continue;
            }
            $v = (string) $data[$k];
            if ($v === (string) $before[$k]) {
                continue;
            }
            $changes[] = $k;
            self::putTenant($db, $tenantId, $k, $v, (int) $actor['id']);
        }
        if ($password !== null) {
            if ($password !== '' && !Crypto::available()) {
                throw new DomainException('The SMTP password cannot be stored because encryption (APP_KEY) is not configured on the server. Ask Fameweb to set it.');
            }
            self::putTenant($db, $tenantId, 'mail_smtp_password_enc', $password === '' ? '' : Crypto::encrypt($password), (int) $actor['id']);
            $changes[] = 'mail_smtp_password';
        }
        if ($changes) {
            AuditLogger::log('email.settings_updated', 'tenant', $tenantId, ['fields' => $changes], $tenantId, $actor);
        }
    }

    private static function putTenant(Database $db, int $tenantId, string $key, string $value, int $userId): void
    {
        $db->execute(
            'INSERT INTO tenant_settings (tenant_id, setting_key, setting_value, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            [$tenantId, $key, $value, $userId]
        );
    }
}
