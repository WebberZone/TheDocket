<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Services\PocketImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use RuntimeException;

/**
 * Pocket import (and the page the export links live on).
 */
class ImportController extends Controller
{
    public function index(): View
    {
        return view('import.index', [
            'itemCount' => Item::query()->count(),
            'maxKb' => (int) config('docket.import.max_kb'),
        ]);
    }

    public function store(Request $request, PocketImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.(int) config('docket.import.max_kb')],
        ], [
            'file.mimes' => 'Upload the .csv file from Pocket\'s export.',
        ]);

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            return back()->withErrors(['file' => 'Upload one CSV file.']);
        }

        try {
            $result = $importer->import((string) $file->getRealPath());
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('import.index')->with('status', $result->summary());
    }
}
