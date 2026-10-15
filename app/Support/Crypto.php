<?php
declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainException;

/**
 * Symmetric encryption for data at rest (identity documents, email bodies, SMTP passwords).
 * The key comes from APP_KEY in .env (generate one with `php bin/generate-key.php`); it is
 * never stored in the database. Uses libsodium (XSalsa20-Poly1305) when available and
 * AES-256-GCM through OpenSSL otherwise; both authenticate the data, so tampering is detected.
 */
final class Crypto
{
    private const PREFIX_SODIUM = 'v1s:';
    private const PREFIX_OPENSSL = 'v1o:';

    public static function available(): bool
    {
        return self::rawKey() !== null;
    }

    private static function rawKey(): ?string
    {
        $key = (string) env('APP_KEY', '');
        if ($key === '') {
            return null;
        }
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? $key : $decoded;
        }
        if (strlen($key) < 16) {
            return null;
        }
        return hash('sha256', 'fameweb-ngo:' . $key, true);
    }

    private static function key(): string
    {
        return self::rawKey() ?? throw new DomainException(
            'Encryption is not configured. Ask the platform administrator to set APP_KEY in the .env file (run php bin/generate-key.php).'
        );
    }

    public static function encrypt(string $plain): string
    {
        $key = self::key();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return self::PREFIX_SODIUM . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return self::PREFIX_OPENSSL . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        $key = self::key();
        if (str_starts_with($payload, self::PREFIX_SODIUM)) {
            $raw = base64_decode(substr($payload, 4), true);
            $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $plain = $raw === false || strlen($raw) <= $n ? false : sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
        } elseif (str_starts_with($payload, self::PREFIX_OPENSSL)) {
            $raw = base64_decode(substr($payload, 4), true);
            $plain = $raw === false || strlen($raw) <= 28 ? false
                : openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        } else {
            $plain = false;
        }
        if ($plain === false) {
            throw new \RuntimeException('Encrypted data could not be decrypted (wrong APP_KEY or corrupted data).');
        }
        return $plain;
    }
}
