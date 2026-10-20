<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WebInstallerTest extends TestCase
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

        $this->envDir = sys_get_temp_dir().'/docket-web-install-test-'.Str::random(8);
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

    /** @return array<string, string> */
    private function validPayload(): array
    {
        return [
            'setup_code' => app(Installer::class)->setupCode(),
            'url' => 'https://docket.example.com',
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'super-secret-99',
            'password_confirmation' => 'super-secret-99',
        ];
    }

    public function test_requirements_page_renders_when_not_installed(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertSee('Welcome to the Docket installer')
            ->assertSee('PHP 8.3 or newer')
            ->assertSee('SQLite with full-text search (FTS5)')
            ->assertSee('Continue');
    }

    public function test_setup_form_renders_when_not_installed(): void
    {
        $this->get('/install/setup')
            ->assertOk()
            ->assertSee('Set up Docket')
            ->assertDontSee('Seed starter categories');
    }

    public function test_installs_and_creates_the_owner(): void
    {
        $response = $this->post('/install/setup', $this->validPayload());

        $response->assertOk()->assertSee('Docket is installed');

        $user = User::query()->where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame('Jane Doe', $user->name);
        $this->assertTrue(Hash::check('super-secret-99', $user->password));

        $this->assertSame(1, User::query()->count());

        $env = (string) file_get_contents($this->envDir.'/.env');
        $this->assertStringContainsString('APP_URL=https://docket.example.com', $env);
        $this->assertStringContainsString('DOCKET_USER_NAME="Jane Doe"', $env);
        $this->assertStringContainsString('DOCKET_USER_EMAIL=jane@example.com', $env);
        $this->assertMatchesRegularExpression('/^APP_KEY="?base64:/m', $env);
        $this->assertStringNotContainsString('super-secret-99', $env);
    }

    public function test_invalid_email_re_renders_form_with_error(): void
    {
        $payload = $this->validPayload();
        $payload['email'] = 'not-an-email';

        $this->post('/install/setup', $payload)
            ->assertStatus(422)
            ->assertSee('Set up Docket');

        $this->assertSame(0, User::query()->count());
    }

    public function test_mismatched_password_confirmation_fails(): void
    {
        $payload = $this->validPayload();
        $payload['password_confirmation'] = 'something-else-9';

        $this->post('/install/setup', $payload)->assertStatus(422);

        $this->assertSame(0, User::query()->count());
    }

    public function test_installer_disabled_once_installed(): void
    {
        User::factory()->create();

        $this->get('/install')->assertRedirect(route('login'));
        $this->get('/install/setup')->assertRedirect(route('login'));
        $this->post('/install/setup', $this->validPayload())->assertRedirect(route('login'));

        $this->assertSame(0, User::query()->where('email', 'jane@example.com')->count());
    }

    public function test_wizard_links_are_relative_so_forced_https_cannot_break_them(): void
    {
        // Before .env exists the app defaults to APP_ENV=production, which
        // makes AppServiceProvider force https on every generated URL. The
        // wizard must therefore use relative paths, or a plain-http install
        // (local, or a server before certbot has run) posts into the void.
        URL::forceScheme('https');

        $this->get('/install')
            ->assertOk()
            ->assertSee('href="/install/setup"', false);

        $this->get('/install/setup')
            ->assertOk()
            ->assertSee('action="/install/setup"', false);
    }

    public function test_install_routes_do_not_require_csrf_or_session(): void
    {
        // POSTing with no CSRF token must not 419 — these routes run
        // outside the 'web' group because no session store exists yet.
        $this->post('/install/setup', [])->assertStatus(422);
    }

    public function test_the_setup_form_says_where_to_find_the_setup_code(): void
    {
        $this->get('/install/setup')
            ->assertOk()
            ->assertSee('Setup code')
            ->assertSee('cat '.app(Installer::class)->setupCodeLocation());

        $this->assertFileExists($this->installState.'/setup-code');
    }

    public function test_a_wrong_or_missing_setup_code_is_refused(): void
    {
        foreach (['', 'aaaa-bbbb-cccc-dddd'] as $code) {
            $this->post('/install/setup', ['setup_code' => $code] + $this->validPayload())
                ->assertStatus(422)
                ->assertSee('Setup code');
        }

        $this->assertSame(0, User::query()->count());
        $this->assertFileDoesNotExist($this->envDir.'/.env');
    }

    public function test_installing_retires_the_setup_code_and_leaves_a_marker(): void
    {
        $this->post('/install/setup', $this->validPayload())->assertOk();

        $this->assertFileDoesNotExist($this->installState.'/setup-code');
        $this->assertFileExists($this->installState.'/installed');
    }

    public function test_the_marker_keeps_the_installer_closed_even_with_no_accounts(): void
    {
        app(Installer::class)->markInstalled();

        $this->get('/install')->assertRedirect(route('login'));
        $this->post('/install/setup', $this->validPayload())->assertRedirect(route('login'));
        $this->assertSame(0, User::query()->count());
    }

    public function test_concurrent_installs_cannot_create_two_owners(): void
    {
        // Pause the first installation just before it creates its account
        // and run a complete second one in that window, as two overlapping
        // requests would. The second is refused by the lock.
        $real = new Installer($this->app);
        $installer = Mockery::mock(Installer::class, [$this->app])->makePartial();
        $second = null;

        $installer->shouldReceive('createOwner')->andReturnUsing(function (string $name, string $email, string $password) use (&$second, $real) {
            $second ??= $this->post('/install/setup', array_replace($this->validPayload(), ['email' => 'intruder@example.com']));

            return $real->createOwner($name, $email, $password);
        });
        $this->app->instance(Installer::class, $installer);

        $this->post('/install/setup', $this->validPayload())->assertOk()->assertSee('Docket is installed');

        $this->assertNotNull($second);
        $second->assertStatus(409)->assertSee('Another installation is running');
        $this->assertSame(['jane@example.com'], User::query()->pluck('email')->all());
    }

    public function test_a_second_owner_is_never_created_even_past_the_checks(): void
    {
        User::factory()->create();

        $this->expectException(RuntimeException::class);

        app(Installer::class)->createOwner('Someone', 'someone@example.com', 'password-123');
    }

    public function test_an_unreadable_database_keeps_the_installer_closed(): void
    {
        // A configured database that errors is not a fresh install: fail
        // closed, and touch nothing.
        Schema::shouldReceive('hasTable')->andThrow(new RuntimeException('database is locked'));

        $this->get('/install')->assertStatus(503)->assertSee('installer stays closed');
        $this->post('/install/setup', $this->validPayload())->assertStatus(503);

        $this->assertFileDoesNotExist($this->envDir.'/.env');
    }
}
