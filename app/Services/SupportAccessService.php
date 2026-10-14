<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Exceptions\DomainException;

/**
 * Fameweb support access to an NGO:
 *  - password resets for NGO users (a single-use link, or a temporary password the user must
 *    change at the next sign-in; a password is never emailed), and
 *  - audited "sign in as the NGO Super Admin" sessions that never use the NGO user's password.
 *
 * While a support session is active the session keeps the Fameweb admin's own id and session
 * version under "_impersonator"; returning restores that admin without a new sign-in. Every
 * start, end and action is written to audit_logs with the NGO's tenant id, so it shows in the
 * NGO's own audit log and in the Fameweb audit log.
 */
final class SupportAccessService
{
    /** Support sessions end automatically after this many minutes. */
    public const MAX_MINUTES = 60;

    // ------------------------------------------------------------------ password reset

    /** @return array{user: array, tenant: array} */
    private static function tenantUser(int $tenantId, int $userId): array
    {
        $db = Database::instance();
        $tenant = $db->fetch('SELECT * FROM tenants WHERE id = ?', [$tenantId]) ?? throw new DomainException('NGO not found.');
        $user = $db->fetch("SELECT * FROM users WHERE id = ? AND tenant_id = ? AND user_type = 'tenant'", [$userId, $tenantId])
            ?? throw new DomainException('That user does not belong to this NGO.');
        return ['user' => $user, 'tenant' => $tenant];
    }

    /** Issue a single-use reset (or, for an invited user, invitation) link and email it. Returns the link. */
    public static function issueResetLink(array $admin, int $tenantId, int $userId): string
    {
        ['user' => $user, 'tenant' => $tenant] = self::tenantUser($tenantId, $userId);
        if ($user['status'] === 'inactive') {
            throw new DomainException('This user is deactivated. The NGO must reactivate them first.');
        }
        $purpose = $user['status'] === 'invited' ? 'invite' : 'reset';
        $ttl = $purpose === 'invite' ? (SettingsService::int('invite_ttl_hours') ?: 72) * 3600 : (SettingsService::int('reset_ttl_minutes') ?: 60) * 60;
        $token = TokenService::issue((int) $user['id'], $purpose, $ttl, (int) $admin['id'], Request::current()?->ip());
        $link = absolute_url(($purpose === 'invite' ? 'invite/' : 'reset-password/') . $token);
        Mailer::send($user['email'], $purpose === 'invite' ? 'Your invitation' : 'Reset your password', sprintf(
            "Hello %s,\n\nFameweb support sent you a single-use link to %s your %s account:\n\n%s\n\n"
            . "Earlier links no longer work. If you did not ask for this, please contact your organization or Fameweb support.\n",
            $user['name'], $purpose === 'invite' ? 'activate' : 'set a new password for', $tenant['name'], $link
        ), $tenantId, 'support-link:' . $user['id'] . ':' . substr(hash('sha256', $token), 0, 16));
        AuditLogger::log('support.reset_link_issued', 'user', $user['id'], ['user' => $user['email'], 'purpose' => $purpose], $tenantId, $admin);
        return $link;
    }

    /**
     * Replace the user's password with a random temporary one that must be changed at the next
     * sign-in. All of the user's sessions and outstanding links end. The temporary password is
     * returned once for the admin to pass on over a trusted channel; it is not stored or emailed.
     */
    public static function setTemporaryPassword(array $admin, int $tenantId, int $userId): string
    {
        ['user' => $user, 'tenant' => $tenant] = self::tenantUser($tenantId, $userId);
        if ($user['status'] !== 'active') {
            throw new DomainException($user['status'] === 'invited'
                ? 'This user has not accepted their invitation yet. Send a new invitation link instead.'
                : 'This user is deactivated. The NGO must reactivate them first.');
        }
        $temp = self::generateTemporaryPassword();
        PasswordService::setPassword((int) $user['id'], $temp);
        Database::instance()->execute('UPDATE users SET must_change_password = 1 WHERE id = ?', [(int) $user['id']]);
        TokenService::revokeAll((int) $user['id']);
        // Tell the user it happened, without the password itself.
        Mailer::send($user['email'], 'Your password was reset', sprintf(
            "Hello %s,\n\nFameweb support reset the password of your %s account on %s. You have been signed out everywhere.\n\n"
            . "Fameweb will give you a temporary password over a trusted channel. You will be asked to choose a new password when you sign in.\n\n"
            . "If you did not ask for this, please contact your organization or Fameweb support.\n",
            $user['name'], $tenant['name'], date('d M Y H:i')
        ), $tenantId, 'support-temp:' . $user['id'] . ':' . bin2hex(random_bytes(6)));
        AuditLogger::log('support.temp_password_set', 'user', $user['id'], ['user' => $user['email']], $tenantId, $admin);
        return $temp;
    }

