<?php

use App\Http\Controllers\AccountBalanceController;
use App\Http\Controllers\CaptureController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ToolsController;
use App\Http\Controllers\TrackerExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// Breeze redirects here after login.
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth'])
    ->name('dashboard');

Route::middleware('auth')->group(
    function () {
        // The three ledgers share one controller/views; export is declared
        // before each resource so it isn't swallowed by the {id} wildcard.
        Route::get('expenses/export', [ExpenseController::class, 'export'])->name('expenses.export');
        Route::resource('expenses', ExpenseController::class);

        Route::get('income/export', [IncomeController::class, 'export'])->name('income.export');
        Route::resource('income', IncomeController::class)->parameters(['income' => 'id']);

        Route::get('cashflows/export', [CashFlowController::class, 'export'])->name('cashflows.export');
        Route::resource('cashflows', CashFlowController::class);

        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

        Route::get('balances', [AccountBalanceController::class, 'index'])->name('balances.index');
        Route::post('balances', [AccountBalanceController::class, 'store'])->name('balances.store');

        Route::get('ledger', [LedgerController::class, 'index'])->name('ledger.index');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');

        // Maintenance tools: {tool} is a key into ToolsController::tools(),
        // never a raw command — unknown keys 404.
        Route::get('tools', [ToolsController::class, 'index'])->name('tools.index');
        Route::post('tools/{tool}', [ToolsController::class, 'run'])->name('tools.run');

        // Lookup taxonomy: categories, payment methods, business units.
        Route::get('lookups', [LookupController::class, 'index'])->name('lookups.index');
        Route::get('lookups/{type}/create', [LookupController::class, 'create'])->name('lookups.create');
        Route::post('lookups/{type}', [LookupController::class, 'store'])->name('lookups.store');
        Route::get('lookups/{type}/{id}/edit', [LookupController::class, 'edit'])->name('lookups.edit');
        Route::patch('lookups/{type}/{id}', [LookupController::class, 'update'])->name('lookups.update');
        Route::delete('lookups/{type}/{id}', [LookupController::class, 'destroy'])->name('lookups.destroy');

        Route::get('export-tracker', [TrackerExportController::class, 'tracker'])->name('tracker.export');

        Route::get('import', [ImportController::class, 'index'])->name('import.index');
        Route::post('import', [ImportController::class, 'store'])->name('import.store');

        Route::get('capture', [CaptureController::class, 'create'])->name('capture.create');
        Route::post('capture', [CaptureController::class, 'store'])->name('capture.store');
        Route::get('capture/{token}', [CaptureController::class, 'confirm'])->name('capture.confirm');
        Route::get('capture/{token}/status', [CaptureController::class, 'status'])->name('capture.status');
        Route::get('capture/{token}/photo', [CaptureController::class, 'photo'])->name('capture.photo');

        Route::post('expenses/{expense}/receipts', [ReceiptController::class, 'store'])->name('receipts.store');
        Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    }
);

require __DIR__.'/auth.php';
