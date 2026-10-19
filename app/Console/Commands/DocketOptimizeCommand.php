<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

#[Signature('docket:optimize')]
#[Description('Re-cache routes, views and events for the tools page')]
class DocketOptimizeCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Caching framework routes, views and events.');

        $tasks = [
            'events' => 'event:cache',
            'routes' => 'route:cache',
            'views' => 'view:cache',
        ];

        $failed = false;

        foreach ($tasks as $description => $command) {
            $success = true;

            $this->components->task(
                $description,
                function () use ($command, &$success): void {
                    $success = Artisan::call($command) === 0;
                }
            );

            if (! $success) {
                $failed = true;
            }
        }

        $this->newLine();

        return $failed ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
