<?php
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Controllers\Controller;

abstract class PlatformController extends Controller
{
    protected string $layout = 'layouts/platform';
}
