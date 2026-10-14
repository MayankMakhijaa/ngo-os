<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AuthService;
use App\Services\SettingsService;
use App\Services\TokenService;

final class AuthController extends Controller
{
    protected string $layout = 'layouts/auth';

    public function showLogin(): string
    {
        return $this->view('auth/login', ['title' => 'Sign in']);
    }

    public function login(): Response
    {
        $email = $this->request->str('email');
        $password = (string) ($this->request->body['password'] ?? '');
        $v = Validator::make(['email' => $email, 'password' => $password], ['email' => 'required|email', 'password' => 'required|max:200']);
        if ($v->fails()) {
            $this->failValidation($v->errors, 'login', 'Please enter your email and password.');
        }
        $result = AuthService::attempt($email, $password, $this->request);
        if (!$result['ok']) {
            Session::flash('_old', ['email' => $email]);
            return $this->redirect('login', 'error', $result['error']);
        }
        $intended = (string) Session::pull('_intended', '');
        $home = Auth::homePath($result['user']);
        // Only follow an intended URL inside the user's own area (never an absolute/external URL).
        if ($intended !== '' && str_starts_with($intended, $home) && !str_starts_with($intended, '//')) {
            return Response::redirect($intended);
        }
        return $this->redirect($home, 'success', 'Welcome back, ' . $result['user']['name'] . '.');
    }

    public function logout(): Response
    {
        AuthService::logout();
        return $this->redirect('login', 'success', 'You have been signed out.');
    }

    public function showForgot(): string
    {
        return $this->view('auth/forgot', [
            'title' => 'Forgot password',
            'enabled' => SettingsService::get('allow_password_reset') === '1',
        ]);
    }

    public function forgot(): Response
    {
        $data = $this->validate(['email' => 'required|email'], 'forgot-password');
        AuthService::requestReset($data['email'], $this->request);
        return $this->redirect('login', 'success',
            'If an active account exists for that email, a password reset link has been sent. The link is valid for a limited time.');
    }

    public function showReset(): string
    {
        $token = $this->request->param('token');
        $row = TokenService::find($token, 'reset');
        header('Referrer-Policy: no-referrer');
        return $this->view('auth/reset', ['title' => 'Choose a new password', 'valid' => (bool) $row, 'token' => $token, 'email' => $row['email'] ?? '']);
    }

    public function reset(): Response
    {
        $token = $this->request->param('token');
        $errors = AuthService::completeToken(
            $token, 'reset',
            (string) ($this->request->body['password'] ?? ''),
            (string) ($this->request->body['password_confirmation'] ?? '')
        );
        if ($errors) {
            Session::flash('_errors', ['password' => implode(' ', $errors)]);
            return $this->redirect('reset-password/' . $token, 'error', 'Your password could not be changed.');
        }
        return $this->redirect('login', 'success', 'Your password has been changed. Please sign in.');
    }

    public function showInvite(): string
    {
        $token = $this->request->param('token');
        $row = TokenService::find($token, 'invite');
        header('Referrer-Policy: no-referrer');
        return $this->view('auth/invite', [
            'title' => 'Activate your account', 'valid' => (bool) $row, 'token' => $token,
            'name' => $row['name'] ?? '', 'email' => $row['email'] ?? '',
        ]);
    }

    public function acceptInvite(): Response
    {
        $token = $this->request->param('token');
        $errors = AuthService::completeToken(
            $token, 'invite',
            (string) ($this->request->body['password'] ?? ''),
            (string) ($this->request->body['password_confirmation'] ?? '')
        );
        if ($errors) {
            Session::flash('_errors', ['password' => implode(' ', $errors)]);
            return $this->redirect('invite/' . $token, 'error', 'Your account could not be activated.');
        }
        return $this->redirect('login', 'success', 'Your account is active. Sign in with your new password.');
    }
}
