<?php
declare(strict_types=1);

namespace App\Core;

/** Minimal .env loader. Real environment variables take precedence over the file. */
final class Env
{
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z0-9_]+$/', $key)) {
                continue;
            }
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            } else {
                $value = preg_replace('/\s+#.*$/', '', $value); // strip inline comments
            }
            self::$values[$key] = $value;
        }
    }

    public static function exists(string $file): bool
    {
        return is_file($file);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $real = getenv($key);
        if ($real !== false) {
            return $real;
        }
        return self::$values[$key] ?? $default;
    }
}
