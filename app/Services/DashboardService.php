<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Dashboard figures, all computed from live database rows. */
final class DashboardService
{
    public static function platform(): array
    {
        $db = Database::instance();
        $tenants = $db->fetch(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'active') AS active,
                    SUM(status = 'suspended') AS suspended,
                    SUM(status = 'deactivated') AS deactivated
               FROM tenants"
        );
        $subs = $db->fetch(
            "SELECT SUM(status = 'trial') AS trial, SUM(status = 'active') AS active, SUM(status = 'past_due') AS past_due,
                    SUM(status = 'expired') AS expired, SUM(status = 'suspended') AS suspended, SUM(status = 'cancelled') AS cancelled
               FROM subscriptions WHERE is_current = 1"
        );
        $revenue = $db->fetch(
            "SELECT COALESCE(SUM(amount),0) AS total,
                    COALESCE(SUM(CASE WHEN paid_at >= ? THEN amount ELSE 0 END),0) AS this_month,
                    COUNT(*) AS payments
               FROM subscription_payments WHERE status = 'paid'",
            [date('Y-m-01 00:00:00')]
        );
        $monthly = $db->fetchAll(
            "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym, SUM(amount) AS total FROM subscription_payments
              WHERE status = 'paid' AND paid_at >= ? GROUP BY ym ORDER BY ym",
            [date('Y-m-01 00:00:00', strtotime('first day of -11 months'))]
        );
        $series = [];
        for ($i = 11; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("first day of -$i months"));
            $series[$ym] = 0.0;
        }
        foreach ($monthly as $m) {
            if (isset($series[$m['ym']])) {
                $series[$m['ym']] = (float) $m['total'];
            }
        }
        return [
            'tenants' => array_map('intval', $tenants),
            'subscriptions' => array_map('intval', $subs ?: []),
            'ngo_users' => (int) $db->fetchColumn("SELECT COUNT(*) FROM users WHERE user_type = 'tenant' AND status IN ('active','invited')"),
            'storage_bytes' => (int) $db->fetchColumn('SELECT COALESCE(SUM(size_bytes),0) FROM storage_objects WHERE deleted_at IS NULL'),
            'revenue' => $revenue,
            'revenue_series' => $series,
            'mrr' => SubscriptionService::mrr(),
            'currency' => (string) config('app.currency', 'INR'),
            'recent_tenants' => $db->fetchAll(
                'SELECT t.id, t.name, t.reference_code, t.status, t.created_at, p.name AS plan_name, s.status AS sub_status
                   FROM tenants t
                   LEFT JOIN subscriptions s ON s.tenant_id = t.id AND s.is_current = 1
                   LEFT JOIN subscription_plans p ON p.id = s.plan_id
                  ORDER BY t.created_at DESC, t.id DESC LIMIT 6'
            ),
            'expiring' => $db->fetchAll(
                "SELECT s.id, s.tenant_id, s.status, s.end_date, s.trial_ends_at, t.name AS tenant_name, p.name AS plan_name
                   FROM subscriptions s JOIN tenants t ON t.id = s.tenant_id JOIN subscription_plans p ON p.id = s.plan_id
                  WHERE s.is_current = 1 AND s.status IN ('trial','active','past_due')
                    AND s.end_date IS NOT NULL AND s.end_date <= ?
                  ORDER BY s.end_date ASC LIMIT 8",
                [date('Y-m-d', strtotime('+30 days'))]
            ),
            'activity' => $db->fetchAll(
                'SELECT a.*, u.name AS actor_name, t.name AS tenant_name FROM audit_logs a
                   LEFT JOIN users u ON u.id = a.actor_user_id LEFT JOIN tenants t ON t.id = a.tenant_id
                  ORDER BY a.id DESC LIMIT 10'
            ),
        ];
    }

    public static function tenant(int $tenantId): array
    {
        $db = Database::instance();
        $users = $db->fetch(
            "SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status = 'invited') AS invited, SUM(status = 'inactive') AS inactive
               FROM users WHERE tenant_id = ?",
            [$tenantId]
        );
        return [
            'users' => array_map('intval', $users),
            'roles' => (int) $db->fetchColumn('SELECT COUNT(*) FROM roles WHERE tenant_id = ?', [$tenantId]),
            'recent_logins' => $db->fetchAll(
                'SELECT u.id, u.name, u.last_login_at, r.name AS role_name FROM users u
                   LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.is_primary = 1
                   LEFT JOIN roles r ON r.id = ur.role_id
                  WHERE u.tenant_id = ? AND u.last_login_at IS NOT NULL ORDER BY u.last_login_at DESC LIMIT 5',
                [$tenantId]
            ),
            'activity' => $db->fetchAll(
                'SELECT a.*, u.name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
                  WHERE a.tenant_id = ? AND a.actor_type <> \'platform\' ORDER BY a.id DESC LIMIT 8',
                [$tenantId]
            ),
        ];
    }

    /** Human readable description of an audit action. */
    public static function describe(string $action): string
    {
        $map = [
            'auth.login' => 'Signed in', 'auth.logout' => 'Signed out', 'auth.login_failed' => 'Failed sign-in attempt',
            'auth.login_locked' => 'Sign-in locked (too many attempts)', 'auth.login_blocked' => 'Sign-in blocked',
            'auth.password_reset' => 'Reset password', 'auth.reset_requested' => 'Requested password reset',
            'auth.invite_accepted' => 'Accepted invitation', 'auth.password_changed' => 'Changed password',
            'tenant.created' => 'Registered NGO', 'tenant.updated' => 'Updated NGO profile', 'tenant.activated' => 'Activated NGO',
            'tenant.suspended' => 'Suspended NGO', 'tenant.deactivated' => 'Deactivated NGO', 'tenant.viewed' => 'Viewed NGO details',
            'tenant.settings_updated' => 'Updated organization settings',
            'subscription.assigned' => 'Assigned subscription', 'subscription.changed' => 'Changed plan',
            'subscription.updated' => 'Updated subscription', 'subscription.renewed' => 'Renewed subscription',
            'subscription.status_auto' => 'Subscription status changed automatically', 'payment.recorded' => 'Recorded payment',
            'plan.created' => 'Created plan', 'plan.updated' => 'Updated plan', 'plan.activated' => 'Activated plan', 'plan.deactivated' => 'Deactivated plan',
            'user.created' => 'Created user', 'user.updated' => 'Updated user', 'user.role_changed' => 'Changed user role',
            'user.deactivated' => 'Deactivated user', 'user.reactivated' => 'Reactivated user',
            'user.permissions_changed' => 'Changed user permissions', 'user.invite_reissued' => 'Sent new invitation',
            'user.reset_link_issued' => 'Sent password reset link',
            'support.reset_link_issued' => 'Fameweb sent a password reset link', 'support.temp_password_set' => 'Fameweb set a temporary password',
            'support.session_started' => 'Fameweb support session started', 'support.session_ended' => 'Fameweb support session ended',
            'role.created' => 'Created role', 'role.updated' => 'Updated role', 'role.deleted' => 'Deleted role',
            'settings.updated' => 'Changed platform settings', 'settings.security_updated' => 'Changed security settings',
            'system.installed' => 'Installed application', 'system.migrated' => 'Applied database upgrade',
            'authz.denied' => 'Access denied', 'file.uploaded' => 'Uploaded file', 'file.deleted' => 'Deleted file',
            'audit.exported' => 'Exported audit log', 'users.exported' => 'Exported users', 'audit.verified' => 'Verified audit log integrity',
            'admin.created' => 'Created Fameweb admin', 'admin.updated' => 'Updated Fameweb admin',
        ];
        return $map[$action] ?? humanize($action);
    }
}
