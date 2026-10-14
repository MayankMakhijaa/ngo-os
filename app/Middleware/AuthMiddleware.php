<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Session;
use App\Services\SupportAccessService;

/** Requires a valid, active, signed-in user (re-validated against the database every request). */
final class AuthMiddleware implements Middleware
{
    public function handle(Request $request, ?string $param): void
    {
        // End a Fameweb support session that expired or is no longer allowed before anything else.
        $ended = SupportAccessService::enforce();
        if ($ended !== null) {
            Session::flash('warning', $ended);
            throw new RedirectException(url(Auth::check() ? ltrim(Auth::homePath(), '/') : 'login'));
        }
        if (Auth::check()) {
            if ((int) (Auth::user()['must_change_password'] ?? 0) === 1 && !SupportAccessService::active()
                && !in_array($request->path, ['/account', '/account/password'], true)) {
                if (!Session::getFlash('warning')) {
                    Session::flash('warning', 'Please choose a new password to continue. You signed in with a temporary password.');
                }
                throw new RedirectException(url('account'));
            }
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