    /** 14 characters from an unambiguous alphabet, always with letters and digits. */
    public static function generateTemporaryPassword(): string
    {
        $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $all = $letters . $digits;
        do {
            $pw = '';
            for ($i = 0; $i < 14; $i++) {
                $pw .= $all[random_int(0, strlen($all) - 1)];
            }
        } while (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw));
        return $pw;
    }

    // ------------------------------------------------------------------ support sessions

    public static function active(): ?array
    {
        $imp = $_SESSION['_impersonator'] ?? null;
        return is_array($imp) && isset($imp['uid'], $imp['ver'], $imp['tenant_id']) ? $imp : null;
    }

    /** The NGO's active Super Admin that a support session signs in as (lowest id first). */
    public static function superAdminOf(int $tenantId): ?array
    {
        return Database::instance()->fetch(
            "SELECT u.* FROM users u
               JOIN user_roles ur ON ur.user_id = u.id AND ur.is_primary = 1
               JOIN roles r ON r.id = ur.role_id AND r.tenant_id = u.tenant_id AND r.slug = 'ngo_super_admin'
              WHERE u.tenant_id = ? AND u.user_type = 'tenant' AND u.status = 'active'
              ORDER BY u.id LIMIT 1",
            [$tenantId]
        );
    }

    public static function start(array $admin, int $tenantId, string $reason): array
    {
        if (self::active()) {
            throw new DomainException('You are already in a support session. Return to Fameweb first.');
        }
        if ($admin['user_type'] !== 'platform' || $admin['tenant_id'] !== null) {
            throw new DomainException('Only Fameweb administrators can start a support session.');
        }
        $reason = trim(mb_substr($reason, 0, 255));
        if ($reason === '') {
            throw new DomainException('Please give a reason for signing in to this NGO.');
        }
        $tenant = Database::instance()->fetch('SELECT * FROM tenants WHERE id = ?', [$tenantId]) ?? throw new DomainException('NGO not found.');
        if ($tenant['status'] !== 'active') {
            throw new DomainException('This NGO is ' . $tenant['status'] . '. Activate it before signing in to it.');
        }
        $target = self::superAdminOf($tenantId)
            ?? throw new DomainException('This NGO has no active Super Admin to sign in as. Send an onboarding link first.');
        AuditLogger::log('support.session_started', 'user', $target['id'], [
            'reason' => $reason, 'signed_in_as' => $target['email'], 'fameweb_admin' => $admin['email'],
        ], $tenantId, $admin);
        $adminVer = (int) Database::instance()->fetchColumn('SELECT session_version FROM users WHERE id = ?', [(int) $admin['id']]);
        Auth::login($target);
        $_SESSION['_impersonator'] = [
            'uid' => (int) $admin['id'],
            'ver' => $adminVer,
            'name' => $admin['name'],
            'tenant_id' => $tenantId,
            'target_uid' => (int) $target['id'],
            'started' => time(),
        ];
        return ['tenant' => $tenant, 'user' => $target];
    }

    /**
     * End the support session: audit it and restore the Fameweb admin's own session if it is
     * still valid (otherwise sign out). Returns the admin user restored, or null.
     */
    public static function stop(string $how = 'returned'): ?array
    {
        $imp = self::active();
        if ($imp === null) {
            return null;
        }
        $db = Database::instance();
        $admin = $db->fetch('SELECT * FROM users WHERE id = ?', [(int) $imp['uid']]);
        $target = Auth::user();
        AuditLogger::log('support.session_ended', 'user', $imp['target_uid'], [
            'how' => $how, 'minutes' => (int) ceil((time() - (int) $imp['started']) / 60),
            'signed_in_as' => $target['email'] ?? null, 'fameweb_admin' => $admin['email'] ?? null,
        ], (int) $imp['tenant_id'], $admin ?: []);
        unset($_SESSION['_impersonator']);
        if ($admin && $admin['status'] === 'active' && $admin['user_type'] === 'platform'
            && (int) $admin['session_version'] === (int) $imp['ver']) {
            Auth::login($admin);
            return $admin;
        }
        Auth::logout();
        return null;
    }

    /**
     * Called on every signed-in request. Ends a support session that has run too long, whose
     * Fameweb admin was deactivated, signed out elsewhere or lost the permission, or whose
     * NGO user changed. Returns a message for the user when the session was ended.
     */
    public static function enforce(): ?string
    {
        $imp = self::active();
        if ($imp === null) {
            return null;
        }
        $admin = Database::instance()->fetch(
            'SELECT u.*, r.id AS role_id, r.slug AS role_slug FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.is_primary = 1
               LEFT JOIN roles r ON r.id = ur.role_id
              WHERE u.id = ?',
            [(int) $imp['uid']]
        );
        $valid = $admin && $admin['status'] === 'active' && $admin['user_type'] === 'platform' && $admin['tenant_id'] === null
            && (int) $admin['session_version'] === (int) $imp['ver']
            && PermissionService::instance()->userHas($admin, 'tenants.impersonate')
            && Auth::id() === (int) $imp['target_uid'];
        if (!$valid) {
            // stop() returns to the Fameweb account only if it is itself still signed in and active.
            self::stop('revoked');
            return 'The support session has ended because the NGO user or your Fameweb access changed.';
        }
        if (time() - (int) $imp['started'] > self::MAX_MINUTES * 60) {
            self::stop('expired');
            return 'The support session ended automatically after ' . self::MAX_MINUTES . ' minutes.';
        }
        return null;
    }

    /** Audit metadata added to every action taken during a support session. */
    public static function auditContext(): array
    {
        $imp = self::active();
        return $imp ? ['support_session_by' => (int) $imp['uid']] : [];
    }
}
