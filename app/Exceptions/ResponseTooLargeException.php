<?php

namespace App\Exceptions;

/**
 * A download abandoned at its size cap. Permanent, and lets callers that
 * keep a byte budget (ImageDownloads) charge the cap that was spent.
 */
class ResponseTooLargeException extends FetchFailedException
{
    public function __construct(string $message, public readonly int $limit)
    {
        parent::__construct($message, true);
    }
}
