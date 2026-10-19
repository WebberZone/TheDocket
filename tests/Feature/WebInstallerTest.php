<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
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
            ->assertSee('Continue');
    }

    public function test_setup_form_renders_when_not_installed(): void
    {
        $this->get('/install/setup')
            ->assertOk()
            ->assertSee('Set up Docket')
            ->assertSee('Seed starter categories');
    }

    public function test_installs_and_seeds_lookups(): void
    {
        $response = $this->post('/install/setup', $this->validPayload() + ['seed_lookups' => '1']);

        $response->assertOk()->assertSee('Docket is installed');

        $user = User::query()->where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame('Jane Doe', $user->name);
        $this->assertTrue(Hash::check('super-secret-99', $user->password));

        $this->assertGreaterThan(0, Category::query()->count());

        $env = (string) file_get_contents($this->envDir.'/.env');
        $this->assertStringContainsString('APP_URL=https://docket.example.com', $env);
        $this->assertStringContainsString('DOCKET_USER_NAME="Jane Doe"', $env);
        $this->assertStringContainsString('DOCKET_USER_EMAIL=jane@example.com', $env);
        $this->assertMatchesRegularExpression('/^APP_KEY="?base64:/m', $env);
        $this->assertStringNotContainsString('super-secret-99', $env);
    }

    public function test_installs_without_lookups_when_unticked(): void
    {
        // An unticked checkbox simply isn't posted.
        $this->post('/install/setup', $this->validPayload())
            ->assertOk()
            ->assertSee('Docket is installed');

        $this->assertSame(0, Category::query()->count());
        $this->assertSame(1, User::query()->count());
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
}
