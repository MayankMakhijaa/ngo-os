<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Exceptions\DomainException;

/**
 * Subscriptions are never overwritten: assigning or changing a plan closes the current row
 * (is_current = 0) and inserts a new one. Date-based status changes (trial ended, renewal
 * missed, grace period over) are applied lazily whenever a subscription is read, plus by the
 * optional daily cron (bin/cron.php), so no background worker is needed.
 */
final class SubscriptionService
{
    public const STATUSES = ['trial', 'active', 'past_due', 'expired', 'suspended', 'cancelled'];
    /** Statuses that allow NGO users to create and change data. */
    public const WRITABLE = ['trial', 'active', 'past_due'];

    private static array $moduleCache = [];

    public static function current(int $tenantId, bool $refresh = true): ?array
    {
        $sub = Database::instance()->fetch(
            'SELECT s.*, p.name AS plan_name, p.slug AS plan_slug, p.status AS plan_status,
                    p.max_users, p.max_members, p.max_storage_mb, p.max_certificates,
                    p.monthly_price, p.annual_price, p.trial_days
               FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id
              WHERE s.tenant_id = ? AND s.is_current = 1',
            [$tenantId]
        );
        if ($sub && $refresh && self::refreshStatus($sub)) {
            return self::current($tenantId, false);
        }
        return $sub;
    }

    /** Apply date-driven transitions. Returns true when the status changed. */
    public static function refreshStatus(array $sub): bool
    {
        $today = date('Y-m-d');
        $grace = SettingsService::int('subscription_grace_days');
        $new = null;
        $note = null;
        if ($sub['status'] === 'trial' && $sub['trial_ends_at'] && $sub['trial_ends_at'] < $today) {
            [$new, $note] = ['expired', 'Trial period ended'];
        } elseif ($sub['status'] === 'active' && $sub['end_date'] && $sub['end_date'] < $today) {
            $graceEnd = date('Y-m-d', strtotime($sub['end_date'] . " +$grace days"));
            [$new, $note] = $graceEnd >= $today ? ['past_due', 'Renewal date passed'] : ['expired', 'Renewal date and grace period passed'];
        } elseif ($sub['status'] === 'past_due' && $sub['end_date']) {
            $graceEnd = date('Y-m-d', strtotime($sub['end_date'] . " +$grace days"));
            if ($graceEnd < $today) {
                [$new, $note] = ['expired', 'Grace period passed'];
            }
        }
        if ($new === null) {
            return false;
        }
        $db = Database::instance();
        $changed = $db->execute(
            'UPDATE subscriptions SET status = ? WHERE id = ? AND status = ?',
            [$new, (int) $sub['id'], $sub['status']]
        );
        if ($changed === 1) {
            self::event((int) $sub['id'], (int) $sub['tenant_id'], $sub['status'], $new, $note, null);
            AuditLogger::log('subscription.status_auto', 'subscription', $sub['id'],
                ['from' => $sub['status'], 'to' => $new, 'reason' => $note], (int) $sub['tenant_id'], []);
        }
        self::$moduleCache = [];
        return true;
    }

    /** Daily sweep used by bin/cron.php. */
    public static function refreshAll(): int
    {
        $rows = Database::instance()->fetchAll(
            "SELECT * FROM subscriptions WHERE is_current = 1 AND status IN ('trial','active','past_due')"
        );
        $n = 0;
        foreach ($rows as $r) {
            $n += self::refreshStatus($r) ? 1 : 0;
        }
        return $n;
    }

    public static function isWritable(?array $sub): bool
    {
        return $sub !== null && in_array($sub['status'], self::WRITABLE, true);
    }

    /** @return string[] module keys available to the tenant (core + plan modules). */
    public static function enabledModules(int $tenantId): array
    {
        if (isset(self::$moduleCache[$tenantId])) {
            return self::$moduleCache[$tenantId];
        }
        $core = array_keys(array_filter((array) config('modules'), static fn ($m) => $m['core']));
        $planModules = array_column(Database::instance()->fetchAll(
            'SELECT pm.module_key FROM subscriptions s JOIN plan_modules pm ON pm.plan_id = s.plan_id
              WHERE s.tenant_id = ? AND s.is_current = 1',
            [$tenantId]
        ), 'module_key');
        return self::$moduleCache[$tenantId] = array_values(array_unique(array_merge($core, $planModules)));
    }

