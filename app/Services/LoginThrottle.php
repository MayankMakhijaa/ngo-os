<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Database-backed throttling (works on shared hosting, no Redis). Locks an email after N
 * failures within the decay window and an IP after a larger number, then unlocks automatically.
 */
final class LoginThrottle
{
    /** Seconds until the email/IP may try again, or 0 when not locked. */
    public static function lockedFor(string $email, string $ip): int
    {
        $cfg = (array) config('security.login');
        $decay = (int) $cfg['decay_minutes'] * 60;
        $since = date('Y-m-d H:i:s', time() - $decay);
        $db = Database::instance();

        $wait = 0;
        $lastSuccess = $db->fetchColumn(
            "SELECT MAX(attempted_at) FROM login_attempts WHERE email = ? AND action = 'login' AND success = 1",
            [$email]
        );
        $from = ($lastSuccess && $lastSuccess > $since) ? $lastSuccess : $since;
        $byEmail = $db->fetch(
            "SELECT COUNT(*) AS c, MIN(attempted_at) AS first FROM login_attempts
              WHERE email = ? AND action = 'login' AND success = 0 AND attempted_at > ?",
            [$email, $from]
        );
        if ((int) $byEmail['c'] >= (int) $cfg['max_attempts_per_email']) {
            $latest = (string) $db->fetchColumn(
                "SELECT MAX(attempted_at) FROM login_attempts WHERE email = ? AND action = 'login' AND success = 0",
                [$email]
            );
            $wait = max($wait, strtotime($latest) + $decay - time());
        }
        $byIp = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND action = 'login' AND success = 0 AND attempted_at > ?",
            [$ip, $since]
        );
        if ($byIp >= (int) $cfg['max_attempts_per_ip']) {
            $wait = max($wait, $decay);
        }
        return max(0, $wait);
    }

    public static function record(string $email, string $ip, bool $success, ?int $userId = null, string $action = 'login'): void
    {
        Database::instance()->insert('login_attempts', [
            'action' => $action,
            'email' => mb_substr($email, 0, 190),
            'ip' => $ip,
            'success' => $success ? 1 : 0,
            'user_id' => $userId,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);
        // Occasional housekeeping instead of a cron job.
        if (random_int(1, 200) === 1) {
            Database::instance()->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
        }
    }

    /** Password-reset requests: limit per email and per IP per hour. */
    public static function resetRequestsExceeded(string $email, string $ip): bool
    {
        $max = (int) config('security.password_reset.max_requests_per_hour', 5);
        $since = date('Y-m-d H:i:s', time() - 3600);
        $db = Database::instance();
        $byEmail = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM login_attempts WHERE action = 'password_reset' AND email = ? AND attempted_at > ?",
            [$email, $since]
        );
        $byIp = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM login_attempts WHERE action = 'password_reset' AND ip = ? AND attempted_at > ?",
            [$ip, $since]
        );
        return $byEmail >= $max || $byIp >= $max * 4;
    }
}
