<?php

namespace App\Console\Commands;

use App\Services\Installer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

#[Signature('docket:check-requirements')]
#[Description('Print the server requirements checklist; exits non-zero when a blocking requirement fails')]
class DocketCheckRequirementsCommand extends Command
{
    public function handle(Installer $installer): int
    {
        $this->table(
            ['Requirement', 'Status', 'Hint'],
            collect($installer->requirements())->map(fn (array $check): array => [
                $check['label'],
                $check['ok'] ? 'Pass' : ($check['blocking'] ? 'Fail' : 'Optional'),
                $check['ok'] ? '' : $check['hint'],
            ])->all()
        );

        $problems = $installer->blockingProblems();

        if ($problems !== []) {
            $this->components->error(count($problems).' blocking requirement(s) failed.');

            return SymfonyCommand::FAILURE;
        }

        $this->components->info('All requirements pass.');

        return SymfonyCommand::SUCCESS;
    }
}
