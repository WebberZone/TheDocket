<?php

namespace App\Services\Fetching;

/**
 * A successful response from SafeHttpClient, body already read (and
 * capped).
 */
final class FetchedResponse
{
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly string $contentType,
        public readonly ?string $charset,
        public readonly string $body,
    ) {}

    public function isHtml(): bool
    {
        return $this->contentType === ''
            || in_array($this->contentType, ['text/html', 'application/xhtml+xml'], true);
    }
}
