<?php

namespace App\Services;

use App\Exceptions\InstallStateUnknownException;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * The installation engine shared by the console installer
 * (php artisan docket:install) and the web installer (/install).
 * Each step is a small idempotent method so either front-end can run
 * them one at a time and narrate progress in its own way.
 *
 * Docket has exactly one account, and the web installer is reachable by
 * anyone who finds a fresh deployment, so installation is guarded three
 * ways: the web form needs a setup code that can only be read on the
 * server; installations are serialised by a lock and re-check ownership
 * inside it; and createOwner() refuses outright once any account exists.
 * Once installed, a marker file keeps the installer closed even when the
 * database can't be read.
 */
class Installer
{
    public function __construct(protected Application $app) {}

    /**
     * Docket counts as installed once the installed marker exists or an
     * account does. A missing SQLite file is a fresh install; any other
     * database error is not proof of anything, so it fails closed rather
     * than reopening the installer on a live site.
     *
     * @throws InstallStateUnknownException
     */
    public function isInstalled(): bool
    {
        if (is_file($this->statePath('installed'))) {
            return true;
        }

        try {
            $installed = Schema::hasTable('users') && User::query()->exists();
        } catch (Throwable $e) {
            if ($this->databaseIsMissing()) {
                return false;
            }

            throw new InstallStateUnknownException(
                'Docket could not read its database to check whether it is installed, so the installer stays closed. Check storage/logs/laravel.log.',
                previous: $e,
            );
        }

        if ($installed) {
            // Installs from before the marker existed get one now.
            $this->markInstalled();
        }

        return $installed;
    }

    /**
     * Record a finished installation and retire the setup code.
     */
    public function markInstalled(): void
    {
        $this->ensureStateDirectory();
        @file_put_contents($this->statePath('installed'), now()->toIso8601String()."\n");
        @unlink($this->statePath('setup-code'));
    }

    /**
     * The one-time code the web installer asks for, created on first use.
     * Reading it needs shell access to the server, which is what proves
     * the person at /install is the person deploying Docket.
     */
    public function setupCode(): string
    {
        $path = $this->statePath('setup-code');

        if (! is_file($path)) {
            $this->ensureStateDirectory();
            file_put_contents($path, implode('-', str_split(Str::lower(Str::random(16)), 4))."\n");
            @chmod($path, 0600);
        }

        return trim((string) file_get_contents($path));
    }

    public function checkSetupCode(string $given): bool
    {
        return hash_equals($this->setupCode(), strtolower(trim($given)));
    }

    /**
     * Where the setup code lives, relative to the app root when it is
     * inside it, for showing in the installer.
     */
    public function setupCodeLocation(): string
    {
        return Str::after($this->statePath('setup-code'), $this->app->basePath().DIRECTORY_SEPARATOR);
    }

