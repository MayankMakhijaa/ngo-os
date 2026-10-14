<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;

abstract class Controller
{
    /** Layout used by view(); platform and NGO controllers override this. */
    protected string $layout = 'layouts/auth';

    public function __construct(protected Request $request)
    {
    }

    protected function user(): array
    {
        return Auth::user() ?? throw new HttpException(401, 'Not signed in.');
    }

    protected function view(string $template, array $data = []): string
    {
        return View::render($template, $data, $this->layout());
    }

    protected function layout(): string
    {
        return $this->layout;
    }

    protected function redirect(string $path, ?string $type = null, ?string $message = null): Response
    {
        if ($type !== null && $message !== null) {
            Session::flash($type, $message);
        }
        return Response::redirect($path);
    }

    /**
     * Validate request input; on failure redirect back to $backTo with field errors and the
     * previous input (passwords are never echoed back).
     */
    protected function validate(array $rules, string $backTo, array $labels = []): array
    {
        $data = [];
        foreach (array_keys($rules) as $field) {
            $data[$field] = $this->request->str($field);
            if ($this->request->str($field . '_confirmation') !== '') {
                $data[$field . '_confirmation'] = $this->request->str($field . '_confirmation');
            }
        }
        $v = Validator::make($data, $rules, $labels);
        if ($v->fails()) {
            $this->failValidation($v->errors, $backTo);
        }
        return $data;
    }

    /** @return never */
    protected function failValidation(array $errors, string $backTo, ?string $message = null): void
    {
        $old = array_filter(
            $this->request->body,
            static fn ($v, $k) => is_string($k) && !preg_match('/pass|token|_csrf/i', $k),
            ARRAY_FILTER_USE_BOTH
        );
        Session::flash('_errors', $errors);
        Session::flash('_old', $old);
        Session::flash('error', $message ?? 'Please correct the highlighted fields.');
        throw new RedirectException(url($backTo));
    }

    protected function notFound(string $what = 'record'): never
    {
        throw new HttpException(404, 'The ' . $what . ' you requested does not exist or you do not have access to it.');
    }
}
