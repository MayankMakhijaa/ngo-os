<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Gap-free per-tenant counters (member IDs, certificate, salary slip and receipt numbers).
 * Must be called inside the transaction that inserts the numbered row: the counter row stays
 * locked until that transaction ends, so parallel requests are serialised and a rollback
 * releases the number again.
 */
final class SequenceService
{
    public static function next(int $tenantId, string $key): int
    {
        $db = Database::instance();
        if (!$db->inTransaction()) {
            throw new \LogicException('SequenceService::next() must run inside a transaction');
        }
        $db->execute('INSERT IGNORE INTO tenant_sequences (tenant_id, seq_key, last_value) VALUES (?, ?, 0)', [$tenantId, $key]);
        $current = (int) $db->fetchColumn('SELECT last_value FROM tenant_sequences WHERE tenant_id = ? AND seq_key = ? FOR UPDATE', [$tenantId, $key]);
        $db->execute('UPDATE tenant_sequences SET last_value = ? WHERE tenant_id = ? AND seq_key = ?', [$current + 1, $tenantId, $key]);
        return $current + 1;
    }
}
