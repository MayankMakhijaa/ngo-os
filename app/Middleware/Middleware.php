<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;

interface Middleware
{
    /** Throw HttpException / RedirectException to stop the request; return to continue. */
    public function handle(Request $request, ?string $param): void;
}
