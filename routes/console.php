<?php

use Illuminate\Support\Facades\Schedule;

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
