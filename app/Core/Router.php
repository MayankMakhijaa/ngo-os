<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Small router. Routes are declared in routes/web.php with a handler [Controller::class, 'method']
 * and a middleware list. Parameter constraints: {id}/{doc}/{user} digits, {type} people type, {token} hex, {uuid} UUID, others [^/]+.
 */
final class Router
{
    private array $routes = [];
    private array $groupStack = [];

    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /** Group routes under a prefix with shared middleware. */
    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $this->groupStack[] = ['prefix' => $prefix, 'middleware' => $middleware];
        $callback($this);
        array_pop($this->groupStack);
    }

    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        $prefix = '';
        $mw = [];
        foreach ($this->groupStack as $g) {
            $prefix .= $g['prefix'];
            $mw = array_merge($mw, $g['middleware']);
        }
        $full = '/' . trim($prefix . $path, '/');
        $regex = preg_replace_callback('/\{([a-z_]+)\}/', static function ($m) {
            $pattern = match ($m[1]) {
                'id', 'doc', 'user' => '\d+',
                'type'  => 'members|volunteers|interns',
                'token' => '[a-f0-9]{64}',
                'uuid'  => '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}',
                default => '[^/]+',
            };
            return '(?P<' . $m[1] . '>' . $pattern . ')';
        }, $full);
        $this->routes[] = [
            'method' => $method,
            'path' => $full,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'middleware' => array_merge($mw, $middleware),
        ];
    }

    /** @return array{0: array, 1: array}|null  [route, params] */
    public function match(string $method, string $path): ?array
    {
        $methodMismatch = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m)) {
                if ($route['method'] !== $method) {
                    $methodMismatch = true;
                    continue;
                }
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                return [$route, $params];
            }
        }
        if ($methodMismatch) {
            throw new HttpException(405, 'Method not allowed.');
        }
        return null;
    }

    public function all(): array
    {
        return $this->routes;
    }
}
