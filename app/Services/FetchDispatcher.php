<?php

namespace App\Services;

use App\Jobs\FetchItemContent;
use App\Models\Item;

/**
 * How a single fetch gets run, decided by what background machinery the
 * host actually has:
 *
 *  - sync queue        → dispatch as before (runs inside the save request).
 *  - scheduler healthy → queue it; the cron (or the web cron URL) drains it.
 *  - nothing running   → run it after the HTTP response so the reader still
 *    gets fetched pages with no cron at all; in console, run it inline.
 *
 * Spaced bulk work (Pocket import, refetch) does NOT go through here —
 * it always needs a working queue, and the UI says so.
 */
class FetchDispatcher
{
    public function dispatch(Item $item): void
    {
        if (config('queue.default') === 'sync') {
            FetchItemContent::dispatch($item);

            return;
        }

        if (app(SchedulerHeartbeat::class)->isHealthy()) {
            FetchItemContent::dispatch($item);

            return;
        }

        if (! app()->runningInConsole()) {
            FetchItemContent::dispatchAfterResponse($item);

            return;
        }

        FetchItemContent::dispatchSync($item);
    }
}
