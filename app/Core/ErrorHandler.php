<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Converts PHP errors to exceptions and makes sure no stack trace, SQL or file path
 * is ever shown to users unless APP_DEBUG=true. Details go to storage/logs/app.log.
 */
final class ErrorHandler
{
    private static bool $debug = false;

    public static function register(bool $debug): void
    {
        self::$debug = $debug;
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
        set_exception_handler([self::class, 'handle']);
    }

    public static function isDebug(): bool
    {
        return self::$debug;
    }

    public static function log(\Throwable $e): string
    {
        $ref = substr(bin2hex(random_bytes(4)), 0, 8);
        $line = sprintf(
            "[%s] [ref %s] %s: %s in %s:%d\n%s\n",
            date('c'), $ref, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
        );
        $dir = storage_path('logs');
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($dir . '/app-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
        } else {
            error_log($line);
        }
        return $ref;
    }

    public static function handle(\Throwable $e): void
    {
        $ref = self::log($e);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, (self::$debug ? (string) $e : 'Error (ref ' . $ref . '): ' . $e->getMessage()) . PHP_EOL);
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        try {
            echo View::render('errors/500', ['ref' => $ref, 'exception' => self::$debug ? $e : null], 'layouts/minimal');
        } catch (\Throwable) {
            echo '<h1>Something went wrong</h1><p>Reference: ' . htmlspecialchars($ref) . '</p>';
        }
    }
}
