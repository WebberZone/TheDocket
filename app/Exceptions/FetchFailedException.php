<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A page could not be fetched or read. The message is shown on the item,
 * so it is written for the reader, not for a log file.
 *
 * $permanent marks failures a retry will not fix (a blocked address, an
 * unsupported content type, a 404), so the job records them at once
 * instead of burning through its remaining attempts.
 */
class FetchFailedException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }

    public static function permanent(string $message): self
    {
        return new self($message, true);
    }
}
