<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\DomainException;
use App\Mail\Message;
use App\Mail\SmtpTransport;
use App\Mail\TransportException;
use App\Support\Crypto;

/**
 * Email outbox. Every email is written to email_outbox first, then delivered at once; when
 * delivery fails it is retried with back-off by bin/cron.php (or "Process queue now" in the
 * admin panel). Each try is recorded in email_attempts. Bodies are encrypted at rest and
 * single-use links (64-hex tokens) are never displayed and are scrubbed after delivery.
 */
final class EmailService
{
    private const TOKEN_PATTERN = '/\b[a-f0-9]{64}\b/';

    /**
     * @param array{to:string, subject:string, body:string, to_name?:string, tenant_id?:?int, category?:string,
     *              idempotency_key?:?string, attachments?:array, created_by?:?int, max_attempts?:int} $m
     * @return int outbox id (the existing one when the idempotency key was already used)
     */
    public static function queue(array $m): int
    {
        $to = mb_strtolower(trim($m['to']));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $m['subject'])) {
            throw new DomainException('Invalid email recipient or subject.');
        }
        $db = Database::instance();
        $key = isset($m['idempotency_key']) && $m['idempotency_key'] !== null ? mb_substr((string) $m['idempotency_key'], 0, 120) : null;
        if ($key !== null) {
            $existing = $db->fetchColumn('SELECT id FROM email_outbox WHERE idempotency_key = ?', [$key]);
            if ($existing) {
                return (int) $existing;
            }
        }
        try {
            return $db->insert('email_outbox', [
                'tenant_id' => $m['tenant_id'] ?? null,
                'category' => mb_substr($m['category'] ?? 'general', 0, 40),
                'to_email' => $to,
                'to_name' => isset($m['to_name']) ? mb_substr($m['to_name'], 0, 150) : null,
                'subject' => mb_substr($m['subject'], 0, 255),
                'body_encrypted' => self::seal($m['body']),
                'attachments' => !empty($m['attachments']) ? json_encode(array_values($m['attachments'])) : null,
                'status' => 'queued',
                'max_attempts' => (int) ($m['max_attempts'] ?? config('mail.max_attempts', 5)),
                'next_attempt_at' => date('Y-m-d H:i:s'),
                'idempotency_key' => $key,
                'created_by' => $m['created_by'] ?? null,
            ]);
        } catch (\PDOException $e) {
            if ($key !== null && Database::isDuplicateKey($e)) {
                return (int) $db->fetchColumn('SELECT id FROM email_outbox WHERE idempotency_key = ?', [$key]);
            }
            throw $e;
        }
    }

    /** Queue and try to deliver immediately. Returns the outbox id. */
    public static function send(array $m): int
    {
        $id = self::queue($m);
        self::deliver($id);
        return $id;
    }

    /** One delivery attempt. Returns the resulting status (sent / queued / failed / sending). */
    public static function deliver(int $id): string
    {
        $db = Database::instance();
        // Claim the row so a parallel cron run or request cannot send it twice.
        $claimed = $db->execute(
            "UPDATE email_outbox SET status = 'sending', locked_until = ? WHERE id = ? AND status = 'queued'
                AND (locked_until IS NULL OR locked_until < ?)",
            [date('Y-m-d H:i:s', time() + 300), $id, date('Y-m-d H:i:s')]
        );
        $row = $db->fetch('SELECT * FROM email_outbox WHERE id = ?', [$id]);
        if ($claimed !== 1 || !$row) {
            return (string) ($row['status'] ?? 'missing');
        }
        $attemptNo = (int) $row['attempts'] + 1;
        $cfg = $row['tenant_id'] ? MailSettings::forTenant((int) $row['tenant_id']) : MailSettings::platform();
        $started = microtime(true);
        $permanent = false;
        try {
            $message = new Message(
                (string) $cfg['from'], (string) $cfg['from_name'], (string) $row['to_email'], (string) ($row['to_name'] ?? ''),
                (string) $row['subject'], self::open((string) $row['body_encrypted']), $cfg['reply_to'] ?? null,
                self::resolveAttachments($row)
            );
            $response = self::transport($cfg, $message);
            $ok = true;
        } catch (TransportException $e) {
            $ok = false;
            $permanent = $e->permanent;
            $response = $e->getMessage();
        } catch (\Throwable $e) {
            $ok = false;
            $response = 'Internal error: ' . mb_substr($e->getMessage(), 0, 200);
            \App\Core\ErrorHandler::log($e);
        }
        $response = self::redact(mb_substr((string) $response, 0, 500));
        $db->insert('email_attempts', [
            'outbox_id' => $id,
            'attempt_no' => $attemptNo,
            'transport' => $cfg['driver'],
            'success' => $ok ? 1 : 0,
            'response' => $response,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);

        if ($ok) {
            $db->update('email_outbox', [
                'status' => 'sent', 'attempts' => $attemptNo, 'sent_at' => date('Y-m-d H:i:s'), 'locked_until' => null,
                'next_attempt_at' => null, 'last_error' => null, 'transport' => $cfg['driver'],
                'body_encrypted' => self::seal(self::redact(self::open((string) $row['body_encrypted']))),
            ], ['id' => $id]);
            if ($row['tenant_id']) {
                UsageService::record((int) $row['tenant_id'], 'emails', 1, 'email:' . $id, 'email', $id);
            }
            return 'sent';
        }

        $final = $permanent || $attemptNo >= (int) $row['max_attempts'];
        $delays = (array) config('mail.retry_minutes', [2, 10, 60, 360]);
        $delay = (int) ($delays[min($attemptNo - 1, count($delays) - 1)] ?? 60);
        $db->update('email_outbox', [
            'status' => $final ? 'failed' : 'queued',
            'attempts' => $attemptNo,
            'locked_until' => null,
            'next_attempt_at' => $final ? null : date('Y-m-d H:i:s', time() + $delay * 60),
            'last_error' => $response,
            'transport' => $cfg['driver'],
        ], ['id' => $id]);
        if ($final) {
            AuditLogger::log('email.failed', 'email', $id, ['category' => $row['category'], 'attempts' => $attemptNo, 'error' => $response],
                $row['tenant_id'] === null ? null : (int) $row['tenant_id'], []);
        }
        return $final ? 'failed' : 'queued';
    }

    /** Deliver queued emails whose retry time has come (bin/cron.php). */
    public static function processDue(int $limit = 25): array
    {
        $db = Database::instance();
        // Release rows stuck in "sending" (a request that died mid-delivery).
        $db->execute("UPDATE email_outbox SET status = 'queued' WHERE status = 'sending' AND locked_until < ?", [date('Y-m-d H:i:s')]);
        $ids = array_column($db->fetchAll(
            "SELECT id FROM email_outbox WHERE status = 'queued' AND next_attempt_at <= ? ORDER BY next_attempt_at, id LIMIT " . max(1, min(200, $limit)),
            [date('Y-m-d H:i:s')]
        ), 'id');
        $result = ['sent' => 0, 'queued' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            $status = self::deliver((int) $id);
            if (isset($result[$status])) {
                $result[$status]++;
            }
        }
        return $result;
    }

    /** Manual retry of a failed (or waiting) email. $tenantId null = platform admin. */
    public static function retry(int $id, ?int $tenantId, array $actor): string
    {
        $row = self::find($id, $tenantId) ?? throw new DomainException('Email not found.');
        if (!in_array($row['status'], ['failed', 'queued'], true)) {
            throw new DomainException('Only failed or waiting emails can be retried.');
        }
        Database::instance()->execute(
            "UPDATE email_outbox SET status = 'queued', next_attempt_at = ?, max_attempts = GREATEST(max_attempts, attempts + 1) WHERE id = ?",
            [date('Y-m-d H:i:s'), $id]
        );
        AuditLogger::log('email.retried', 'email', $id, ['category' => $row['category']], $row['tenant_id'] === null ? null : (int) $row['tenant_id'], $actor);
        return self::deliver($id);
    }

    /** Load an outbox row. NGO callers pass their tenant id and only ever see their own emails. */
    public static function find(int $id, ?int $tenantId): ?array
    {
        $db = Database::instance();
        return $tenantId === null
            ? $db->fetch('SELECT o.*, t.name AS tenant_name FROM email_outbox o LEFT JOIN tenants t ON t.id = o.tenant_id WHERE o.id = ?', [$id])
            : $db->fetch('SELECT * FROM email_outbox WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    }

    public static function attempts(int $id): array
    {
        return Database::instance()->fetchAll('SELECT * FROM email_attempts WHERE outbox_id = ? ORDER BY attempt_no', [$id]);
    }

    /** Body for the log viewer: decrypted, single-use links hidden. */
    public static function displayBody(array $row): string
    {
        try {
            return self::redact(self::open((string) $row['body_encrypted']));
        } catch (\Throwable) {
            return '[The message body cannot be decrypted with the current APP_KEY.]';
        }
    }

    public static function stats(?int $tenantId): array
    {
        $where = $tenantId === null ? '1 = 1' : 'tenant_id = ' . (int) $tenantId;
        $rows = Database::instance()->fetchAll(
            "SELECT status, COUNT(*) AS n FROM email_outbox WHERE $where AND created_at >= ? GROUP BY status",
            [date('Y-m-d H:i:s', time() - 30 * 86400)]
        );
        return array_merge(['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0], array_column($rows, 'n', 'status'));
    }

    /** Replace bodies of emails older than $days with their redacted form (cron housekeeping). */
    public static function scrubOld(int $days = 30): int
    {
        $db = Database::instance();
        $n = 0;
        foreach ($db->fetchAll("SELECT id, body_encrypted FROM email_outbox WHERE status IN ('sent','failed') AND created_at < ? AND body_encrypted <> ''",
            [date('Y-m-d H:i:s', time() - $days * 86400)]) as $r) {
            try {
                $db->update('email_outbox', ['body_encrypted' => self::seal(self::redact(self::open($r['body_encrypted'])))], ['id' => (int) $r['id']]);
                $n++;
            } catch (\Throwable) {
            }
        }
        return $n;
    }

    public static function redact(string $text): string
    {
        return (string) preg_replace(self::TOKEN_PATTERN, '[single-use link hidden]', $text);
    }

    // ------------------------------------------------------------------ internals

    private static function seal(string $body): string
    {
        return Crypto::available() ? Crypto::encrypt($body) : 'b64:' . base64_encode($body);
    }

    private static function open(string $stored): string
    {
        return str_starts_with($stored, 'b64:') ? (string) base64_decode(substr($stored, 4)) : Crypto::decrypt($stored);
    }

    private static function resolveAttachments(array $row): array
    {
        $specs = $row['attachments'] ? json_decode((string) $row['attachments'], true) : [];
        $out = [];
        foreach (is_array($specs) ? $specs : [] as $spec) {
            $tenantId = (int) $row['tenant_id'];
            $file = match ($spec['type'] ?? '') {
                'receipt' => ReceiptService::attachment($tenantId, (int) ($spec['id'] ?? 0)),
                'certificate' => CertificateService::attachment($tenantId, (int) ($spec['id'] ?? 0)),
                default => null,
            };
            if ($file === null) {
                throw new TransportException('An attachment could not be generated (it may have been cancelled).', true);
            }
            $out[] = $file;
        }
        return $out;
    }

    private static function transport(array $cfg, Message $message): string
    {
        switch ($cfg['driver']) {
            case 'smtp':
                return (new SmtpTransport($cfg))->send($message);
            case 'mail':
                $mime = $message->toMime(substr(strrchr($message->fromEmail, '@') ?: '@localhost', 1));
                [$headers, $body] = explode("\r\n\r\n", $mime, 2);
                $headers = implode("\r\n", array_filter(explode("\r\n", $headers), static fn ($h) => !preg_match('/^(To|Subject):/i', $h)));
                if (!@mail($message->toEmail, Message::header($message->subject), $body, $headers)) {
                    throw new TransportException('PHP mail() did not accept the message. Check the hosting email configuration or switch to SMTP.');
                }
                return 'Accepted by PHP mail()';
            default:
                $names = array_map(static fn ($a) => $a['filename'] . ' (' . strlen($a['content']) . ' bytes)', $message->attachments);
                $entry = sprintf("==== %s ====\nTo: %s\nFrom: %s <%s>\nSubject: %s\n%s\n%s\n\n", date('c'), $message->toEmail, $message->fromName,
                    $message->fromEmail, $message->subject, $names ? 'Attachments: ' . implode(', ', $names) . "\n" : '', $message->body);
                if (@file_put_contents(storage_path('logs/mail.log'), $entry, FILE_APPEND | LOCK_EX) === false) {
                    throw new TransportException('Could not write to storage/logs/mail.log.');
                }
                return 'Written to the mail log (log driver)';
        }
    }
}
