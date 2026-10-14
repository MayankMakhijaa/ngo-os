<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;

final class AuthService
{
    /** Used when the email is unknown so the response time does not reveal which emails exist. */
    private const DUMMY_HASH = '$2y$10$DOS65wUdQsCSxd2VPT3ZquMYhr0hCjJampmFK3H94b29wnysds7lG';

    /** @return array{ok: bool, error?: string, user?: array} */
    public static function attempt(string $email, string $password, Request $request): array
    {
        $email = mb_strtolower(trim($email));
        $ip = $request->ip();
        $db = Database::instance();

        $wait = LoginThrottle::lockedFor($email, $ip);
        if ($wait > 0) {
            AuditLogger::log('auth.login_locked', 'user', null, ['email' => $email], null, []);
            return ['ok' => false, 'error' => sprintf(
                'Too many failed sign-in attempts. For your security, sign-in is locked for %d more minute(s).',
                (int) ceil($wait / 60)
            )];
        }

        $user = $db->fetch('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        $valid = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);

        if (!$user || !$user['password_hash'] || !$valid) {
            LoginThrottle::record($email, $ip, false, $user ? (int) $user['id'] : null);
            AuditLogger::log('auth.login_failed', 'user', $user['id'] ?? null, ['email' => $email],
                $user && $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null, []);
            return ['ok' => false, 'error' => 'The email or password is incorrect.'];
        }

        if ($user['status'] === 'invited') {
            return ['ok' => false, 'error' => 'Your account is not activated yet. Use the invitation link you received to set your password.'];
        }
        if ($user['status'] !== 'active') {
            AuditLogger::log('auth.login_blocked', 'user', $user['id'], ['reason' => 'user_inactive'],
                $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null, []);
            return ['ok' => false, 'error' => 'Your account has been deactivated. Please contact your administrator.'];
        }
        if ($user['user_type'] === 'tenant') {
            $tenant = $db->fetch('SELECT status FROM tenants WHERE id = ?', [(int) $user['tenant_id']]);
            if (!$tenant || $tenant['status'] !== 'active') {
                AuditLogger::log('auth.login_blocked', 'user', $user['id'], ['reason' => 'tenant_' . ($tenant['status'] ?? 'missing')],
                    (int) $user['tenant_id'], []);
                return ['ok' => false, 'error' => $tenant && $tenant['status'] === 'suspended'
                    ? 'Your organization\'s account is suspended. Please contact Fameweb support.'
                    : 'Your organization\'s account is not active. Please contact Fameweb support.'];
            }
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [PasswordService::hash($password), (int) $user['id']]);
        }
        $db->execute('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [$ip, (int) $user['id']]);
        LoginThrottle::record($email, $ip, true, (int) $user['id']);
        Auth::login($user);
        $fresh = Auth::user();
        AuditLogger::log('auth.login', 'user', $user['id'], [], $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null, $fresh);
        return ['ok' => true, 'user' => $fresh];
    }

    public static function logout(): void
    {
        $user = Auth::user();
        if ($user) {
            AuditLogger::log('auth.logout', 'user', $user['id'], [], $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null, $user);
        }
        Auth::logout();
    }

    /**
     * Start a password reset. Always behaves the same whether or not the email exists, so the
     * form cannot be used to discover registered addresses.
     */
    public static function requestReset(string $email, Request $request): void
    {
        $email = mb_strtolower(trim($email));
        $ip = $request->ip();
        if (LoginThrottle::resetRequestsExceeded($email, $ip)) {
            AuditLogger::log('auth.reset_throttled', 'user', null, ['email' => $email], null, []);
            return;
        }
        LoginThrottle::record($email, $ip, false, null, 'password_reset');
        if (SettingsService::get('allow_password_reset') !== '1') {
            return;
        }
        $user = Database::instance()->fetch("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
        if (!$user) {
            return;
        }
        if ($user['user_type'] === 'tenant') {
            $status = Database::instance()->fetchColumn('SELECT status FROM tenants WHERE id = ?', [(int) $user['tenant_id']]);
            if ($status !== 'active') {
                return;
            }
        }
        $ttl = SettingsService::int('reset_ttl_minutes') ?: 60;
        $token = TokenService::issue((int) $user['id'], 'reset', $ttl * 60, null, $ip);
        $link = absolute_url('reset-password/' . $token);
        $tenantId = $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null;
        Mailer::send($user['email'], 'Reset your password', sprintf(
            "Hello %s,\n\nWe received a request to reset your password. Use this link within %d minutes:\n\n%s\n\n"
            . "If you did not request this, you can ignore this email. Your password will not change.\n",
            $user['name'], $ttl, $link
        ), $tenantId);
        AuditLogger::log('auth.reset_requested', 'user', $user['id'], [], $tenantId, []);
    }

    /** Complete a reset or an invitation. Returns error strings (empty on success). */
    public static function completeToken(string $token, string $purpose, string $password, string $confirmation): array
    {
        $row = TokenService::find($token, $purpose);
        if (!$row) {
            return ['This link is invalid or has expired. Please request a new one.'];
        }
        $errors = PasswordService::validate($password, $row['email'], $confirmation);
        if ($errors) {
            return $errors;
        }
        $db = Database::instance();
        $result = $db->transaction(function () use ($row, $password, $purpose) {
            if (!TokenService::consume((int) $row['token_id'])) {
                return ['This link has already been used.'];
            }
            if ($purpose === 'reset' && $row['status'] !== 'active') {
                return ['This account is not active.'];
            }
            if ($purpose === 'invite' && $row['status'] !== 'invited') {
                return ['This invitation is no longer valid.'];
            }
            PasswordService::setPassword((int) $row['user_id'], $password);
            TokenService::revokeAll((int) $row['user_id']);
            $actor = Database::instance()->fetch(
                'SELECT u.*, r.slug AS role_slug FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.is_primary = 1
                   LEFT JOIN roles r ON r.id = ur.role_id WHERE u.id = ?',
                [(int) $row['user_id']]
            );
            AuditLogger::log($purpose === 'invite' ? 'auth.invite_accepted' : 'auth.password_reset', 'user', $row['user_id'], [],
                $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null, $actor ?: []);
            return [];
        });
        return $result;
    }
}
