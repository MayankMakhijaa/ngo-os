<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\RedirectException;
use App\Core\Request;
use App\Services\Installer;

final class InstalledMiddleware implements Middleware
{
    public function handle(Request $request, ?string $param): void
    {
        if (!Installer::isInstalled()) {
            throw new RedirectException(url('install'));
        }
    }
}
