<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_shows_the_bookmarklet_tokens_and_info(): void
    {
        config([
            'filesystems.disks.offsite.bucket' => 'docket-backups',
            'filesystems.disks.offsite.endpoint' => 'https://s3.eu-central-003.backblazeb2.com',
        ]);

        $response = $this->actingAs(User::factory()->create())->get('/settings');

        $response->assertOk();
        $response->assertSee('Bookmarklet');
        $response->assertSee('javascript:', false);
        $response->assertSee(route('save.bookmarklet'), false);
        $response->assertSee('API tokens');
        $response->assertSee('Setting up the iOS Shortcut');
        $response->assertSee('docket-backups @ https://s3.eu-central-003.backblazeb2.com');
        $response->assertDontSee(config('app.key'));
    }

    public function test_settings_save_with_the_field_names_a_browser_actually_submits(): void
    {
        // PHP converts dots to underscores in form data, so the request
        // arrives with fetch_timeout etc. The dotted names never reach the
        // server. This posts exactly what a browser would deliver.
        $this->actingAs(User::factory()->create())
            ->patch('/settings', $this->validSettingsPayload(['fetch_timeout' => '45', 'fetch_backoff' => '5, 50']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings');

        $this->assertSame(45, settings('fetch.timeout'));
        $this->assertSame([5, 50], settings('fetch.backoff'));
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->from('/settings')
            ->patch('/settings', $this->validSettingsPayload(['words_per_minute' => '0', 'fetch_backoff' => 'soon']));

        $response->assertSessionHasErrors(['words_per_minute', 'fetch_backoff']);
        $this->assertNull(Setting::where('key', 'words_per_minute')->first());
    }

    public function test_defaults_come_from_config_until_overridden(): void
    {
        config(['docket.per_page' => 17]);

        $this->assertSame(17, settings('per_page'));
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
            'words_per_minute' => (string) config('docket.words_per_minute'),
            'per_page' => (string) config('docket.per_page'),
            'fetch_timeout' => (string) config('docket.fetch.timeout'),
            'fetch_max_kb' => (string) config('docket.fetch.max_kb'),
            'fetch_tries' => (string) config('docket.fetch.tries'),
            'fetch_backoff' => implode(', ', config('docket.fetch.backoff')),
            'images_max_per_item' => (string) config('docket.images.max_per_item'),
            'offline_articles' => (string) config('docket.offline.articles'),
            'import_spacing_seconds' => (string) config('docket.import.spacing_seconds'),
        ], $overrides);
    }
}
