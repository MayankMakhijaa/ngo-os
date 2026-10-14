<?php
declare(strict_types=1);

namespace App\Exceptions;

/** A business-rule violation with a message that is safe to show to the user. */
final class DomainException extends \RuntimeException
{
}
