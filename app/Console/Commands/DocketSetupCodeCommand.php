<?php

namespace App\Console\Commands;

use App\Exceptions\InstallStateUnknownException;
use App\Services\Installer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

#[Signature('docket:setup-code')]
#[Description('Print the one-time code the web installer asks for')]
class DocketSetupCodeCommand extends Command
{
    public function handle(Installer $installer): int
    {
        try {
            if ($installer->isInstalled()) {
                $this->components->warn('Docket is already installed; the setup code is no longer needed.');

                return SymfonyCommand::FAILURE;
            }
        } catch (InstallStateUnknownException $e) {
            $this->components->error($e->getMessage());

            return SymfonyCommand::FAILURE;
        }

        $this->line($installer->setupCode());

        return SymfonyCommand::SUCCESS;
    }
}
