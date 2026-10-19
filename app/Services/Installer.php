<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

/**
 * The installation engine shared by the console installer
 * (php artisan docket:install) and the web installer (/install).
 * Each step is a small idempotent method so either front-end can run
 * them one at a time and narrate progress in its own way.
 */
class Installer
{
    public function __construct(protected Application $app) {}

    /**
     * Docket counts as installed once the owner account exists — the
     * final step of either installer. Anything earlier (missing .env,
     * empty database) is a fresh or half-finished install.
     */
    public function isInstalled(): bool
    {
        try {
            return Schema::hasTable('users') && User::query()->exists();
        } catch (Throwable) {
            // No database yet — definitely a fresh install.
            return false;
        }
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

        foreach (['pdo_sqlite', 'mbstring', 'fileinfo', 'dom', 'xml'] as $extension) {
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

    public function createOwner(string $name, string $email, string $plainPassword): User
    {
        // The User model's 'hashed' cast hashes the plain password.
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $plainPassword,
        ]);
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
