<?php
declare(strict_types=1);

// Front controller. Every request that is not a static asset is routed here by .htaccess.

// PHP built-in server (development/tests): let it serve existing static files directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

// The application lives one level above the web root by default. If your host forces the
// web root elsewhere (see docs/DEPLOYMENT.md), create app_path.php next to this file
// returning the absolute path of the application directory.
$appRoot = is_file(__DIR__ . '/app_path.php') ? require __DIR__ . '/app_path.php' : dirname(__DIR__);

$app = require $appRoot . '/bootstrap/app.php';
$app->run();
