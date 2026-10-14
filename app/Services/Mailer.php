<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Phase 1 mailer. "log" driver writes to storage/logs/mail.log (no email leaves the server);
 * "mail" uses PHP mail(). Every email sent on behalf of an NGO is metered in usage_records.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body, ?int $tenantId = null, ?string $idempotencyKey = null): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject)) {
            return false;
        }
        $from = (string) config('mail.from');
        $fromName = (string) config('mail.from_name');
        $driver = (string) config('mail.driver', 'log');
        $ok = false;

        if ($driver === 'mail') {
            $headers = [
                'From' => sprintf('%s <%s>', mb_encode_mimeheader($fromName), $from),
                'MIME-Version' => '1.0',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Mailer' => 'FamewebNGO',
            ];
            $ok = @mail($to, mb_encode_mimeheader($subject), $body, $headers);
        } else {
            $entry = sprintf("==== %s ====\nTo: %s\nFrom: %s <%s>\nSubject: %s\n\n%s\n\n", date('c'), $to, $fromName, $from, $subject, $body);
            $ok = @file_put_contents(storage_path('logs/mail.log'), $entry, FILE_APPEND | LOCK_EX) !== false;
        }

        if ($ok && $tenantId !== null) {
            UsageService::record($tenantId, 'emails', 1, $idempotencyKey ?? ('email:' . bin2hex(random_bytes(8))), 'email', null);
        }
        return $ok;
    }
}
