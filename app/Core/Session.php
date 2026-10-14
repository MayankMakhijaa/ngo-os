<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Hardened PHP session handling: strict mode, HttpOnly + SameSite cookies, Secure on HTTPS,
 * private save path, idle and absolute timeouts, and one-request flash messages.
 */
final class Session
{
    private static array $flash = [];
    private static bool $started = false;

    public static function start(Request $request): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        $cfg = (array) config('security.session');
        $secureSetting = strtolower((string) ($cfg['secure_cookie'] ?? 'auto'));
        $secure = $secureSetting === 'auto' ? $request->isHttps() : in_array($secureSetting, ['1', 'true', 'yes'], true);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string) max(1440, ((int) ($cfg['absolute_minutes'] ?? 720)) * 60));
        $savePath = (string) ($cfg['save_path'] ?? '');
        if ($savePath !== '' && is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }
        session_name((string) ($cfg['name'] ?? 'fwngo_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => base_path_url() === '' ? '/' : base_path_url() . '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => (string) ($cfg['same_site'] ?? 'Lax'),
        ]);
        session_start();
        self::$started = true;

        // Rotate flash data: what was flashed on the previous request is readable now.
        self::$flash = $_SESSION['_flash_next'] ?? [];
        unset($_SESSION['_flash_next']);

        self::enforceTimeouts($cfg);
    }

    private static function enforceTimeouts(array $cfg): void
    {
        $now = time();
        if (isset($_SESSION['_auth'])) {
            $idle = ((int) ($cfg['idle_minutes'] ?? 30)) * 60;
            $absolute = ((int) ($cfg['absolute_minutes'] ?? 720)) * 60;
            $last = (int) ($_SESSION['_last_activity'] ?? $now);
            $created = (int) ($_SESSION['_auth']['at'] ?? $now);
            if (($now - $last) > $idle || ($now - $created) > $absolute) {
                $uid = $_SESSION['_auth']['uid'] ?? null;
                $_SESSION = [];
                session_regenerate_id(true);
                $_SESSION['_expired_uid'] = $uid;
                self::flash('warning', 'Your session expired. Please sign in again.');
            }
        }
        $_SESSION['_last_activity'] = $now;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $v = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $v;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
                'httponly' => true, 'samesite' => $p['samesite'] ?: 'Lax',
            ]);
            session_destroy();
            // Fresh empty session so flash messages survive the redirect to the login page.
            session_start();
            session_regenerate_id(true);
        }
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash_next'][$key] = $value;
        self::$flash[$key] = $value; // also visible if the same request renders a view
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return self::$flash[$key] ?? $default;
    }

    /** Flash messages for toasts: success, error, warning, info. */
    public static function messages(): array
    {
        $out = [];
        foreach (['success', 'error', 'warning', 'info'] as $type) {
            if (!empty(self::$flash[$type])) {
                $out[] = ['type' => $type, 'text' => (string) self::$flash[$type]];
            }
        }
        return $out;
    }
}
