<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Import the legacy Accounts Tracker workbook through the web interface.
 *
 * Uploads the XLSX to a temporary private disk, runs the Python importer
 * in a sub-process, then reports rows inserted and skipped. Duplicate
 * detection lives in the Python script (occurred_on, counterparty,
 * amount_gbp_minor), so re-importing the same file is idempotent.
 */
class ImportController extends Controller
{
    public function index(): View
    {
        return view('import.index');
    }

    public function store(ImportRequest $request): RedirectResponse
    {
        $file = $request->file('tracker');
        $path = $file->storeAs('tmp', 'tracker-import-'.uniqid().'.xlsx', ['disk' => 'local']);
        $fullPath = Storage::disk('local')->path($path);

        $process = new Process(
            [
                config('docket.import.python_bin'),
                config('docket.import.script'),
                $fullPath,
                '--db',
                config('database.connections.sqlite.database'),
            ]
        );

        try {
            $process->run();
        } finally {
            Storage::disk('local')->delete($path);
        }

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $output = trim($process->getOutput());

        return redirect()->route('import.index')->with('status', $output);
    }
}