    /**
     * Take the installation lock without waiting: a second installation
     * running at the same moment is told to try again rather than queued
     * behind the first.
     *
     * @return resource|null the lock handle, or null when it is held
     */
    public function acquireLock()
    {
        $this->ensureStateDirectory();
        $handle = fopen($this->statePath('install.lock'), 'c');

        if ($handle === false) {
            throw new RuntimeException('Could not open the installation lock file.');
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    public function releaseLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Environment checks for the requirements screen. Blocking entries
     * must pass before installation can proceed; the rest are advisory.
     *
     * @return list<array{label: string, ok: bool, blocking: bool, hint: string}>
     */
    public function requirements(): array
    {
        $envPath = $this->app->environmentFilePath();
        $envWritable = file_exists($envPath)
            ? is_writable($envPath)
            : is_writable($this->app->environmentPath());

        $checks = [
            [
                'label' => 'PHP 8.3 or newer (running '.PHP_VERSION.')',
                'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
                'blocking' => true,
                'hint' => 'Upgrade PHP to 8.3+.',
            ],
        ];

        foreach (['pdo_sqlite', 'mbstring', 'fileinfo', 'dom', 'xml', 'curl'] as $extension) {
            $checks[] = [
                'label' => "PHP extension: {$extension}",
                'ok' => extension_loaded($extension),
                'blocking' => true,
                'hint' => "Install/enable the {$extension} PHP extension.",
            ];
        }

        $checks[] = [
            'label' => 'SQLite with full-text search (FTS5)',
            'ok' => $this->hasFts5(),
            'blocking' => true,
            'hint' => 'Search needs an SQLite build with FTS5 (standard on current Debian, Ubuntu and Homebrew PHP).',
        ];

        $writable = [
            '.env file location' => $envWritable,
            'storage/ directory' => is_writable($this->app->storagePath()),
            'bootstrap/cache/ directory' => is_writable($this->app->bootstrapPath('cache')),
            'database/ directory' => is_writable(dirname($this->sqlitePath() ?? database_path('database.sqlite'))),
        ];

        foreach ($writable as $label => $ok) {
            $checks[] = [
                'label' => "Writable: {$label}",
                'ok' => $ok,
                'blocking' => true,
                'hint' => 'Fix ownership/permissions for the web server user (e.g. chown -R www-data).',
            ];
        }

        $checks[] = [
            'label' => 'Frontend assets built (public/build)',
            'ok' => file_exists(public_path('build/manifest.json')),
            'blocking' => false,
            'hint' => 'Run: npm install && npm run build',
        ];

        $checks[] = [
            'label' => 'PHP extension: intl (used to normalise international domain names)',
            'ok' => extension_loaded('intl'),
            'blocking' => false,
            'hint' => 'Install the intl PHP extension; without it, non-ASCII domains are compared as typed.',
        ];

        $checks[] = [
            'label' => 'PHP extension: zip (used by off-site backups)',
            'ok' => extension_loaded('zip'),
            'blocking' => false,
            'hint' => 'Install the zip PHP extension to enable backups.',
        ];

        return $checks;
    }

    /**
     * Human-readable descriptions of every failing blocking requirement.
     *
     * @return list<string>
     */
    public function blockingProblems(): array
    {
        $problems = [];

        foreach ($this->requirements() as $check) {
            if ($check['blocking'] && ! $check['ok']) {
                $problems[] = $check['label'].' — '.$check['hint'];
            }
        }

        return $problems;
    }

    /**
     * @return bool whether a new .env was created (false = already existed)
     */
    public function ensureEnvFile(): bool
    {
        $envPath = $this->app->environmentFilePath();

        if (file_exists($envPath)) {
            return false;
        }

        $example = $this->app->environmentPath().DIRECTORY_SEPARATOR.'.env.example';

        if (! copy($example, $envPath)) {
            throw new \RuntimeException("Could not create {$envPath} from .env.example.");
        }

        return true;
    }

    /**
     * Writes a fresh APP_KEY directly rather than calling key:generate,
     * whose replacement regex anchors on the key the app booted with —
     * which is empty/stale when .env was only just created above.
     *
     * @return bool whether a key was generated (false = already present)
     */
    public function ensureAppKey(): bool
    {
        $contents = (string) file_get_contents($this->app->environmentFilePath());

        if (preg_match('/^APP_KEY=\S+/m', $contents)) {
            return false;
        }

        $key = 'base64:'.base64_encode(random_bytes(32));

        $this->writeEnvValues(['APP_KEY' => $key]);

        if (! config('app.key')) {
            config(['app.key' => $key]);
        }

        return true;
    }

    /**
     * Set keys in the .env file, replacing existing (or commented-out)
     * lines and appending anything not present.
     *
     * @param  array<string, string>  $values
     */
    public function writeEnvValues(array $values): void
    {
        $path = $this->app->environmentFilePath();
        $contents = (string) file_get_contents($path);

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->formatEnvValue($value);
            $pattern = '/^#?\h*'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents)) {
                $contents = (string) preg_replace($pattern, $line, $contents, 1);
            } else {
                $contents = rtrim($contents, "\n")."\n".$line."\n";
            }
        }

        file_put_contents($path, $contents);
    }

    /**
     * The default SQLite database lives at database/database.sqlite; create
     * it (and its directory) so the first migrate has somewhere to run.
     */
    public function ensureSqliteDatabase(): void
    {
        $path = $this->sqlitePath();

        if ($path === null || file_exists($path)) {
            return;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        touch($path);
    }

    public function migrate(): bool
    {
        return Artisan::call('migrate', ['--force' => true]) === 0;
    }

    public function ownerExists(string $email): bool
    {
        return User::query()->where('email', $email)->exists();
    }

    /**
     * Whether any account exists. Docket has one, whatever its email.
     */
    public function hasOwner(): bool
    {
        return User::query()->exists();
    }

    /**
     * @throws RuntimeException when an account already exists
     */
    public function createOwner(string $name, string $email, string $plainPassword): User
    {
        if ($this->hasOwner()) {
            throw new RuntimeException('Docket already has its owner account; it does not create a second one.');
        }

        // The User model's 'hashed' cast hashes the plain password.
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $plainPassword,
        ]);
    }

    protected function statePath(string $file): string
    {
        return rtrim((string) config('docket.install.state_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;
    }

    protected function ensureStateDirectory(): void
    {
        $directory = dirname($this->statePath('x'));

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
    }

    /**
     * True only for a fresh install: SQLite whose database file doesn't
     * exist yet (or is still empty).
     */
    protected function databaseIsMissing(): bool
    {
        $path = $this->sqlitePath();

        return $path !== null && (! is_file($path) || filesize($path) === 0);
    }

    /**
     * Whether this PHP's SQLite library was compiled with FTS5, which the
     * items_fts search index needs.
     */
    protected function hasFts5(): bool
    {
        if (! extension_loaded('pdo_sqlite')) {
            return false;
        }

        try {
            $pdo = new PDO('sqlite::memory:');
            $pdo->exec('CREATE VIRTUAL TABLE fts5_probe USING fts5(body)');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Path of the SQLite database file, or null when the default
     * connection is not file-backed SQLite (e.g. :memory: in tests).
     */
    protected function sqlitePath(): ?string
    {
        if (config('database.default') !== 'sqlite') {
            return null;
        }

        $path = config('database.connections.sqlite.database');

        return is_string($path) && $path !== ':memory:' ? $path : null;
    }

    protected function formatEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\-\/:@]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"';
    }
}
