<?php

use App\Http\Controllers\Api\ItemController;
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
