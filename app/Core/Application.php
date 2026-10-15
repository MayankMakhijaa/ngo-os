<?php
declare(strict_types=1);

namespace App\Core;

use App\Middleware;
use App\Services\Installer;
use App\Services\Migrator;

/** Handles one HTTP request: headers, session, CSRF, routing, middleware, controller, errors. */
final class Application
{
    /** Middleware aliases usable in routes/web.php ("perm:users.view", "module:audit_logs"). */
    private const MIDDLEWARE = [
        'installed' => Middleware\InstalledMiddleware::class,
        'guest'     => Middleware\GuestMiddleware::class,
        'auth'      => Middleware\AuthMiddleware::class,
        'platform'  => Middleware\PlatformMiddleware::class,
        'tenant'    => Middleware\TenantMiddleware::class,
        'perm'      => Middleware\PermissionMiddleware::class,
        'module'    => Middleware\ModuleMiddleware::class,
        'writable'  => Middleware\SubscriptionWritableMiddleware::class,
    ];

    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
        $router = $this->router;
        require BASE_PATH . '/routes/web.php';
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function run(): void
    {
        $request = Request::capture();
        $this->sendSecurityHeaders($request);
        try {
            $response = $this->handle($request);
        } catch (RedirectException $e) {
            $response = Response::redirect($e->location);
        } catch (HttpException $e) {
            $response = $this->errorResponse($e->status, $e->getMessage());
        } catch (\Throwable $e) {
            $ref = ErrorHandler::log($e);
            $response = $this->pendingUpgradeResponse($e) ?? new Response(
                View::render('errors/500', ['ref' => $ref, 'exception' => ErrorHandler::isDebug() ? $e : null], 'layouts/minimal'),
                500
            );
        }
        $response->send();
    }

    private function handle(Request $request): Response
    {
        Session::start($request);

        if ($request->isPost() && !Csrf::verify($request)) {
            throw new HttpException(419, 'Your form expired or was submitted from another site. Please go back, reload the page and try again.');
        }

        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            throw new HttpException(404, 'The page you requested does not exist.');
        }
        [$route, $params] = $match;
        $request->routeParams = $params;

        // Every route except the installer itself requires an installed application.
        $middleware = $route['middleware'];
        if (!str_starts_with($route['path'], '/install')) {
            array_unshift($middleware, 'installed');
        }
        foreach ($middleware as $entry) {
            [$name, $arg] = array_pad(explode(':', $entry, 2), 2, null);
            $class = self::MIDDLEWARE[$name] ?? throw new \LogicException('Unknown middleware ' . $name);
            (new $class())->handle($request, $arg);
        }

        [$controllerClass, $method] = $route['handler'];
        $controller = new $controllerClass($request);
        $result = $controller->$method($request);
        if ($result instanceof Response) {
            return $result;
        }
        return new Response((string) $result);
    }

    /**
     * New code deployed before `bin/migrate.php` ran: queries hit tables or columns that do not
     * exist yet. Explain that instead of a bare 500, and point Fameweb admins at the fix.
     */
    private function pendingUpgradeResponse(\Throwable $e): ?Response
    {
        if (!$e instanceof \PDOException || !in_array((string) $e->getCode(), ['42S02', '42S22'], true)) {
            return null;
        }
        try {
            $pending = Migrator::pending();
            $canUpgrade = $pending && can('platform.settings');
        } catch (\Throwable) {
            return null;
        }
        if (!$pending) {
            return null;
        }
        $message = $canUpgrade
            ? 'The database needs an upgrade before this page can work (' . count($pending) . ' pending). '
                . 'Back up the database, then open Settings and click "Apply upgrades", or run php bin/migrate.php on the server.'
            : 'The system is being upgraded. Please try again in a few minutes.';
        $body = View::render('errors/generic', [
            'status' => 503,
            'title' => 'Upgrade needed',
            'message' => $message,
            'action' => $canUpgrade ? ['label' => 'Open Settings', 'url' => url('admin/settings')] : null,
        ], 'layouts/minimal');
        return new Response($body, 503);
    }

    private function errorResponse(int $status, string $message): Response
    {
        $titles = [
            403 => 'Access denied', 404 => 'Page not found', 405 => 'Method not allowed',
            419 => 'Page expired', 429 => 'Too many requests', 503 => 'Unavailable',
        ];
        $req = Request::current();
        if ($req && str_contains((string) ($req->server['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            return Response::json(['error' => $message ?: ($titles[$status] ?? 'Error')], $status);
        }
        $body = View::render('errors/generic', [
            'status' => $status,
            'title' => $titles[$status] ?? 'Error',
            'message' => $message,
        ], 'layouts/minimal');
        return new Response($body, $status);
    }

    private function sendSecurityHeaders(Request $request): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        // All scripts are local files; no inline script is used anywhere.
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; "
            . "base-uri 'self'; form-action 'self'; object-src 'none'");
        if ($request->isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        // Authenticated pages must not be cached by browsers or proxies.
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
    }
}
