<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_info_section_surfaces_helpful_env_derived_values(): void
    {
        config([
            'services.google_vision.credentials' => null,
            'filesystems.disks.offsite.bucket' => 'docket-backups',
            'filesystems.disks.offsite.endpoint' => 'https://s3.eu-central-003.backblazeb2.com',
        ]);

        $response = $this->actingAs(User::factory()->create())->get('/settings');

        $response->assertOk();
        $response->assertSee('Info');
        $response->assertSee('Tesseract only (Google Vision not configured)');
        $response->assertSee('docket-backups @ https://s3.eu-central-003.backblazeb2.com');
        $response->assertDontSee(config('app.key'));
    }

    public function test_settings_save_with_the_field_names_a_browser_actually_submits(): void
    {
        // PHP converts dots to underscores in form data, so the request
        // arrives with receipts_mimes etc. — the dotted names never reach
        // the server. This posts exactly what a browser would deliver.
        $this->actingAs(User::factory()->create())
            ->patch('/settings', $this->validSettingsPayload(['receipts_max_kb' => '2048']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings');

        $this->assertSame(2048, settings('receipts.max_kb'));
    }

    public function test_receipt_file_types_reject_active_content_extensions(): void
    {
        // Receipts stream back inline, so an SVG (or HTML) "receipt" would
        // run scripts on this origin — the setting must refuse them.
        $response = $this->actingAs(User::factory()->create())
            ->from('/settings')
            ->patch('/settings', $this->validSettingsPayload(['receipts_mimes' => 'jpg,svg']));

        $response->assertSessionHasErrors('receipts_mimes');
        $this->assertNull(Setting::where('key', 'receipts.mimes')->first());
    }

    public function test_receipt_file_types_are_normalised_on_save(): void
    {
        // Spaces and mixed case would silently break the mimes: rule the
        // stored value is spliced into.
        $this->actingAs(User::factory()->create())
            ->patch('/settings', $this->validSettingsPayload(['receipts_mimes' => 'JPG, png , pdf']))
            ->assertSessionHasNoErrors();

        $this->assertSame('jpg,png,pdf', settings('receipts.mimes'));
    }

    /**
     * Every field the settings form submits, keyed by the underscore form
     * names PHP actually delivers, with defaults from config.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validSettingsPayload(array $overrides = []): array
    {
        return array_merge([
            'currencies' => 'GBP, USD, EUR',
            'receipts_mimes' => config('docket.receipts.mimes'),
            'receipts_max_kb' => (string) config('docket.receipts.max_kb'),
            'max_amount' => (string) config('docket.max_amount'),
            'ocr_tries' => (string) config('docket.ocr.tries'),
            'ocr_backoff' => '10, 60',
        ], $overrides);
    }

    public function test_info_section_reports_vision_when_credentials_are_present(): void
    {
        config(['services.google_vision.credentials' => 'test-credentials']);

        $response = $this->actingAs(User::factory()->create())->get('/settings');

        $response->assertOk();
        $response->assertSee('Google Vision (falls back to Tesseract on error)');
    }

    public function test_tesseract_status_reports_install_instructions_when_missing(): void
    {
        config(['docket.ocr.tesseract_binary' => 'this-binary-does-not-exist-xyz']);

        $response = $this->actingAs(User::factory()->create())->get('/settings');

        $response->assertOk();
        $response->assertSee('Not found');
        $response->assertSee('install');
        $response->assertSee('tesseract');
    }

    public function test_tesseract_status_reports_available_when_the_binary_resolves(): void
    {
        // "sh" stands in for a real, always-present binary — the point is
        // to exercise the resolves-successfully branch without depending
        // on tesseract actually being installed wherever tests run.
        config(['docket.ocr.tesseract_binary' => 'sh']);

        $response = $this->actingAs(User::factory()->create())->get('/settings');

        $response->assertOk();
        $response->assertSee('Available');
    }

    public function test_tesseract_status_falls_back_to_well_known_paths_when_path_is_limited(): void
    {
        // GUI-launched/IDE-managed PHP processes on macOS often don't
        // inherit the interactive shell's PATH, which is specifically
        // where Homebrew adds its own bin directory — reproduces that by
        // stripping PATH down to nothing and confirming Tesseract is still
        // found via the hardcoded fallback locations.
        $installed = collect(['/opt/homebrew/bin/tesseract', '/usr/local/bin/tesseract', '/usr/bin/tesseract'])
            ->first(fn ($path) => is_executable($path));

        if ($installed === null) {
            $this->markTestSkipped('Tesseract is not installed at a well-known path on this machine.');
        }

        config(['docket.ocr.tesseract_binary' => 'tesseract']);
        $originalPath = getenv('PATH');
        putenv('PATH=/nonexistent-directory-for-this-test');

        try {
            $response = $this->actingAs(User::factory()->create())->get('/settings');
        } finally {
            putenv("PATH={$originalPath}");
        }

        $response->assertOk();
        $response->assertSee("Available ({$installed})");
    }
}
