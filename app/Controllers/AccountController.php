<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditLogger;
use App\Services\PasswordService;

/** The signed-in user's own profile and password. */
final class AccountController extends Controller
{
    protected function layout(): string
    {
        return Auth::isPlatform() ? 'layouts/platform' : Ngo\NgoController::layoutFor(Auth::user());
    }

    public function show(): string
    {
        if (!Auth::isPlatform()) {
            (new \App\Middleware\TenantMiddleware())->handle($this->request, null);
        }
        return $this->view('account/show', ['title' => 'My account', 'me' => $this->user()]);
    }

    public function updateProfile(): Response
    {
        $data = $this->validate(['name' => 'required|max:120', 'phone' => 'phone|max:30'], 'account');
        $me = $this->user();
        Database::instance()->update('users', ['name' => $data['name'], 'phone' => $data['phone'] ?: null], ['id' => (int) $me['id']]);
        AuditLogger::log('user.updated', 'user', $me['id'], ['fields' => ['name', 'phone'], 'self' => true]);
        return $this->redirect('account', 'success', 'Your profile has been updated.');
    }

    public function changePassword(): Response
    {
        $me = $this->user();
        $current = (string) ($this->request->body['current_password'] ?? '');
        $new = (string) ($this->request->body['password'] ?? '');
        $hash = (string) Database::instance()->fetchColumn('SELECT password_hash FROM users WHERE id = ?', [(int) $me['id']]);
        if (!password_verify($current, $hash)) {
            $this->failValidation(['current_password' => 'Your current password is incorrect.'], 'account');
        }
        $errors = PasswordService::validate($new, $me['email'], (string) ($this->request->body['password_confirmation'] ?? ''));
        if ($errors) {
            $this->failValidation(['password' => implode(' ', $errors)], 'account');
        }
        PasswordService::setPassword((int) $me['id'], $new);
        AuditLogger::log('auth.password_changed', 'user', $me['id']);
        // setPassword ended all sessions; keep this one by signing in again with a new session id.
        $fresh = Database::instance()->fetch('SELECT * FROM users WHERE id = ?', [(int) $me['id']]);
        Auth::login($fresh);
        Session::flash('success', 'Your password has been changed. Other devices have been signed out.');
        return Response::redirect('account');
    }
}
