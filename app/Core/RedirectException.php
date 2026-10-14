<?php
declare(strict_types=1);

namespace App\Core;

/** Thrown by middleware / controllers to end the request with a redirect. */
final class RedirectException extends \RuntimeException
{
    public function __construct(public readonly string $location)
    {
        parent::__construct('Redirect');
    }
}
