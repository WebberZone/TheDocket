<?php

namespace App\Console\Commands;

use App\Services\PocketImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

#[Signature('docket:import-pocket
    {file : Path to the CSV from Pocket\'s export (title, url, time_added, tags, status)}')]
#[Description('Import a Pocket export CSV, keeping dates, tags and status; safe to re-run')]
class DocketImportPocketCommand extends Command
{
    public function handle(PocketImporter $importer): int
    {
        $file = (string) $this->argument('file');

        try {
            $result = $importer->import($file);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return SymfonyCommand::FAILURE;
        }

        $this->components->info($result->summary());

        if ($result->queued > 0) {
            $this->components->bulletList([
                'Extraction runs on the queue: keep php artisan queue:work running until it finishes.',
            ]);
        }

        return SymfonyCommand::SUCCESS;
    }
}
