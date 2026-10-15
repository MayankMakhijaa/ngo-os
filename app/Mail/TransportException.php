<?php
declare(strict_types=1);

namespace App\Mail;

/** Delivery failure. $permanent = the server rejected the message for good (5xx), so do not retry. */
final class TransportException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }
}
