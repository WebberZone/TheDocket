<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The server side of offline reading: the list public/sw.js keeps on the
 * device, the setting that sizes it, and clearing it on logout.
 */
class OfflineReadingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_the_list_needs_a_session(): void
    {
        $this->getJson('/offline/articles')->assertUnauthorized();
    }

    public function test_it_lists_the_newest_unread_articles_with_a_readable_copy(): void
    {
        $older = Item::factory()->fetched('Older article.')->create(['title' => 'Older', 'added_at' => now()->subDays(2)]);
        $newer = Item::factory()->fetched('Newer article.')->create(['title' => 'Newer', 'added_at' => now()->subDay()]);
        Item::factory()->fetched()->archived()->create(['added_at' => now()]);
        Item::factory()->create(['added_at' => now()]);
        Item::factory()->failed()->create(['added_at' => now()]);

        $response = $this->actingAs($this->user)->getJson('/offline/articles');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(["/items/{$newer->id}", "/items/{$older->id}"], array_column($response->json('items'), 'url'));
        $this->assertSame('Newer', $response->json('items.0.title'));
        $this->assertSame($newer->site_name, $response->json('items.0.site'));
        $this->assertSame((int) $newer->reading_time, $response->json('items.0.minutes'));
    }

    public function test_the_setting_limits_the_list_and_zero_turns_it_off(): void
    {
        Item::factory()->count(5)->fetched()->create();

        app(Settings::class)->set('offline.articles', 3);
        $this->assertCount(3, $this->actingAs($this->user)->getJson('/offline/articles')->json('items'));

        app(Settings::class)->set('offline.articles', 0);
        $this->assertSame([], $this->actingAs($this->user)->getJson('/offline/articles')->json('items'));
    }

    public function test_it_lists_only_the_stored_images_the_article_uses(): void
    {
        $item = Item::factory()->fetched()->create();
        $stored = '/items/'.$item->id.'/images/'.sha1('a').'.png';
        $item->forceFill([
            'content_html' => '<p><img src="'.$stored.'"><img src="'.$stored.'">'
                .'<img src="https://example.com/remote.png">'
                .'<img src="/items/999/images/'.sha1('b').'.png"></p>',
        ])->save();

        $images = $this->actingAs($this->user)->getJson('/offline/articles')->json('items.0.images');

        $this->assertSame([$stored], $images);
    }

    public function test_the_version_changes_when_the_page_would(): void
    {
        $item = Item::factory()->fetched()->create();
        $version = fn (): string => $this->actingAs($this->user)->getJson('/offline/articles')->json('items.0.version');

        $before = $version();
        $this->travel(5)->seconds();
        $item->forceFill(['title' => 'Renamed'])->save();

        $this->assertNotSame($before, $version());
    }

    public function test_the_setting_is_on_the_settings_page(): void
    {
        $this->actingAs($this->user)->get('/settings')
            ->assertOk()
            ->assertSee('Articles kept for offline reading');
    }

    public function test_logged_in_pages_ask_for_a_sync_and_the_login_page_does_not(): void
    {
        $item = Item::factory()->fetched()->create();

        foreach (['/', "/items/{$item->id}"] as $page) {
            $this->actingAs($this->user)->get($page)
                ->assertOk()
                ->assertSee('<meta name="docket-offline" content="/offline/articles">', false);
        }

        $this->app['auth']->forgetGuards();
        $this->get('/login')->assertOk()->assertDontSee('docket-offline', false);
    }

    public function test_logging_out_clears_the_offline_copies(): void
    {
        $this->actingAs($this->user)->get('/')->assertSee('data-clears-offline', false);

        $this->actingAs($this->user)->post('/logout')
            ->assertRedirect('/')
            ->assertHeader('Clear-Site-Data', '"cache", "storage"');
    }

    public function test_the_service_worker_asks_for_the_route_the_app_serves(): void
    {
        $worker = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("const LIST_URL = '".route('offline.articles', [], false)."';", $worker);
        $this->assertFileExists(public_path('offline.js'));
    }
}
