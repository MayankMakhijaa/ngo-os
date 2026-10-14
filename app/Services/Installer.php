<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\DomainException;

/**
 * One-time installation: runs migrations, seeds roles/permissions and settings, and creates
 * the first Fameweb Super Admin with a password chosen by the installer (no default
 * credentials exist anywhere). Guarded by the INSTALL_TOKEN from .env and by a lock file;
 * it also refuses to run if any platform user already exists.
 */
final class Installer
{
    public static function lockFile(): string
    {
        return storage_path('installed.lock');
    }

    public static function isInstalled(): bool
    {
        return is_file(self::lockFile());
    }

    /** Environment checks shown on the installer page. */
    public static function requirements(): array
    {
        $checks = [
            'PHP 8.2 or newer' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'PDO MySQL extension' => extension_loaded('pdo_mysql'),
            'mbstring extension' => extension_loaded('mbstring'),
            'fileinfo extension' => extension_loaded('fileinfo'),
            'openssl extension' => extension_loaded('openssl'),
            '.env file present' => is_file(getenv('APP_ENV_FILE') ?: BASE_PATH . '/.env'),
            'storage/ is writable' => is_writable(storage_path()),
            'storage/private is writable' => is_writable(storage_path('private')),
            'storage/logs is writable' => is_writable(storage_path('logs')),
            'storage/sessions is writable' => is_writable(storage_path('sessions')),
            'INSTALL_TOKEN set in .env' => strlen((string) config('app.install_token')) >= 16,
        ];
        return $checks;
    }

    public static function databaseReachable(): ?string
    {
        try {
            Database::instance()->fetchColumn('SELECT 1');
            return null;
        } catch (\Throwable $e) {
            return 'Could not connect to the database with the credentials in .env. Check DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD.';
        }
    }

    /** @param array{name:string,email:string,password:string} $admin */
    public static function install(array $admin): int
    {
        if (self::isInstalled()) {
            throw new DomainException('The application is already installed.');
        }
        Migrator::ensureTable();
        Migrator::migrate();
        RbacSeeder::sync();
        SettingsService::seedDefaults();

        $db = Database::instance();
        if ((int) $db->fetchColumn("SELECT COUNT(*) FROM users WHERE user_type = 'platform'") > 0) {
            throw new DomainException('A Fameweb administrator already exists. Installation is locked.');
        }
        $userId = $db->transaction(function (Database $db) use ($admin) {
            $id = $db->insert('users', [
                'tenant_id' => null,
                'user_type' => 'platform',
                'name' => $admin['name'],
                'email' => mb_strtolower($admin['email']),
                'password_hash' => PasswordService::hash($admin['password']),
                'status' => 'active',
                'password_changed_at' => date('Y-m-d H:i:s'),
            ]);
            $roleId = (int) $db->fetchColumn("SELECT id FROM roles WHERE tenant_id IS NULL AND slug = 'fameweb_super_admin'");
            $db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId, 'is_primary' => 1]);
            AuditLogger::log('system.installed', 'user', $id, ['admin_email' => mb_strtolower($admin['email'])], null, []);
            return $id;
        });
        if (@file_put_contents(self::lockFile(), 'installed ' . date('c') . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write storage/installed.lock');
        }
        return $userId;
    }
}
