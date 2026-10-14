<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authenticated-user resolution. The session stores only the user id and a session version;
 * the user's type, tenant, status and role are re-read from the database on every request,
 * so editing a cookie, form field or URL can never grant platform (Fameweb) privileges.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        $auth = $_SESSION['_auth'] ?? null;
        if (!is_array($auth) || !isset($auth['uid'], $auth['ver'])) {
            return null;
        }
        $user = Database::instance()->fetch(
            'SELECT u.*, r.id AS role_id, r.slug AS role_slug, r.name AS role_name
               FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.is_primary = 1
               LEFT JOIN roles r ON r.id = ur.role_id
              WHERE u.id = :id
              LIMIT 1',
            ['id' => (int) $auth['uid']]
        );
        if ($user === null
            || $user['status'] !== 'active'
            || (int) $user['session_version'] !== (int) $auth['ver']
            || ($user['user_type'] === 'platform') !== ($user['tenant_id'] === null)) {
            // Deactivated, password changed elsewhere, or inconsistent: drop the session.
            unset($_SESSION['_auth']);
            return null;
        }
        // Role must belong to the same tenant as the user (defence in depth).
        if ($user['role_id'] !== null) {
            $roleTenant = Database::instance()->fetchColumn('SELECT tenant_id FROM roles WHERE id = ?', [(int) $user['role_id']]);
            if (($roleTenant === null ? null : (int) $roleTenant) !== ($user['tenant_id'] === null ? null : (int) $user['tenant_id'])) {
                $user['role_id'] = $user['role_slug'] = $user['role_name'] = null;
            }
        }
        unset($user['password_hash']);
        self::$user = $user;
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function isPlatform(): bool
    {
        $u = self::user();
        return $u !== null && $u['user_type'] === 'platform' && $u['tenant_id'] === null;
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        $_SESSION['_auth'] = [
            'uid' => (int) $user['id'],
            'ver' => (int) $user['session_version'],
            'at'  => time(),
        ];
        $_SESSION['_last_activity'] = time();
        self::$resolved = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$resolved = true;
        self::$user = null;
    }

    /** Forget the cached user (after the current user's own record changed). */
    public static function refresh(): void
    {
        self::$resolved = false;
        self::$user = null;
    }

    public static function homePath(?array $user = null): string
    {
        $user ??= self::user();
        if ($user === null) {
            return '/login';
        }
        return $user['user_type'] === 'platform' ? '/admin' : '/ngo';
    }
}
