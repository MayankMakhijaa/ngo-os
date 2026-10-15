<?php
declare(strict_types=1);
/*
 * Minimal SMTP server for the test suite (plain text, no TLS, AUTH accepted as-is).
 *   php tests/fixtures/fake_smtp.php <port> <outfile> [failFirst]
 * The first [failFirst] messages are refused at RCPT with "451 4.3.0 Try again later", so the
 * suite can check retries. Each accepted message is appended to <outfile> followed by a line
 * "=====END=====".
 */
if (PHP_SAPI !== 'cli') { exit; }
[$port, $out, $failFirst] = [(int) $argv[1], $argv[2], (int) ($argv[3] ?? 0)];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) { fwrite(STDERR, "listen failed: $errstr\n"); exit(1); }
$refused = 0;
while ($conn = @stream_socket_accept($server, -1)) {
    stream_set_timeout($conn, 10);
    $w = static function (string $line) use ($conn) { fwrite($conn, $line . "\r\n"); };
    $w('220 fake.smtp.test ESMTP');
    $data = false;
    $msg = '';
    $authSteps = 0;
    while (($line = fgets($conn)) !== false) {
        if ($data) {
            if (rtrim($line, "\r\n") === '.') {
                $data = false;
                file_put_contents($out, $msg . "=====END=====\n", FILE_APPEND);
                $msg = '';
                $w('250 2.0.0 Ok: queued as TEST' . mt_rand(1000, 9999));
            } else {
                $msg .= $line;
            }
            continue;
        }
        if ($authSteps > 0) {
            $authSteps--;
            $w($authSteps > 0 ? '334 UGFzc3dvcmQ6' : '235 2.7.0 Authentication successful');
            continue;
        }
        $cmd = strtoupper(substr(trim($line), 0, 4));
        switch ($cmd) {
            case 'EHLO': fwrite($conn, "250-fake.smtp.test\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n"); break;
            case 'HELO': $w('250 fake.smtp.test'); break;
            case 'AUTH':
                if (stripos($line, 'AUTH LOGIN') === 0) { $authSteps = 2; $w('334 VXNlcm5hbWU6'); } else { $w('235 2.7.0 Authentication successful'); }
                break;
            case 'MAIL': $w('250 2.1.0 Ok'); break;
            case 'RCPT':
                if ($refused < $failFirst) { $refused++; $w('451 4.3.0 Try again later'); } else { $w('250 2.1.5 Ok'); }
                break;
            case 'DATA': $data = true; $w('354 End data with <CR><LF>.<CR><LF>'); break;
            case 'RSET': $w('250 Ok'); break;
            case 'NOOP': $w('250 Ok'); break;
            case 'QUIT': $w('221 Bye'); fclose($conn); continue 3;
            default: $w('502 5.5.2 Command not recognised');
        }
    }
    @fclose($conn);
}
