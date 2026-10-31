<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Proof that the scheduler cron is running. schedule:run calls beat()
 * every minute and the dashboard warns when the file goes stale.
 *
 * Deliberately file-based, not the cache: `optimize:clear` and friends
 * flush caches, and a cleared heartbeat would look exactly like a dead
 * cron — a false alarm on the very command admins run while debugging.
 */
class SchedulerHeartbeat
{
    /**
     * Minutes without a beat before the dashboard warning appears.
     */
    public const STALE_AFTER_MINUTES = 5;

    public function beat(): void
    {
        $path = $this->path();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        touch($path);
    }

    public function lastBeat(): ?CarbonInterface
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        $mtime = filemtime($path);

        return $mtime === false ? null : Carbon::createFromTimestamp($mtime);
    }

    public function isHealthy(): bool
    {
        $last = $this->lastBeat();

        return $last !== null && $last->gte(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public function path(): string
    {
        return (string) (config('docket.scheduler.heartbeat_path') ?: storage_path('app/scheduler-heartbeat'));
    }
}
