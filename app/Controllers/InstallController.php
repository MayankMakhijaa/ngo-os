<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Exceptions\DomainException;
use App\Services\Installer;
use App\Services\PasswordService;

final class InstallController extends Controller
{
    protected string $layout = 'layouts/auth';

    public function show(): string
    {
        if (Installer::isInstalled()) {
            throw new HttpException(404, 'The page you requested does not exist.');
        }
        $requirements = Installer::requirements();
        $dbError = $requirements['.env file present'] ? Installer::databaseReachable() : null;
        return $this->view('install/index', [
            'title' => 'Install',
            'requirements' => $requirements,
            'dbError' => $dbError,
            'ready' => !in_array(false, $requirements, true) && $dbError === null,
        ]);
    }

    public function install(): Response
    {
        if (Installer::isInstalled()) {
            throw new HttpException(404, 'The page you requested does not exist.');
        }
        $expected = (string) config('app.install_token');
        $given = (string) ($this->request->body['install_token'] ?? '');
        if (strlen($expected) < 16 || !hash_equals($expected, $given)) {
            sleep(1);
            $this->failValidation(['install_token' => 'The installation token does not match INSTALL_TOKEN in .env.'], 'install');
        }
        $data = [
            'name' => $this->request->str('name'),
            'email' => mb_strtolower($this->request->str('email')),
            'password' => (string) ($this->request->body['password'] ?? ''),
        ];
        $v = Validator::make($data, ['name' => 'required|max:120', 'email' => 'required|email']);
        $errors = $v->errors;
        $pw = PasswordService::validate($data['password'], $data['email'], (string) ($this->request->body['password_confirmation'] ?? ''));
        if ($pw) {
            $errors['password'] = implode(' ', $pw);
        }
        if ($errors) {
            $this->failValidation($errors, 'install');
        }
        try {
            Installer::install($data);
        } catch (DomainException $e) {
            return $this->redirect('install', 'error', $e->getMessage());
        }
        Session::flash('success', 'Installation complete. Sign in with the administrator account you just created, then remove INSTALL_TOKEN from .env.');
        return Response::redirect('login');
    }
}
