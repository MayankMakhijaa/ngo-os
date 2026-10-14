<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Session;

/** Requires a valid, active, signed-in user (re-validated against the database every request). */
final class AuthMiddleware implements Middleware
{
    public function handle(Request $request, ?string $param): void
    {
        if (Auth::check()) {
            return;
        }
        if ($request->method === 'GET') {
            Session::set('_intended', $request->fullUrlPath());
        }
        if (!Session::getFlash('warning')) {
            Session::flash('info', 'Please sign in to continue.');
        }
        throw new RedirectException(url('login'));
    }
}
