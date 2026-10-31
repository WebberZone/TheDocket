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
    public function __invoke(string $token, Installer $installer): Response
    {
        if (! $this->isInstalled($installer) || ! hash_equals($installer->cronToken(), $token)) {
            abort(404);
        }

        app()->terminating(function (): void {
            if (! Cache::lock('docket:web-cron', 300)->get()) {
                return;
            }

            @set_time_limit(300);
            ignore_user_abort(true);

            try {
                app(SchedulerHeartbeat::class)->beat();

                Artisan::call('queue:work', [
                    '--stop-when-empty' => true,
                    '--tries' => 3,
                    '--max-time' => 50,
                ]);

                Artisan::call('schedule:run');
            } catch (\Throwable $e) {
                Log::warning('Web cron run failed: '.$e->getMessage());
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
