<?php

namespace App\Exceptions;

/**
 * A fetch refused before any connection was made, because the URL (or a
 * redirect it led to) points at a private, loopback, link-local or
 * otherwise non-public address. Always permanent.
 */
class UnsafeUrlException extends FetchFailedException
{
    public function __construct(string $message)
    {
        parent::__construct($message, true);
    }
}
