<?php

namespace App\Services\Extraction;

use Carbon\CarbonImmutable;

/**
 * What ArticleExtractor found on a page. $html is the raw readability
 * output (not yet sanitised) and is null when no article could be found.
 */
final class ExtractedArticle
{
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $author,
        public readonly ?string $siteName,
        public readonly ?string $excerpt,
        public readonly ?string $leadImageUrl,
        public readonly ?string $html,
        public readonly ?string $language,
        public readonly ?CarbonImmutable $publishedAt,
    ) {}
}
