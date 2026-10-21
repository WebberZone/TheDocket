<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\BulkItemController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ItemImageController;
use App\Http\Controllers\ItemStateController;
use App\Http\Controllers\OfflineController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SaveController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\ToolsController;
use App\Services\ImageLocaliser;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(
    function () {
        // The lists. The route default picks the view (the tag list has
        // its own action), and ?sort= / ?q= refine it.
        Route::get('/', [ItemController::class, 'index'])->defaults('view', 'unread')->name('items.index');
        Route::get('archive', [ItemController::class, 'index'])->defaults('view', 'archive')->name('items.archive');
        Route::get('favourites', [ItemController::class, 'index'])->defaults('view', 'favourites')->name('items.favourites');
        Route::get('search', [ItemController::class, 'index'])->defaults('view', 'search')->name('items.search');
        Route::get('tags/{tag}', [ItemController::class, 'tagged'])->whereNumber('tag')->name('items.tagged');

        Route::post('items', [ItemController::class, 'store'])->name('items.store');
        Route::post('items/bulk', BulkItemController::class)->name('items.bulk');
        Route::get('items/{item}', [ItemController::class, 'show'])->name('items.show');
        Route::patch('items/{item}', [ItemController::class, 'update'])->name('items.update');
        Route::delete('items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');

        Route::post('items/{item}/archive', [ItemStateController::class, 'archive'])->name('items.archive.store');
        Route::delete('items/{item}/archive', [ItemStateController::class, 'unarchive'])->name('items.archive.destroy');
        Route::post('items/{item}/favourite', [ItemStateController::class, 'favourite'])->name('items.favourite.store');
        Route::delete('items/{item}/favourite', [ItemStateController::class, 'unfavourite'])->name('items.favourite.destroy');
        Route::post('items/{item}/retry', [ItemStateController::class, 'retry'])->name('items.retry');
        Route::post('items/{item}/progress', [ItemStateController::class, 'progress'])->name('items.progress');

        Route::get('items/{item}/images/{filename}', ItemImageController::class)
            ->where('filename', ImageLocaliser::FILENAME_PATTERN)
            ->name('items.image');

        // What the service worker keeps on the device for offline reading.
        Route::get('offline/articles', OfflineController::class)->name('offline.articles');

        // Bookmarklet and PWA share target. GET, because both arrive as a
        // plain navigation from another page or app.
        Route::get('save', [SaveController::class, 'bookmarklet'])->name('save.bookmarklet');
        Route::get('share', [SaveController::class, 'share'])->name('save.share');

        Route::get('tags', [TagController::class, 'index'])->name('tags.index');
        Route::patch('tags/{tag}', [TagController::class, 'update'])->whereNumber('tag')->name('tags.update');
        Route::delete('tags/{tag}', [TagController::class, 'destroy'])->whereNumber('tag')->name('tags.destroy');

        Route::get('import', [ImportController::class, 'index'])->name('import.index');
        Route::post('import', [ImportController::class, 'store'])->name('import.store');

        Route::get('export/csv', [ExportController::class, 'csv'])->name('export.csv');
        Route::get('export/json', [ExportController::class, 'json'])->name('export.json');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
        Route::delete('settings/tokens/{token}', [ApiTokenController::class, 'destroy'])->whereNumber('token')->name('tokens.destroy');

        // Maintenance tools: {tool} is a key into ToolsController::tools(),
        // never a raw command; unknown keys 404.
        Route::get('tools', [ToolsController::class, 'index'])->name('tools.index');
        Route::post('tools/{tool}', [ToolsController::class, 'run'])->name('tools.run');

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    }
);

require __DIR__.'/auth.php';
