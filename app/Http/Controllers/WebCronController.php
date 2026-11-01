<?php

namespace App\Http\Controllers;

use App\Services\Installer;
use App\Services\SchedulerHeartbeat;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The web cron endpoint for hosts without a crontab: an external pinger
 * (cron-job.org, UptimeRobot, the host's own "URL cron") hits
 * GET /api/cron/{token} every minute. The token is a server-side secret
 * only the owner can read (Settings page, or the file in storage/), so a
 * wrong one gets the same 404 as an unknown route.
 *
 * The response leaves immediately; the work runs after it, inside a
 * cache lock so overlapping pings don't pile up. The queue is drained
 * INLINE here rather than by the scheduled `queue:work` event, because
 * that event needs proc_open for its background process — exactly the
 * function shared hosts disable. schedule:run is still called for the
 * other events (backups etc.); where proc_open is blocked those fail,
 * which the README warns about.
 */
class WebCronController extends Controller
{
    /**
     * Seconds the queue drain may keep taking new jobs.
     */
    public const MAX_DRAIN_SECONDS = 50;

    /**
     * How long the overlap lock outlives a run that never releases it.
     * --max-time is only checked between jobs, so a run can last the
     * drain window plus one full fetch plus schedule:run (backups) —
     * and max_execution_time doesn't bound that on Linux, where it
     * ignores time spent waiting on the network. Too short a TTL lets
     * a second ping start a second worker mid-run.
     */
    public const LOCK_SECONDS = 900;

    public function __invoke(string $token, Installer $installer): Response
    {
        if (! $this->isInstalled($installer) || ! hash_equals($installer->cronToken(), $token)) {
            abort(404);
        }

        app()->terminating(function (): void {
            $lock = Cache::lock('docket:web-cron', self::LOCK_SECONDS);

            if (! $lock->get()) {
                return;
            }

            @set_time_limit(self::LOCK_SECONDS);
            ignore_user_abort(true);

            try {
                app(SchedulerHeartbeat::class)->beat();

                Artisan::call('queue:work', [
                    '--stop-when-empty' => true,
                    '--tries' => 3,
                    '--max-time' => self::MAX_DRAIN_SECONDS,
                ]);

                // A fetch resets the time limit to its own; give the
                // scheduler a fresh one.
                @set_time_limit(self::LOCK_SECONDS);
                Artisan::call('schedule:run');
            } catch (\Throwable $e) {
                Log::warning('Web cron run failed: '.$e->getMessage());
            } finally {
                // Release once the work is done — the TTL only guards a
                // run that died; holding it the whole time would make
                // every ping for the next fifteen minutes a no-op.
                $lock->release();
            }
        });

        return response('OK', 200, ['Content-Type' => 'text/plain']);
    }

    protected function isInstalled(Installer $installer): bool
    {
        try {
            return $installer->isInstalled();
        } catch (\Throwable) {
            return false;
        }
    }
}
