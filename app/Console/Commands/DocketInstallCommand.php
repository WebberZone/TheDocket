<?php

namespace App\Console\Commands;

use App\Exceptions\InstallStateUnknownException;
use App\Services\Installer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('docket:install
    {--url= : Public URL the app will be served from}
    {--name= : Owner account name}
    {--email= : Owner account email}
    {--password= : Owner account password (auto-generated when omitted non-interactively)}
    {--force : Run even when an account already exists}')]
#[Description('Install Docket: create .env, generate the app key, migrate the database and create the owner account')]
class DocketInstallCommand extends Command
{
    /**
     * A password generated for a non-interactive install with no --password,
     * remembered so the closing summary can show it exactly once.
     */
    protected ?string $generatedPassword = null;

    public function handle(Installer $installer): int
    {
        $this->components->info('Installing Docket.');

        $problems = $installer->blockingProblems();

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }

            return SymfonyCommand::FAILURE;
        }

        try {
            $installed = $installer->isInstalled();
        } catch (InstallStateUnknownException $e) {
            $this->components->error($e->getMessage());

            return SymfonyCommand::FAILURE;
        }

        if ($installed && ! $this->option('force')) {
            $this->components->warn(
                'Docket already has a user account. Re-run with --force to run the installer anyway (existing data is never deleted).'
            );

            return SymfonyCommand::FAILURE;
        }

        if ($installer->ensureEnvFile()) {
            $this->components->task('creating .env from .env.example', fn (): bool => true);
        }

        if ($installer->ensureAppKey()) {
            $this->components->task('generating application key', fn (): bool => true);
        }

        $url = $this->option('url')
            ?? $this->promptOr(fn (): string => text(
                label: 'What URL will Docket be served from?',
                default: 'http://localhost:8000',
                required: true,
            ), 'http://localhost:8000');

        $name = $this->option('name')
            ?? $this->promptOr(fn (): string => text(
                label: 'Your name',
                default: 'Docket Owner',
                required: true,
            ), 'Docket Owner');

        $email = $this->option('email')
            ?? $this->promptOr(fn (): string => text(
                label: 'Your email (used to log in)',
                default: 'owner@example.com',
                required: true,
                validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL)
                    ? null
                    : 'Enter a valid email address.',
            ), 'owner@example.com');

        $plainPassword = $this->option('password') ?? $this->askForPassword();

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error("'{$email}' is not a valid email address.");

            return SymfonyCommand::FAILURE;
        }

        if (strlen($plainPassword) < 8) {
            $this->components->error('The password must be at least 8 characters.');

            return SymfonyCommand::FAILURE;
        }

        // Serialised with the web installer, so the two can't race each
        // other into creating two accounts.
        $lock = $installer->acquireLock();

        if ($lock === null) {
            $this->components->error('Another installation is running right now. Try again in a moment.');

            return SymfonyCommand::FAILURE;
        }

        try {
            $installer->ensureSqliteDatabase();

            $this->components->task('running database migrations', fn (): bool => $installer->migrate());

            if ($installer->ownerExists($email)) {
                $this->components->warn("An account for {$email} already exists — its password was left unchanged.");
            } elseif ($installer->hasOwner()) {
                $this->components->warn('Docket already has its owner account, under a different email. It has one account, so no new one was created; change the email on the Profile page instead.');

                return SymfonyCommand::FAILURE;
            } else {
                $this->components->task(
                    "creating owner account for {$email}",
                    function () use ($installer, $name, $email, $plainPassword): bool {
                        $installer->createOwner($name, $email, $plainPassword);

                        return true;
                    }
                );
            }

            // Written once the account is in place, so a refused install
            // leaves .env alone. The password is deliberately NOT written
            // to .env: it is hashed straight into the database.
            $installer->writeEnvValues([
                'APP_URL' => $url,
                'DOCKET_USER_NAME' => $name,
                'DOCKET_USER_EMAIL' => $email,
            ]);
            $this->components->task('.env updated (APP_URL, owner name/email)', fn (): bool => true);

            $installer->markInstalled();
        } finally {
            $installer->releaseLock($lock);
        }

        $this->printNextSteps($url, $email);

        return SymfonyCommand::SUCCESS;
    }

    protected function askForPassword(): string
    {
        if (! $this->input->isInteractive()) {
            $this->generatedPassword = Str::password(20);

            return $this->generatedPassword;
        }

        return password(
            label: 'Choose a login password',
            required: true,
            validate: fn (string $value): ?string => strlen($value) >= 8
                ? null
                : 'The password must be at least 8 characters.',
        );
    }

    /**
     * Run the given prompt when the session is interactive; otherwise fall
     * back to the default so --no-interaction installs never hang.
     *
     * @param  callable(): string  $prompt
     */
    protected function promptOr(callable $prompt, string $default): string
    {
        return $this->input->isInteractive() ? $prompt() : $default;
    }

    protected function printNextSteps(string $url, string $email): void
    {
        $this->newLine();
        $this->components->info('Docket is installed.');

        if ($this->generatedPassword !== null) {
            $this->components->warn(
                "Generated login password (shown once, store it now): {$this->generatedPassword}"
            );
        }

        $steps = [
            "Log in at {$url} as {$email}.",
        ];

        if (! file_exists(public_path('build/manifest.json'))) {
            $steps[] = 'Build the frontend: npm install && npm run build';
        }

        $steps[] = 'Serve the app: point your web server at public/ (or run: php artisan serve)';
        $steps[] = 'Run a queue worker to fetch saved pages: php artisan queue:work (use systemd/supervisor in production)';
        $steps[] = 'Add the scheduler cron: * * * * * cd /path/to/docket && php artisan schedule:run >> /dev/null 2>&1';
        $steps[] = 'Optional off-site backups: fill in the BACKUP_* keys in .env (S3-compatible bucket)';
        $steps[] = 'Coming from Pocket? Import your export: php artisan docket:import-pocket /path/to/part_000000.csv';

        $this->components->bulletList($steps);
        $this->newLine();
    }
}
