<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\RedirectException;
use App\Core\Request;

/** Pages for signed-out visitors (login, forgot password): signed-in users go to their dashboard. */
final class GuestMiddleware implements Middleware
{
    public function handle(Request $request, ?string $param): void
    {
        if (Auth::check()) {
            throw new RedirectException(url(Auth::homePath()));
        }
    }
}
