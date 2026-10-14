<?php
declare(strict_types=1);

namespace App\Exceptions;

/** A plan limit would be exceeded. The message is safe to show to the authorised user. */
final class LimitExceededException extends \RuntimeException
{
}
