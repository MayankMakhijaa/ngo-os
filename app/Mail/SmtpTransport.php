<?php
declare(strict_types=1);

namespace App\Mail;

/**
 * Plain-PHP SMTP client (no extensions beyond OpenSSL). Supports implicit TLS (465),
 * STARTTLS (587) and AUTH LOGIN / PLAIN. Errors carry the server's reply but never the
 * credentials. Works on shared hosting because it only needs an outbound socket.
 */
final class SmtpTransport
{
    /** @var resource|null */
    private $socket = null;
    private array $transcript = [];

    /** @param array{host:string, port:int, encryption:string, username:string, password:string, timeout?:int} $cfg */
    public function __construct(private array $cfg)
    {
    }

    /** Returns the server's final reply on success; throws TransportException otherwise. */
    public function send(Message $message): string
    {
        $host = trim((string) $this->cfg['host']);
        if ($host === '') {
            throw new TransportException('SMTP host is not configured.', true);
        }
        $port = (int) ($this->cfg['port'] ?: 587);
        $enc = (string) ($this->cfg['encryption'] ?? 'tls');
        $timeout = (int) ($this->cfg['timeout'] ?? 15);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new TransportException(sprintf('Could not connect to %s:%d (%s).', $host, $port, $errstr ?: 'connection failed'));
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $timeout);
        try {
            $this->expect(220);
            $ehloName = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost')) ?: 'localhost';
            $features = $this->command('EHLO ' . $ehloName, 250);
            if ($enc === 'tls') {
                if (!str_contains(strtoupper($features), 'STARTTLS')) {
                    throw new TransportException('The SMTP server does not offer STARTTLS. Use encryption "ssl" (port 465) or check the port.', true);
                }
                $this->command('STARTTLS', 220);
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (!@stream_socket_enable_crypto($socket, true, $crypto)) {
                    throw new TransportException('TLS negotiation with the SMTP server failed.');
                }
                $features = $this->command('EHLO ' . $ehloName, 250);
            }
            $user = (string) ($this->cfg['username'] ?? '');
            if ($user !== '') {
                $pass = (string) ($this->cfg['password'] ?? '');
                if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $features) && !preg_match('/AUTH[ =][^\r\n]*LOGIN/i', $features)) {
                    $this->command('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), 235, true);
                } else {
                    $this->command('AUTH LOGIN', 334);
                    $this->command(base64_encode($user), 334, true);
                    $this->command(base64_encode($pass), 235, true);
                }
            }
            $this->command('MAIL FROM:<' . self::clean($message->fromEmail) . '>', 250);
            $this->command('RCPT TO:<' . self::clean($message->toEmail) . '>', [250, 251]);
            $this->command('DATA', 354);
            $data = $message->toMime(substr(strrchr($message->fromEmail, '@') ?: '@localhost', 1));
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $data));
            $this->write($data . "\r\n.\r\n");
            $reply = $this->expect(250);
            try {
                $this->write("QUIT\r\n");
            } catch (\Throwable) {
            }
            return trim($reply);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    /** @param int|int[] $expect */
    private function command(string $line, int|array $expect, bool $secret = false): string
    {
        $this->write($line . "\r\n");
        $this->transcript[] = '> ' . ($secret ? '[credentials hidden]' : $line);
        return $this->expect($expect);
    }

    private function write(string $data): void
    {
        $len = strlen($data);
        for ($written = 0; $written < $len; $written += $n) {
            $n = @fwrite($this->socket, substr($data, $written, 8192));
            if ($n === false || $n === 0) {
                throw new TransportException('The SMTP connection was closed while sending.');
            }
        }
    }

    /** @param int|int[] $expect */
    private function expect(int|array $expect): string
    {
        $reply = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if ($reply === '') {
            $meta = stream_get_meta_data($this->socket);
            throw new TransportException(!empty($meta['timed_out']) ? 'The SMTP server did not answer in time.' : 'The SMTP server closed the connection.');
        }
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, (array) $expect, true)) {
            $text = mb_substr(trim(preg_replace('/\s+/', ' ', $reply)), 0, 300);
            if ($code === 535 || $code === 534) {
                $text = $code . ' Authentication failed: check the SMTP username and password.';
            }
            // 5xx is a permanent rejection, except authentication errors: the administrator can fix
            // the credentials and the remaining retries will then succeed.
            throw new TransportException('SMTP error: ' . $text, $code >= 500 && $code !== 535 && $code !== 534);
        }
        return $reply;
    }

    private static function clean(string $email): string
    {
        return str_replace(["\r", "\n", '<', '>'], '', $email);
    }
}
