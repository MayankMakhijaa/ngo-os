<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\LimitExceededException;

/**
 * Tenant usage, always computed on the server from real data:
 *  - users     : NGO user rows (active + invited) — inactive users do not count
 *  - storage   : SUM of size_bytes of storage_objects that are not deleted
 *  - documents : storage_objects in the "document" category that are not deleted
 *  - members   : members + volunteers + interns that are pending or active (inactive and
 *                completed records are kept but do not count)
 *  - invoices  : 80G donation receipts issued (cancelled ones included: the number was used)
 *  - emails, certificates : metered events in usage_records (idempotent)
 */
final class UsageService
{
    public const METRICS = [
        'users'        => ['label' => 'NGO users',                 'limit' => 'max_users',        'available' => true,  'kind' => 'count'],
        'members'      => ['label' => 'Members, volunteers & interns', 'limit' => 'max_members',  'available' => true,  'kind' => 'count'],
        'storage'      => ['label' => 'Storage',                   'limit' => 'max_storage_mb',   'available' => true,  'kind' => 'bytes'],
        'documents'    => ['label' => 'Uploaded documents',        'limit' => null,               'available' => true,  'kind' => 'count'],
        'certificates' => ['label' => 'Certificates issued',       'limit' => 'max_certificates', 'available' => true,  'kind' => 'count'],
        'invoices'     => ['label' => '80G receipts issued',       'limit' => null,               'available' => true,  'kind' => 'count'],
        'emails'       => ['label' => 'Emails sent (this month)',  'limit' => null,               'available' => true,  'kind' => 'count'],
    ];

    public static function used(int $tenantId, string $metric): int
    {
        $db = Database::instance();
        return (int) match ($metric) {
            'users' => $db->fetchColumn("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND status IN ('active','invited')", [$tenantId]),
            'storage' => $db->fetchColumn('SELECT COALESCE(SUM(size_bytes),0) FROM storage_objects WHERE tenant_id = ? AND deleted_at IS NULL', [$tenantId]),
            'documents' => $db->fetchColumn("SELECT COUNT(*) FROM storage_objects WHERE tenant_id = ? AND deleted_at IS NULL AND category = 'document'", [$tenantId]),
            'members' => $db->fetchColumn("SELECT COUNT(*) FROM people WHERE tenant_id = ? AND status IN ('pending','active')", [$tenantId]),
            'invoices' => $db->fetchColumn('SELECT COUNT(*) FROM donation_receipts WHERE tenant_id = ?', [$tenantId]),
            'emails' => $db->fetchColumn(
                "SELECT COALESCE(SUM(quantity),0) FROM usage_records WHERE tenant_id = ? AND metric = 'emails' AND occurred_at >= ?",
                [$tenantId, date('Y-m-01 00:00:00')]
            ),
            'certificates' => $db->fetchColumn(
                'SELECT COALESCE(SUM(quantity),0) FROM usage_records WHERE tenant_id = ? AND metric = ?',
                [$tenantId, $metric]
            ),
            default => throw new \InvalidArgumentException('Unknown metric'),
        };
    }

    /** Plan limit in base units (bytes for storage) or null for unlimited / no subscription limit. */
    public static function limit(?array $subscription, string $metric): ?int
    {
        $col = self::METRICS[$metric]['limit'] ?? null;
        if ($col === null || $subscription === null || $subscription[$col] === null) {
            return null;
        }
        $v = (int) $subscription[$col];
        return $metric === 'storage' ? $v * 1024 * 1024 : $v;
    }

    public static function summary(int $tenantId, ?array $subscription = null): array
    {
        $subscription ??= SubscriptionService::current($tenantId);
        $warn = SettingsService::int('usage_warning_percent') ?: 80;
        $out = [];
        foreach (self::METRICS as $key => $m) {
            $used = self::used($tenantId, $key);
            $limit = self::limit($subscription, $key);
            $percent = ($limit !== null && $limit > 0) ? min(100, round($used / $limit * 100, 1)) : null;
            if ($limit === 0) {
                $percent = $used > 0 ? 100.0 : 0.0;
            }
            $state = match (true) {
                !$m['available'] => 'unavailable',
                $limit === null => 'unlimited',
                $used >= $limit => 'reached',
                $percent !== null && $percent >= $warn => 'warning',
                default => 'ok',
            };
            $fmt = static fn ($v) => $m['kind'] === 'bytes' ? format_bytes($v) : number_format((int) $v);
            $out[$key] = [
                'key' => $key,
                'label' => $m['label'],
                'available' => $m['available'],
                'used' => $used,
                'limit' => $limit,
                'percent' => $percent,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                'state' => $state,
                'used_display' => $fmt($used),
                'limit_display' => $limit === null ? 'Unlimited' : $fmt($limit),
                'remaining_display' => $limit === null ? '—' : $fmt(max(0, $limit - $used)),
            ];
        }
        return $out;
    }

