<?php
declare(strict_types=1);

/*
 * Application bootstrap: autoloading, environment, configuration, error handling.
 * Used by the web front controller (public/index.php), CLI scripts (bin/) and tests.
 */

define('BASE_PATH', dirname(__DIR__));

// Composer autoloader when present (optional; the app has no third-party PHP dependencies).
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require_once BASE_PATH . '/app/Helpers/functions.php';

App\Core\Env::load(getenv('APP_ENV_FILE') ?: BASE_PATH . '/.env');
App\Core\Config::load(BASE_PATH . '/config');

date_default_timezone_set((string) config('app.timezone', 'Asia/Kolkata'));
mb_internal_encoding('UTF-8');

App\Core\ErrorHandler::register((bool) config('app.debug', false));

return new App\Core\Application();
