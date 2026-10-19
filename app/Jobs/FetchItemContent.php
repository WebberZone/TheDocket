<?php

namespace App\Jobs;

use App\Enums\FetchStatus;
use App\Enums\ItemSource;
use App\Exceptions\FetchFailedException;
use App\Models\Item;
use App\Services\Extraction\ArticleExtractor;
use App\Services\Fetching\SafeHttpClient;
use App\Services\HtmlSanitiser;
use App\Services\ImageLocaliser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetch a saved page, extract the article, sanitise it, download its
 * images and store the lot on the item.
 *
 * Failures the reader can act on (blocked address, not a web page, HTTP
 * 404, too large) are recorded on the item straight away; transient ones
 * (timeouts, 5xx) are retried with backoff and recorded once the attempts
 * run out. Either way the item stays in the list with its link, and the
 * reader can retry it.
 */
class FetchItemContent implements ShouldQueue
{
    use Queueable;

    /**
     * Image downloads can add up; this is the ceiling for the whole job.
     */
    public int $timeout = 300;

    /**
     * Deleted before the worker got to it: nothing to do.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Item $item) {}

    public function tries(): int
    {
        return max(1, (int) settings('fetch.tries'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        $backoff = settings('fetch.backoff');

        return is_array($backoff) && $backoff !== [] ? array_values(array_map('intval', $backoff)) : [30];
    }

    public function handle(
        SafeHttpClient $http,
        ArticleExtractor $extractor,
        HtmlSanitiser $sanitiser,
        ImageLocaliser $images,
    ): void {
        $item = $this->item;
        $item->forceFill(['fetch_attempts' => $item->fetch_attempts + 1])->saveQuietly();

        try {
            $response = $http->get(
                $item->url,
                max(1, (int) settings('fetch.max_kb')) * 1024,
                'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'
            );

            if (! $response->isHtml()) {
                throw FetchFailedException::permanent(
                    "This link is {$response->contentType}, not a web page, so there is no article to extract. The original link still works."
                );
            }

            $article = $extractor->extract($response->body, $response->url, $response->charset);
        } catch (FetchFailedException $e) {
            if ($e->permanent || $this->isFinalAttempt()) {
                $this->recordFailure($e->getMessage());

                return;
            }

            throw $e;
        }

        $html = $article->html !== null ? $sanitiser->sanitise($article->html) : '';

        $item->forceFill([
            'resolved_url' => $response->url !== $item->url ? $response->url : null,
            'author' => $article->author,
            'site_name' => $article->siteName,
            'lead_image_url' => $article->leadImageUrl,
            'language' => $article->language,
            'published_at' => $article->publishedAt,
        ]);

        if ($this->shouldReplaceTitle($item) && $article->title !== null) {
            $item->title = $article->title;
        }

        if (trim(strip_tags($html, '<img>')) === '') {
            $item->forceFill(['excerpt' => $article->excerpt])->save();
            $this->recordFailure('Could not find any article text on this page. The original link still works.');

            return;
        }

        $html = $images->localise($item, $html);
        $text = HtmlSanitiser::toText($html);
        $words = HtmlSanitiser::wordCount($text);
        $wordsPerMinute = max(1, (int) settings('words_per_minute'));

        $item->forceFill([
            'content_html' => $html,
            'content_text' => $text,
            'excerpt' => $article->excerpt ?? Str::limit(str_replace("\n", ' ', $text), 280),
            'word_count' => $words,
            'reading_time' => $words > 0 ? (int) max(1, ceil($words / $wordsPerMinute)) : 0,
            'lead_image_path' => $article->leadImageUrl !== null
                ? $images->store($item, $article->leadImageUrl)
                : null,
            'fetch_status' => FetchStatus::Fetched,
            'fetch_error' => null,
            'fetched_at' => now(),
        ])->save();
    }

    /**
     * Something unexpected (not a FetchFailedException) used up the last
     * attempt. Record it so the item doesn't sit at "pending" for ever.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $this->recordFailure('Extraction failed unexpectedly. Try again, or open the original link.');
    }

    protected function recordFailure(string $message): void
    {
        $this->item->forceFill([
            'fetch_status' => FetchStatus::Failed,
            'fetch_error' => mb_substr($message, 0, 1000),
        ])->save();
    }

    /**
     * A sync (or directly invoked) job has no retries to fall back on.
     */
    protected function isFinalAttempt(): bool
    {
        return $this->job === null
            || $this->job instanceof SyncJob
            || $this->attempts() >= $this->tries();
    }

    /**
     * The extracted title wins over a missing one, a bare URL, or the
     * browser tab title a bookmarklet or share sheet sent along (which
     * usually carries the site name too). A title typed in by hand, or
     * one already replaced by an earlier fetch, is kept.
     */
    protected function shouldReplaceTitle(Item $item): bool
    {
        $title = trim((string) $item->title);

        return $title === ''
            || $title === $item->url
            || (in_array($item->source, [ItemSource::Bookmarklet, ItemSource::Share], true) && $item->fetched_at === null);
    }
}
