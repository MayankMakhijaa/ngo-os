<?php
declare(strict_types=1);

namespace App\Core;

/** Thrown to stop a request with an HTTP status and a safe, user-facing message. */
class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message, $status);
    }
}
