<?php
declare(strict_types=1);

namespace App\Mail;

/** An outgoing email as handed to a transport. */
final class Message
{
    /** @param array<int, array{filename:string, mime:string, content:string}> $attachments */
    public function __construct(
        public string $fromEmail,
        public string $fromName,
        public string $toEmail,
        public string $toName,
        public string $subject,
        public string $body,
        public ?string $replyTo = null,
        public array $attachments = [],
    ) {
    }

    /** RFC 5322 message (headers + MIME body) with CRLF line endings. */
    public function toMime(string $messageIdDomain = 'localhost'): string
    {
        $headers = [
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . preg_replace('/[^A-Za-z0-9.\-]/', '', $messageIdDomain) . '>',
            'From: ' . self::address($this->fromEmail, $this->fromName),
            'To: ' . self::address($this->toEmail, $this->toName),
            'Subject: ' . self::header($this->subject),
            'MIME-Version: 1.0',
            'X-Mailer: FamewebNGO',
        ];
        if ($this->replyTo) {
            $headers[] = 'Reply-To: ' . self::address($this->replyTo, '');
        }
        $text = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $this->body));
        if (!$this->attachments) {
            return implode("\r\n", $headers) . "\r\n" . $text;
        }
        $boundary = 'fw-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $body = "This is a multi-part message in MIME format.\r\n--" . $boundary . "\r\n" . $text . "\r\n";
        foreach ($this->attachments as $a) {
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $a['filename']);
            $body .= '--' . $boundary . "\r\nContent-Type: " . $a['mime'] . '; name="' . $name . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $name . "\"\r\n\r\n"
                . chunk_split(base64_encode($a['content']), 76, "\r\n");
        }
        $body .= '--' . $boundary . "--\r\n";
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    public static function header(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    public static function address(string $email, string $name): string
    {
        $email = str_replace(["\r", "\n", '<', '>'], '', $email);
        if ($name === '') {
            return $email;
        }
        $name = str_replace(["\r", "\n"], ' ', $name);
        $encoded = preg_match('/[^\x20-\x7E]/', $name) ? '=?UTF-8?B?' . base64_encode($name) . '?=' : '"' . addcslashes($name, '"\\') . '"';
        return $encoded . ' <' . $email . '>';
    }
}
