<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Scratch directory the installer's .env work is redirected to, so the
     * project's real .env is never touched by these tests.
     */
    private string $envDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envDir = sys_get_temp_dir().'/docket-install-test-'.Str::random(8);
        mkdir($this->envDir);
        copy(base_path('.env.example'), $this->envDir.'/.env.example');

        $this->app->useEnvironmentPath($this->envDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->envDir.'/.env');
        @unlink($this->envDir.'/.env.example');
        @rmdir($this->envDir);

        parent::tearDown();
    }

    private function envContents(): string
    {
        return (string) file_get_contents($this->envDir.'/.env');
    }

    public function test_installs_with_options_and_seeds_lookups(): void
    {
        $this->artisan('docket:install', [
            '--url' => 'https://ledger.example.com',
            '--name' => 'Jane Doe',
            '--email' => 'jane@example.com',
            '--password' => 'super-secret-99',
        ])
            ->expectsConfirmation('Seed starter categories, business units and payment methods?', 'yes')
            ->assertSuccessful();

        $user = User::query()->where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame('Jane Doe', $user->name);
        $this->assertTrue(Hash::check('super-secret-99', $user->password));

        $this->assertGreaterThan(0, Category::query()->count());

        $env = $this->envContents();
        $this->assertStringContainsString('APP_URL=https://ledger.example.com', $env);
        $this->assertStringContainsString('DOCKET_USER_NAME="Jane Doe"', $env);
        $this->assertStringContainsString('DOCKET_USER_EMAIL=jane@example.com', $env);
        $this->assertMatchesRegularExpression('/^APP_KEY="?base64:/m', $env);
        $this->assertStringNotContainsString('super-secret-99', $env);
    }

    public function test_skip_lookups_leaves_taxonomy_empty(): void
    {
        $this->artisan('docket:install', [
            '--url' => 'http://localhost',
            '--name' => 'Jane Doe',
            '--email' => 'jane@example.com',
            '--password' => 'super-secret-99',
            '--skip-lookups' => true,
        ])->assertSuccessful();

        $this->assertSame(0, Category::query()->count());
        $this->assertSame(1, User::query()->where('email', 'jane@example.com')->count());
    }

    public function test_non_interactive_install_generates_a_password(): void
    {
        $this->artisan('docket:install', [
            '--email' => 'owner@example.com',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
        // The fallback owner password must not be in play.
        $this->assertFalse(Hash::check('change-me-on-first-login', $user->password));
    }

    public function test_interactive_install_prompts_for_details(): void
    {
        $this->artisan('docket:install')
            ->expectsQuestion('What URL will Docket be served from?', 'https://docket.example.com')
            ->expectsQuestion('Your name', 'Sam Smith')
            ->expectsQuestion('Your email (used to log in)', 'sam@example.com')
            ->expectsQuestion('Choose a login password', 'another-secret-1')
            ->expectsConfirmation('Seed starter categories, business units and payment methods?', 'no')
            ->assertSuccessful();

        $user = User::query()->where('email', 'sam@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('another-secret-1', $user->password));
        $this->assertSame(0, Category::query()->count());
    }

    public function test_refuses_to_run_when_already_installed(): void
    {
        User::factory()->create();

        $this->artisan('docket:install', [
            '--email' => 'jane@example.com',
            '--password' => 'super-secret-99',
        ])->assertFailed();

        $this->assertSame(0, User::query()->where('email', 'jane@example.com')->count());
    }

    public function test_force_reruns_without_touching_existing_account(): void
    {
        $existing = User::factory()->create(['email' => 'jane@example.com']);
        $originalPassword = $existing->password;

        $this->artisan('docket:install', [
            '--url' => 'http://localhost',
            '--name' => 'Jane Doe',
            '--email' => 'jane@example.com',
            '--password' => 'a-brand-new-password',
            '--skip-lookups' => true,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame($originalPassword, $existing->refresh()->password);
    }

    public function test_rejects_invalid_email(): void
    {
        $this->artisan('docket:install', [
            '--email' => 'not-an-email',
            '--password' => 'super-secret-99',
            '--no-interaction' => true,
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_rejects_short_password(): void
    {
        $this->artisan('docket:install', [
            '--email' => 'jane@example.com',
            '--password' => 'short',
            '--no-interaction' => true,
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
