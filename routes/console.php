<?php

use App\Services\SchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Driven by one system cron on the server:
|   * * * * * cd /var/www/docket && php artisan schedule:run >> /dev/null 2>&1
|
| Times are in APP_TIMEZONE. The heartbeat is touched every minute so the
| dashboard can warn when this cron stops running.
|
| Queue and backup events are only registered once installation has
| finished — the marker file is checked directly (this file loads for
| EVERY artisan command, including the installer itself, when there is
| no .env or database yet, so no query may happen here). Before that
| point only the heartbeat runs, which is harmless.
|
| With docket.queue.via_scheduler on (the default), the same cron also
| drains the queue every minute — no systemd/supervisor worker needed.
| queue:work exits once the queue is empty (and at --max-time either way),
| so this is a reaper, not a daemon; withoutOverlapping keeps a slow run
| from piling up. Set DOCKET_QUEUE_VIA_SCHEDULER=false when a dedicated
| worker service runs instead.
|
| Prune old backups first, then take the new one, then verify the newest
| backup is fresh enough (emails if not).
|
*/

$installed = is_file(rtrim((string) config('docket.install.state_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'installed');

Schedule::call(fn () => app(SchedulerHeartbeat::class)->beat())->everyMinute();

if ($installed) {
    if (config('docket.queue.via_scheduler')) {
        Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=300 --max-time=55')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->runInBackground();
    }

    Schedule::command('backup:clean')->dailyAt('01:30');
    Schedule::command('backup:run')->dailyAt('02:00');
    Schedule::command('backup:monitor')->dailyAt('08:00');
}