    public static function flushCache(): void
    {
        self::$moduleCache = [];
    }

    /**
     * Assign a plan (new NGO) or change it (existing NGO). The previous subscription is kept as
     * history. Returns [subscriptionId, warnings[]] where warnings explain any limits the NGO's
     * existing data already exceeds on the new plan (data is never deleted).
     */
    public static function assign(int $tenantId, int $planId, array $opts, ?array $actor = null): array
    {
        $actor ??= Auth::user();
        $db = Database::instance();
        $plan = $db->fetch('SELECT * FROM subscription_plans WHERE id = ?', [$planId]);
        if (!$plan) {
            throw new DomainException('The selected plan does not exist.');
        }
        if ($plan['status'] !== 'active') {
            throw new DomainException('The selected plan is inactive and cannot be assigned.');
        }
        $status = $opts['status'] ?? 'active';
        if (!in_array($status, ['trial', 'active'], true)) {
            throw new DomainException('A new subscription must start as Trial or Active.');
        }
        $cycle = $opts['billing_cycle'] ?? 'monthly';
        if (!in_array($cycle, ['monthly', 'annual', 'custom'], true)) {
            throw new DomainException('Invalid billing cycle.');
        }
        $start = ($opts['start_date'] ?? '') ?: date('Y-m-d');
        $trialEnds = null;
        if ($status === 'trial') {
            $days = (int) ($plan['trial_days'] ?: SettingsService::int('default_trial_days'));
            $trialEnds = ($opts['trial_ends_at'] ?? null) ?: date('Y-m-d', strtotime("$start +$days days"));
        }
        $end = $opts['end_date'] ?? null;
        if (!$end) {
            $end = match (true) {
                $status === 'trial' => $trialEnds,
                $cycle === 'annual' => date('Y-m-d', strtotime("$start +1 year")),
                $cycle === 'monthly' => date('Y-m-d', strtotime("$start +1 month")),
                default => null,
            };
        }
        if ($end !== null && $end < $start) {
            throw new DomainException('The expiry / renewal date must be on or after the start date.');
        }
        $price = isset($opts['price']) && $opts['price'] !== ''
            ? (float) $opts['price']
            : ($status === 'trial' ? 0.0 : (float) ($cycle === 'annual' ? $plan['annual_price'] : $plan['monthly_price']));

        $subId = $db->transaction(function (Database $db) use ($tenantId, $plan, $status, $cycle, $start, $end, $trialEnds, $price, $opts, $actor) {
            $db->fetch('SELECT id FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
            $prev = $db->fetch('SELECT * FROM subscriptions WHERE tenant_id = ? AND is_current = 1 FOR UPDATE', [$tenantId]);
            if ($prev) {
                $db->execute(
                    "UPDATE subscriptions SET is_current = 0, ended_at = NOW(), end_reason = 'plan_change' WHERE id = ?",
                    [(int) $prev['id']]
                );
                self::event((int) $prev['id'], $tenantId, $prev['status'], $prev['status'], 'Superseded by a plan change', $actor['id'] ?? null);
            }
            $id = $db->insert('subscriptions', [
                'tenant_id' => $tenantId,
                'plan_id' => (int) $plan['id'],
                'status' => $status,
                'billing_cycle' => $cycle,
                'price' => number_format($price, 2, '.', ''),
                'currency' => $plan['currency'],
                'start_date' => $start,
                'end_date' => $end,
                'trial_ends_at' => $trialEnds,
                'is_current' => 1,
                'gateway' => 'manual',
                'notes' => $opts['notes'] ?? null,
                'created_by' => $actor['id'] ?? null,
            ]);
            self::event($id, $tenantId, null, $status, $prev ? 'Plan changed' : 'Subscription created', $actor['id'] ?? null);
            AuditLogger::log($prev ? 'subscription.changed' : 'subscription.assigned', 'subscription', $id, [
                'plan' => $plan['name'],
                'previous_plan_id' => $prev['plan_id'] ?? null,
                'status' => $status, 'billing_cycle' => $cycle, 'start_date' => $start, 'end_date' => $end,
            ], $tenantId, $actor);
            return $id;
        });
        self::$moduleCache = [];
        PermissionService::flush();

        $warnings = [];
        foreach (UsageService::summary($tenantId) as $metric) {
            if ($metric['limit'] !== null && $metric['used'] > $metric['limit']) {
                $warnings[] = sprintf('%s: %s used, new plan allows %s. Existing data is kept, but new additions are blocked.',
                    $metric['label'], $metric['used_display'], $metric['limit_display']);
            }
        }
        return [$subId, $warnings];
    }

    /** Manual status / renewal-date update by a Fameweb admin. */
    public static function update(int $subscriptionId, string $status, ?string $endDate, ?string $note, ?array $actor = null): void
    {
        $actor ??= Auth::user();
        if (!in_array($status, self::STATUSES, true)) {
            throw new DomainException('Invalid subscription status.');
        }
        $db = Database::instance();
        $db->transaction(function (Database $db) use ($subscriptionId, $status, $endDate, $note, $actor): void {
            $sub = $db->fetch('SELECT * FROM subscriptions WHERE id = ? FOR UPDATE', [$subscriptionId]);
            if (!$sub || !(int) $sub['is_current']) {
                throw new DomainException('Only the current subscription can be updated; history is read-only.');
            }
            if ($endDate !== null && $endDate !== '' && $endDate < $sub['start_date']) {
                throw new DomainException('The expiry / renewal date must be on or after the start date.');
            }
            $data = ['status' => $status];
            if ($endDate !== null && $endDate !== '') {
                $data['end_date'] = $endDate;
                if ($status === 'trial') {
                    $data['trial_ends_at'] = $endDate;
                }
            }
            if (in_array($status, ['cancelled'], true)) {
                $data['ended_at'] = date('Y-m-d H:i:s');
                $data['end_reason'] = 'cancelled';
            }
            $db->update('subscriptions', $data, ['id' => $subscriptionId]);
            self::event($subscriptionId, (int) $sub['tenant_id'], $sub['status'], $status, $note, $actor['id'] ?? null);
            AuditLogger::log('subscription.updated', 'subscription', $subscriptionId, [
                'from_status' => $sub['status'], 'to_status' => $status,
                'from_end_date' => $sub['end_date'], 'to_end_date' => $data['end_date'] ?? $sub['end_date'],
                'note' => $note,
            ], (int) $sub['tenant_id'], $actor);
        });
        self::$moduleCache = [];
    }

    /**
     * Record a manual payment. The idempotency key (a nonce embedded in the form) makes a
     * double-submitted form or a retried request record the payment only once.
     * Returns [paymentId, created(bool)].
     */
    public static function recordPayment(int $subscriptionId, array $data, string $idempotencyKey, ?array $actor = null): array
    {
        $actor ??= Auth::user();
        $db = Database::instance();
        $existing = $db->fetchColumn('SELECT id FROM subscription_payments WHERE idempotency_key = ?', [$idempotencyKey]);
        if ($existing) {
            return [(int) $existing, false];
        }
        try {
            return $db->transaction(function (Database $db) use ($subscriptionId, $data, $idempotencyKey, $actor) {
                $sub = $db->fetch('SELECT * FROM subscriptions WHERE id = ? FOR UPDATE', [$subscriptionId]);
                if (!$sub) {
                    throw new DomainException('Subscription not found.');
                }
                $id = $db->insert('subscription_payments', [
                    'tenant_id' => (int) $sub['tenant_id'],
                    'subscription_id' => $subscriptionId,
                    'amount' => number_format((float) $data['amount'], 2, '.', ''),
                    'currency' => $sub['currency'],
                    'status' => $data['status'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?: null,
                    'paid_at' => $data['paid_at'] . ' 00:00:00',
                    'period_start' => $data['period_start'] ?: null,
                    'period_end' => $data['period_end'] ?: null,
                    'gateway' => 'manual',
                    'idempotency_key' => $idempotencyKey,
                    'notes' => $data['notes'] ?: null,
                    'recorded_by' => $actor['id'] ?? null,
                ]);
                AuditLogger::log('payment.recorded', 'subscription_payment', $id, [
                    'subscription_id' => $subscriptionId, 'amount' => $data['amount'], 'method' => $data['method'],
                    'status' => $data['status'], 'period_end' => $data['period_end'] ?: null,
                ], (int) $sub['tenant_id'], $actor);

                // A paid payment covering a period can renew the current subscription.
                if (!empty($data['extend']) && $data['status'] === 'paid' && $data['period_end'] && (int) $sub['is_current']) {
                    $newStatus = in_array($sub['status'], ['trial', 'past_due', 'expired', 'active'], true) ? 'active' : $sub['status'];
                    $db->update('subscriptions', ['end_date' => $data['period_end'], 'status' => $newStatus, 'trial_ends_at' => null], ['id' => $subscriptionId]);
                    self::event($subscriptionId, (int) $sub['tenant_id'], $sub['status'], $newStatus, 'Renewed until ' . $data['period_end'] . ' by payment #' . $id, $actor['id'] ?? null);
                    AuditLogger::log('subscription.renewed', 'subscription', $subscriptionId,
                        ['end_date' => $data['period_end'], 'payment_id' => $id], (int) $sub['tenant_id'], $actor);
                }
                return [$id, true];
            });
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                return [(int) $db->fetchColumn('SELECT id FROM subscription_payments WHERE idempotency_key = ?', [$idempotencyKey]), false];
            }
            throw $e;
        }
    }

    public static function history(int $tenantId): array
    {
        return Database::instance()->fetchAll(
            'SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id
              WHERE s.tenant_id = ? ORDER BY s.id DESC',
            [$tenantId]
        );
    }

    public static function payments(int $tenantId, int $limit = 50): array
    {
        return Database::instance()->fetchAll(
            'SELECT sp.*, p.name AS plan_name FROM subscription_payments sp
               JOIN subscriptions s ON s.id = sp.subscription_id
               JOIN subscription_plans p ON p.id = s.plan_id
              WHERE sp.tenant_id = ? ORDER BY sp.paid_at DESC, sp.id DESC LIMIT ' . (int) $limit,
            [$tenantId]
        );
    }

    public static function events(int $tenantId, int $limit = 50): array
    {
        return Database::instance()->fetchAll(
            'SELECT e.*, u.name AS actor_name FROM subscription_events e LEFT JOIN users u ON u.id = e.actor_user_id
              WHERE e.tenant_id = ? ORDER BY e.id DESC LIMIT ' . (int) $limit,
            [$tenantId]
        );
    }

    /** Monthly recurring revenue from current Active / Past Due subscriptions with a monthly or annual cycle. */
    public static function mrr(): array
    {
        $rows = Database::instance()->fetchAll(
            "SELECT billing_cycle, currency, SUM(price) AS total, COUNT(*) AS n FROM subscriptions
              WHERE is_current = 1 AND status IN ('active','past_due') GROUP BY billing_cycle, currency"
        );
        $mrr = 0.0;
        $custom = 0;
        $counted = 0;
        foreach ($rows as $r) {
            if ($r['billing_cycle'] === 'monthly') {
                $mrr += (float) $r['total'];
                $counted += (int) $r['n'];
            } elseif ($r['billing_cycle'] === 'annual') {
                $mrr += (float) $r['total'] / 12;
                $counted += (int) $r['n'];
            } else {
                $custom += (int) $r['n'];
            }
        }
        return ['mrr' => round($mrr, 2), 'counted' => $counted, 'custom_excluded' => $custom];
    }

    private static function event(int $subId, int $tenantId, ?string $from, string $to, ?string $note, int|string|null $actorId): void
    {
        Database::instance()->insert('subscription_events', [
            'subscription_id' => $subId,
            'tenant_id' => $tenantId,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note ? mb_substr($note, 0, 255) : null,
            'actor_user_id' => $actorId === null ? null : (int) $actorId,
        ]);
    }
}
