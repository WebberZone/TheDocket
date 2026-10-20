<?php

namespace App\Http\Controllers;

use App\Services\Exporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Pocket-shaped CSV: title, url, time_added, tags, status. ?for=spreadsheet
     * neutralises cells a spreadsheet would run as formulas.
     */
    public function csv(Request $request, Exporter $exporter): StreamedResponse
    {
        $forSpreadsheets = $request->query('for') === 'spreadsheet';

        return response()->streamDownload(
            function () use ($exporter, $forSpreadsheets): void {
                $handle = fopen('php://output', 'w');

                if ($handle !== false) {
                    $exporter->writeCsv($handle, $forSpreadsheets);
                    fclose($handle);
                }
            },
            'docket-export-'.now()->format('Y-m-d').($forSpreadsheets ? '-spreadsheet' : '').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    /**
     * Everything, including the extracted articles.
     */
    public function json(Exporter $exporter): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($exporter): void {
                $handle = fopen('php://output', 'w');

                if ($handle !== false) {
                    $exporter->writeJson($handle);
                    fclose($handle);
                }
            },
            'docket-export-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }
}
