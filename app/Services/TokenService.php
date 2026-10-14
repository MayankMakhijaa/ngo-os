<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Single-use, expiring tokens for invitations and password resets.
 * 256-bit random tokens; only SHA-256 hashes are stored; issuing a new token revokes older
 * unused tokens of the same purpose; consumption is atomic so a token cannot be used twice.
 */
final class TokenService
{
    public static function issue(int $userId, string $purpose, int $ttlSeconds, ?int $createdBy = null, ?string $ip = null): string
    {
        if (!in_array($purpose, ['reset', 'invite'], true)) {
            throw new \InvalidArgumentException('Bad token purpose');
        }
        $db = Database::instance();
        $token = bin2hex(random_bytes(32));
        $db->execute(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL',
            [$userId, $purpose]
        );
        $db->insert('password_reset_tokens', [
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
            'created_by' => $createdBy,
            'created_ip' => $ip,
        ]);
        return $token;
    }

    /** Returns the token row joined with its user, or null if unknown, used or expired. */
    public static function find(string $token, string $purpose): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return Database::instance()->fetch(
            'SELECT t.id AS token_id, t.expires_at, u.id AS user_id, u.email, u.name, u.status, u.tenant_id, u.user_type
               FROM password_reset_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND t.purpose = ? AND t.used_at IS NULL AND t.expires_at > NOW()',
            [hash('sha256', $token), $purpose]
        );
    }

    public static function consume(int $tokenId): bool
    {
        return Database::instance()->execute(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL AND expires_at > NOW()',
            [$tokenId]
        ) === 1;
    }

    public static function revokeAll(int $userId): void
    {
        Database::instance()->execute(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
    }
}
