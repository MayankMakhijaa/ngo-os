<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?Request $current = null;
    public array $routeParams = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $files,
        public readonly array $server,
    ) {
    }

    public static function capture(): Request
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = base_path_url();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        if (str_starts_with($uri, '/public/')) { // root .htaccess fallback deployment
            $uri = substr($uri, 7);
        }
        if (str_starts_with($uri, '/index.php')) {
            $uri = substr($uri, 10);
        }
        $path = '/' . trim(rawurldecode($uri), '/');
        self::$current = new Request(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER
        );
        return self::$current;
    }

    public static function current(): ?Request
    {
        return self::$current;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $v = $this->body[$key] ?? $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    /** String input, never an array (prevents type-juggling via name[]=). */
    public function str(string $key, string $default = ''): string
    {
        $v = $this->body[$key] ?? $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key);
        return is_string($v) && preg_match('/^-?\d+$/', $v) ? (int) $v : $default;
    }

    public function arr(string $key): array
    {
        $v = $this->body[$key] ?? $this->query[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->str($k);
        }
        return $out;
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['error']) || is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    public function param(string $key): string
    {
        return (string) ($this->routeParams[$key] ?? '');
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function ip(): string
    {
        if (config('security.trust_proxy_headers')) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
                if (!empty($this->server[$h])) {
                    $ip = trim(explode(',', (string) $this->server[$h])[0]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isHttps(): bool
    {
        if (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') {
            return true;
        }
        return config('security.trust_proxy_headers')
            && strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function fullUrlPath(): string
    {
        $q = $this->query ? '?' . http_build_query($this->query) : '';
        return $this->path . $q;
    }
}
