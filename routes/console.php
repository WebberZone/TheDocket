<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command(
    'inspire', function () {
        $this->comment(Inspiring::quote());
    }
)->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Driven by one system cron on the server:
|   * * * * * cd /var/www/docket && php artisan schedule:run >> /dev/null 2>&1
|
| Times are server-local. Prune old backups first, then take the new one,
| then verify the newest backup is fresh enough (emails if not).
*/

Schedule::command('backup:clean')->dailyAt('01:30');
Schedule::command('backup:run')->dailyAt('02:00');
Schedule::command('backup:monitor')->dailyAt('08:00');

// Abandoned captures (photo taken, form never finished) leave a file in
// receipts/pending/ behind after their cache entry expires.
Schedule::command('docket:prune-captures')->daily();

// OCI Always Free reclaims instances that look idle (<20% CPU 95th
// percentile over 7 days). A single-user app looks idle; this burns a
// little CPU regularly so the instance stays above the reclaim line.
Schedule::exec('nice -n 19 sh -c "timeout 300 sha256sum /dev/zero || true"')
    ->everyThirtyMinutes()
    ->environments(['production']);
