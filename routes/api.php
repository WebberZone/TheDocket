<?php

use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\WebCronController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
|
| Authenticated with personal access tokens (Laravel Sanctum) created on
| the Settings page: send "Authorization: Bearer <token>". Used by the iOS
| Shortcut, and open to browser extensions and anything else later.
|
*/

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::post('items', [ItemController::class, 'store'])->name('api.items.store');
});

// Web cron for hosts without a crontab: a pinger hits this every minute.
// Unauthenticated by design — the URL's token is the secret — and it runs
// without the 'web' group, so no session or cookies are ever set.
Route::get('cron/{token}', WebCronController::class)
    ->middleware('throttle:10,1')
    ->name('cron.run');
