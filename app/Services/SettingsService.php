<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Platform-wide settings stored in system_settings, with safe defaults. */
final class SettingsService
{
    public const DEFAULTS = [
        'platform_name'           => 'Fameweb NGO Cloud',
        'support_email'           => '',
        'default_trial_days'      => '14',
        'subscription_grace_days' => '7',
        'usage_warning_percent'   => '80',
        'invite_ttl_hours'        => '72',
        'reset_ttl_minutes'       => '60',
        'allow_password_reset'    => '1',
    ];

    /** Settings that affect security; changes are audited as security events. */
    public const SECURITY_KEYS = ['invite_ttl_hours', 'reset_ttl_minutes', 'allow_password_reset'];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = Database::instance()->fetchAll('SELECT setting_key, setting_value FROM system_settings');
            $stored = array_column($rows, 'setting_value', 'setting_key');
            self::$cache = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        }
        return self::$cache;
    }

    public static function get(string $key): string
    {
        return (string) (self::all()[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function set(string $key, string $value, ?int $userId): void
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown setting');
        }
        Database::instance()->execute(
            'INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            [$key, $value, $userId]
        );
        self::$cache = null;
    }

    public static function seedDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Database::instance()->execute(
                'INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)',
                [$k, $v]
            );
        }
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
