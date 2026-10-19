<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web installer
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php WITHOUT the 'web' middleware group: the
| database session store (and the database itself) may not exist yet when
| these run, so they use no session, no cookies and no CSRF middleware.
| The EnsureNotInstalled middleware redirects everything to the login
| screen once the owner account exists.
|
*/

Route::get('install', [InstallController::class, 'requirements'])->name('install.requirements');
Route::get('install/setup', [InstallController::class, 'form'])->name('install.form');
Route::post('install/setup', [InstallController::class, 'store'])->name('install.store');
