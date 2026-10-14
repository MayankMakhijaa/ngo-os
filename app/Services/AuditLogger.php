<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\TenantContext;

/**
 * Append-only audit trail. Metadata is sanitised (no passwords, tokens, secrets or full ID
 * numbers). Each row's hash covers its content and the previous row's hash, so editing or
 * deleting rows directly in the database breaks the chain and is detected by verify().
 */
final class AuditLogger
{
    private const SENSITIVE_KEY = '/pass|token|secret|aadhaar|aadhar|otp|pin$|cvv|card|pan_number|api_key|hash/i';

    public static function log(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        array $metadata = [],
        int|false|null $tenantId = false,
        ?array $actor = null,
    ): void {
        // $actor null = current user; [] = the system itself (automatic transitions, cron).
        $actor ??= Auth::user();
        $isSystem = $actor === [];
        if ($tenantId === false) {
            // Default tenant: the request's tenant, else the actor's tenant, else platform-level (NULL).
            $tenantId = TenantContext::has() ? TenantContext::id() : (isset($actor['tenant_id']) ? (int) $actor['tenant_id'] : null);
        }
        $request = Request::current();
        $row = [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $actor ? (int) $actor['id'] : null,
            'actor_type'    => $actor ? $actor['user_type'] : ((!$isSystem && $request) ? 'guest' : 'system'),
            'actor_role'    => $actor['role_slug'] ?? null,
            'action'        => mb_substr($action, 0, 80),
            'entity_type'   => $entityType,
            'entity_id'     => $entityId === null ? null : (string) $entityId,
            'ip'            => $request?->ip(),
            'user_agent'    => $request ? mb_substr($request->userAgent(), 0, 255) : 'cli',
            'metadata'      => $metadata ? json_encode(self::sanitize($metadata), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        $db = Database::instance();
        $db->transaction(function (Database $db) use ($row): void {
            $prev = $db->fetchColumn('SELECT row_hash FROM audit_logs ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $row['prev_hash'] = $prev ?: null;
            $row['row_hash'] = self::hashRow($row);
            $db->insert('audit_logs', $row);
        });
    }

    public static function hashRow(array $row): string
    {
        $parts = [];
        foreach (['prev_hash', 'tenant_id', 'actor_user_id', 'actor_type', 'actor_role', 'action', 'entity_type',
                  'entity_id', 'ip', 'user_agent', 'metadata', 'created_at'] as $k) {
            $parts[] = $k . '=' . (string) ($row[$k] ?? '');
        }
        return hash('sha256', implode("\x1f", $parts));
    }

    /** Recompute the hash chain. Returns null when intact, otherwise the first broken row id. */
    public static function verify(): ?int
    {
        $db = Database::instance();
        $prev = null;
        $lastId = 0;
        do {
            $rows = $db->fetchAll('SELECT * FROM audit_logs WHERE id > ? ORDER BY id ASC LIMIT 1000', [$lastId]);
            foreach ($rows as $r) {
                if (($r['prev_hash'] ?? null) !== $prev || self::hashRow($r) !== $r['row_hash']) {
                    return (int) $r['id'];
                }
                $prev = $r['row_hash'];
                $lastId = (int) $r['id'];
            }
        } while (count($rows) === 1000);
        return null;
    }

    public static function sanitize(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if ($depth > 4) {
                break;
            }
            if (is_string($k) && preg_match(self::SENSITIVE_KEY, $k)) {
                $out[$k] = '[redacted]';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = self::sanitize($v, $depth + 1);
            } elseif (is_string($v)) {
                // Mask anything that looks like a 12-digit Aadhaar number, keep last 4 digits.
                $v = preg_replace('/\b\d{4}[ -]?\d{4}[ -]?(\d{4})\b/', 'XXXX-XXXX-$1', $v);
                $out[$k] = mb_substr($v, 0, 500);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
