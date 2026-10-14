<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;

final class HomeController extends Controller
{
    public function index(): Response
    {
        return Response::redirect(Auth::check() ? Auth::homePath() : '/login');
    }
}
