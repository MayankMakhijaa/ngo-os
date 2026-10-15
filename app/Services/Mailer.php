<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Entry point used by the rest of the app to send an email. Everything goes through the
 * outbox (EmailService): stored, delivered at once, retried by cron when delivery fails,
 * and visible in the email log. Emails sent on behalf of an NGO are metered as usage.
 */
final class Mailer
{
    /** Returns true when the email was accepted into the outbox (delivered now or queued for retry). */
    public static function send(string $to, string $subject, string $body, ?int $tenantId = null, ?string $idempotencyKey = null, array $options = []): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject)) {
            return false;
        }
        try {
            $id = EmailService::send([
                'to' => $to,
                'to_name' => $options['to_name'] ?? null,
                'subject' => $subject,
                'body' => $body,
                'tenant_id' => $tenantId,
                'category' => $options['category'] ?? self::categoryFor($idempotencyKey, $subject),
                'idempotency_key' => $idempotencyKey,
                'attachments' => $options['attachments'] ?? [],
                'created_by' => $options['created_by'] ?? (\App\Core\Auth::user()['id'] ?? null),
            ]);
            return $id > 0;
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::log($e);
            return false;
        }
    }

    private static function categoryFor(?string $key, string $subject): string
    {
        return match (true) {
            $key !== null && str_starts_with($key, 'invite-email') => 'invite',
            $key !== null && str_starts_with($key, 'access-email') => 'access_link',
            stripos($subject, 'reset') !== false => 'password_reset',
            stripos($subject, 'invitation') !== false => 'invite',
            default => 'general',
        };
    }
}
