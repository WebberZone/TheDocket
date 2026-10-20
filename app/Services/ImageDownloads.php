<?php

namespace App\Services;

use App\Models\Item;

/**
 * One article's image-download budget, shared by its inline images and its
 * lead image. Every limit counts attempts, not just successes: a page of
 * broken or oversized images uses the budget up as fast as a page of good
 * ones, so it can't keep a worker busy until the job times out.
 *
 *  - images.max_per_item:  download attempts (0 turns downloads off)
 *  - images.max_total_kb:  bytes downloaded across all attempts
 *  - images.time_budget:   seconds spent downloading
 *  - images.enabled:       master switch, lead image included
 *
 * Each URL is tried once; a lead image that also appears inline reuses the
 * inline copy.
 */
class ImageDownloads
{
    protected int $attemptsLeft;

    protected int $bytesLeft;

    protected float $deadline;

    /**
     * URL => stored filename, or null when it wasn't stored.
     *
     * @var array<string, string|null>
     */
    protected array $results = [];

    public function __construct(
        protected ImageLocaliser $localiser,
        protected Item $item,
    ) {
        $this->attemptsLeft = config('docket.images.enabled') ? max(0, (int) settings('images.max_per_item')) : 0;
        $this->bytesLeft = max(0, (int) config('docket.images.max_total_kb')) * 1024;
        $this->deadline = microtime(true) + max(0, (int) config('docket.images.time_budget'));
    }

    public function localise(string $html): string
    {
        return $this->localiser->rewrite($this->item, $html, fn (string $url): ?string => $this->download($url));
    }

    /**
     * The stored filename for the lead image, if it could be stored.
     */
    public function lead(?string $url): ?string
    {
        return $url === null ? null : $this->download($url);
    }

    /**
     * How many download attempts are left (for tests and logging).
     */
    public function attemptsLeft(): int
    {
        return $this->attemptsLeft;
    }

    protected function download(string $url): ?string
    {
        if (array_key_exists($url, $this->results)) {
            return $this->results[$url];
        }

        $secondsLeft = (int) floor($this->deadline - microtime(true));

        if ($this->attemptsLeft <= 0 || $this->bytesLeft <= 0 || $secondsLeft < 1) {
            return $this->results[$url] = null;
        }

        $this->attemptsLeft--;

        [$filename, $bytes] = $this->localiser->store(
            $this->item,
            $url,
            min((int) config('docket.images.max_kb') * 1024, $this->bytesLeft),
            min($secondsLeft, (int) settings('fetch.timeout')),
        );

        $this->bytesLeft -= $bytes;

        return $this->results[$url] = $filename;
    }
}