    /** Throw a clear, user-facing error if adding $increment would exceed the plan limit. */
    public static function assertCanAdd(int $tenantId, string $metric, int $increment = 1, ?array $subscription = null): void
    {
        $subscription ??= SubscriptionService::current($tenantId);
        $limit = self::limit($subscription, $metric);
        if ($limit === null) {
            return;
        }
        $used = self::used($tenantId, $metric);
        if ($used + $increment > $limit) {
            $plan = $subscription['plan_name'] ?? 'current';
            $msg = match ($metric) {
                'users' => sprintf('Your %s plan allows up to %d active or invited users and you already have %d. Deactivate a user you no longer need, or ask Fameweb to upgrade your plan.', $plan, $limit, $used),
                'storage' => sprintf('This upload would exceed your %s plan storage limit of %s (%s used). Delete unused files or ask Fameweb to upgrade your plan.', $plan, format_bytes($limit), format_bytes($used)),
                'members' => sprintf('Your %s plan allows up to %d active or pending members, volunteers and interns, and you already have %d. Mark people who have left as inactive or completed, or ask Fameweb to upgrade your plan.', $plan, $limit, $used),
                'certificates' => sprintf('Your %s plan allows %d certificates and %d have been issued. Ask Fameweb to upgrade your plan to issue more.', $plan, $limit, $used),
                default => sprintf('Your %s plan limit for %s (%d) has been reached.', $plan, self::METRICS[$metric]['label'], $limit),
            };
            throw new LimitExceededException($msg);
        }
    }

    /**
     * Record a metered event exactly once. Re-sending the same idempotency key (retries,
     * double submits) is ignored. Returns true when a new record was written.
     */
    public static function record(int $tenantId, string $metric, int $quantity, string $idempotencyKey, ?string $sourceType = null, int|string|null $sourceId = null, ?int $userId = null): bool
    {
        try {
            Database::instance()->insert('usage_records', [
                'tenant_id' => $tenantId,
                'metric' => $metric,
                'quantity' => $quantity,
                'idempotency_key' => mb_substr($idempotencyKey, 0, 120),
                'source_type' => $sourceType,
                'source_id' => $sourceId === null ? null : (string) $sourceId,
                'occurred_at' => date('Y-m-d H:i:s'),
                'recorded_by' => $userId,
            ]);
            return true;
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                return false;
            }
            throw $e;
        }
    }

    /** Usage of every tenant (platform view). */
    public static function platformOverview(string $search = ''): array
    {
        $params = [];
        $where = '';
        if ($search !== '') {
            $where = 'WHERE t.name LIKE ? OR t.reference_code LIKE ?';
            $params = ['%' . $search . '%', '%' . $search . '%'];
        }
        $rows = Database::instance()->fetchAll(
            "SELECT t.id, t.name, t.reference_code, t.status,
                    s.status AS sub_status, p.name AS plan_name, p.max_users, p.max_storage_mb, p.max_members,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.status IN ('active','invited')) AS users_used,
                    (SELECT COUNT(*) FROM people pe WHERE pe.tenant_id = t.id AND pe.status IN ('pending','active')) AS members_used,
                    (SELECT COALESCE(SUM(so.size_bytes),0) FROM storage_objects so WHERE so.tenant_id = t.id AND so.deleted_at IS NULL) AS storage_used,
                    (SELECT COUNT(*) FROM storage_objects so WHERE so.tenant_id = t.id AND so.deleted_at IS NULL AND so.category = 'document') AS documents_used,
                    (SELECT COALESCE(SUM(ur.quantity),0) FROM usage_records ur WHERE ur.tenant_id = t.id AND ur.metric = 'emails' AND ur.occurred_at >= ?) AS emails_month,
                    (SELECT MAX(u.last_login_at) FROM users u WHERE u.tenant_id = t.id) AS last_activity
               FROM tenants t
               LEFT JOIN subscriptions s ON s.tenant_id = t.id AND s.is_current = 1
               LEFT JOIN subscription_plans p ON p.id = s.plan_id
               $where
              ORDER BY t.name",
            array_merge([date('Y-m-01 00:00:00')], $params)
        );
        $warn = SettingsService::int('usage_warning_percent') ?: 80;
        foreach ($rows as &$r) {
            $r['users_pct'] = $r['max_users'] ? min(100, round($r['users_used'] / $r['max_users'] * 100)) : null;
            $limitBytes = $r['max_storage_mb'] !== null ? (int) $r['max_storage_mb'] * 1048576 : null;
            $r['storage_pct'] = $limitBytes ? min(100, round($r['storage_used'] / $limitBytes * 100)) : null;
            $r['members_pct'] = $r['max_members'] ? min(100, round($r['members_used'] / $r['max_members'] * 100)) : null;
            $r['alert'] = ($r['users_pct'] !== null && $r['users_pct'] >= $warn) || ($r['storage_pct'] !== null && $r['storage_pct'] >= $warn)
                || ($r['members_pct'] !== null && $r['members_pct'] >= $warn);
        }
        return $rows;
    }
}
