<?php

namespace Tests\Feature;

use App\Enums\FetchStatus;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\User;
use App\Services\Exporter;
use App\Services\ImageLocaliser;
use App\Services\UrlNormaliser;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Regression tests for the issues raised in the October 2026 review
 * (reviews/2026-10-20-code-review.md on its own branch): queue timing,
 * memory, image budgets, storage failures, refresh failures, refetch
 * paging and spreadsheet-safe export.
 */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private function item(string $url = 'https://example.com/article', array $attributes = []): Item
    {
        return Item::factory()->create($attributes + [
            'url' => $url,
            'normalized_url' => app(UrlNormaliser::class)->normalise($url),
            'title' => null,
        ]);
    }

    private function articleHtml(string $body = 'A readable paragraph about reading and libraries.'): string
    {
        return '<!DOCTYPE html><html lang="en"><head><title>Article</title>'
            .'<meta property="og:image" content="https://cdn.example.com/lead.png"></head><body><article>'
            .str_repeat('<p>'.$body.' '.str_repeat('More words to make a proper paragraph. ', 8).'</p>', 6)
            .'</article></body></html>';
    }

    // Queue timing --------------------------------------------------------

    public function test_queue_reservations_outlast_the_fetch_job(): void
    {
        $timeout = (new FetchItemContent($this->item()))->timeout;

        foreach (['database', 'redis', 'beanstalkd'] as $connection) {
            $this->assertGreaterThan($timeout, config("queue.connections.{$connection}.retry_after"), $connection);
        }
    }

    public function test_the_same_item_is_never_fetched_twice_at_once(): void
    {
        Http::fake();
        $item = $this->item();
        $job = new FetchItemContent($item);

        // Another worker is mid-fetch on this item.
        $lock = Cache::lock($job->middleware()[0]->getLockKey($job), 600);
        $this->assertTrue($lock->get());

        FetchItemContent::dispatchSync($item);

        Http::assertNothingSent();
        $this->assertSame(0, $item->refresh()->fetch_attempts);

        $lock->release();
    }

    // Memory ---------------------------------------------------------------

    public function test_lists_bulk_actions_and_csv_export_never_select_article_bodies(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $items = Item::factory()->count(3)->fetched()->create();

        DB::enableQueryLog();

        $this->actingAs($user)->get('/')->assertOk();
        $this->actingAs($user)->get('/search?q=the')->assertOk();
        $this->actingAs($user)->post('/items/bulk', ['action' => 'archive', 'ids' => $items->pluck('id')->all()]);
        $this->actingAs($user)->get('/export/csv')->streamedContent();

        $bodies = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')
                && str_contains($sql, '"items"')
                && (str_contains($sql, '"items".*') || str_contains($sql, 'select *') || str_contains($sql, 'content_html')));

        $this->assertSame([], $bodies->values()->all());
        $this->assertSame(3, Item::query()->archived()->count());
    }

    // Images ---------------------------------------------------------------

    public function test_failed_image_downloads_use_up_the_per_article_limit(): void
    {
        config(['docket.images.max_per_item' => 2]);
        Http::fake(['*' => Http::response('', 404)]);

        $html = '';
        for ($i = 0; $i < 7; $i++) {
            $html .= '<img src="https://images.example.com/'.$i.'.png">';
        }

        app(ImageLocaliser::class)->localise($this->item(), $html);

        Http::assertSentCount(2);
    }

    public function test_image_downloads_stop_at_the_per_article_byte_budget(): void
    {
        Storage::fake(Item::DISK);
        config(['docket.images.max_total_kb' => 1]);
        Http::fake(['*' => Http::response(str_repeat('x', 900))]);

        app(ImageLocaliser::class)->localise($this->item(), '<img src="https://a.example/1.png"><img src="https://a.example/2.png"><img src="https://a.example/3.png">');

        // 900 bytes, then 124 left (capped), then nothing left to spend.
        Http::assertSentCount(2);
    }

    public function test_disabled_downloads_skip_the_lead_image_too(): void
    {
        Storage::fake(Item::DISK);
        config(['docket.images.enabled' => false]);
        Http::fake([
            'https://example.com/article' => Http::response($this->articleHtml(), 200, ['Content-Type' => 'text/html']),
            '*' => Http::response((string) base64_decode(self::PNG)),
        ]);
        $item = $this->item();

        FetchItemContent::dispatchSync($item);

        $item->refresh();
        $this->assertSame(FetchStatus::Fetched, $item->fetch_status);
        $this->assertNull($item->lead_image_path);
        $this->assertSame('https://cdn.example.com/lead.png', $item->lead_image_url);
        Http::assertSentCount(1);
    }

    public function test_a_lead_image_shown_inline_is_downloaded_once(): void
    {
        Storage::fake(Item::DISK);
        $html = str_replace('<article>', '<article><p><img src="https://cdn.example.com/lead.png" alt=""></p>', $this->articleHtml());
        Http::fake([
            'https://example.com/article' => Http::response($html, 200, ['Content-Type' => 'text/html']),
            'https://cdn.example.com/lead.png' => Http::response((string) base64_decode(self::PNG)),
        ]);
        $item = $this->item();

        FetchItemContent::dispatchSync($item);

        $this->assertSame(sha1('https://cdn.example.com/lead.png').'.png', $item->refresh()->lead_image_path);
        Http::assertSentCount(2);
    }

    public function test_a_failed_image_write_keeps_the_remote_address(): void
    {
        Http::fake(['*' => Http::response((string) base64_decode(self::PNG))]);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with(Item::DISK)->andReturn($disk);

        $item = $this->item();
        $html = app(ImageLocaliser::class)->localise($item, '<img src="https://example.com/image.png">');

        $this->assertStringContainsString('src="https://example.com/image.png"', $html);
        $this->assertStringNotContainsString('/items/'.$item->id.'/images/', $html);
    }

    // Refresh failures -----------------------------------------------------

    public function test_a_failed_refresh_keeps_the_saved_article_readable(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $item = Item::factory()->fetched('The preserved article body.')->create([
            'author' => 'Original Author',
            'site_name' => 'Original Site',
        ]);

        FetchItemContent::dispatchSync($item);
        $item->refresh();

        $this->assertSame(FetchStatus::Failed, $item->fetch_status);
        $this->assertTrue($item->hasArticle());
        $this->assertTrue($item->refreshFailed());
        $this->assertSame('Original Author', $item->author);

        $this->actingAs(User::factory()->create())
            ->get("/items/{$item->id}")
            ->assertOk()
            ->assertSee('The preserved article body.')
            ->assertSee('Could not refresh this article: The site responded with HTTP 404.');

        $this->actingAs(User::factory()->create())->get('/')->assertSee('Refresh failed');
    }

    public function test_a_refresh_that_finds_no_article_keeps_the_saved_details(): void
    {
        Http::fake(['*' => Http::response('<html><head><title>Gone</title></head><body></body></html>', 200, ['Content-Type' => 'text/html'])]);
        $item = Item::factory()->fetched('Still here.')->create([
            'title' => 'Original title',
            'excerpt' => 'Original excerpt',
            'site_name' => 'Original Site',
        ]);

        FetchItemContent::dispatchSync($item);
        $item->refresh();

        $this->assertSame('Original title', $item->title);
        $this->assertSame('Original excerpt', $item->excerpt);
        $this->assertSame('Original Site', $item->site_name);
        $this->assertStringContainsString('Still here.', (string) $item->content_html);
    }

    // Refetch paging -------------------------------------------------------

    public function test_refetch_reaches_every_item_as_the_selection_shrinks(): void
    {
        // On a sync queue each item is fetched (and leaves the failed set)
        // during the loop; offset paging would skip the rows behind it.
        config(['queue.default' => 'sync', 'docket.import.spacing_seconds' => 0, 'docket.images.enabled' => false]);
        Http::fake(fn () => Http::response($this->articleHtml(), 200, ['Content-Type' => 'text/html']));

        Item::factory()->count(450)->failed()->create(['fetch_attempts' => 0, 'title' => 'Kept']);

        $this->artisan('docket:refetch')->assertSuccessful();

        $this->assertSame(0, Item::query()->where('fetch_attempts', 0)->count());
        $this->assertSame(450, Item::query()->where('fetch_status', FetchStatus::Fetched->value)->count());
    }

    // Export ---------------------------------------------------------------

    public function test_the_spreadsheet_csv_neutralises_formulas_and_the_plain_one_is_lossless(): void
    {
        Item::factory()->create(['title' => '=HYPERLINK("https://evil.example","x")', 'url' => 'https://example.com/a']);
        Item::factory()->create(['title' => " \t@SUM(A1)", 'url' => 'https://example.com/b']);
        Item::factory()->create(['title' => 'An ordinary title', 'url' => 'https://example.com/c']);

        $user = User::factory()->create();
        $parse = fn (string $csv): array => array_map(
            fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_values(array_filter(explode("\n", $csv)))
        );

        $plain = $parse($this->actingAs($user)->get('/export/csv')->streamedContent());
        $safe = $this->actingAs($user)->get('/export/csv?for=spreadsheet');
        $this->assertStringContainsString('-spreadsheet.csv', (string) $safe->headers->get('Content-Disposition'));
        $safe = $parse($safe->streamedContent());

        $this->assertSame('=HYPERLINK("https://evil.example","x")', $plain[1][0]);
        $this->assertSame('\'=HYPERLINK("https://evil.example","x")', $safe[1][0]);
        $this->assertSame("' \t@SUM(A1)", $safe[2][0]);
        $this->assertSame('An ordinary title', $safe[3][0]);
        $this->assertSame('https://example.com/a', $safe[1][1]);

        foreach (['-1', '+1', '@x', "\r=1", "\x01=1"] as $dangerous) {
            $this->assertStringStartsWith("'", Exporter::spreadsheetSafe($dangerous), json_encode($dangerous) ?: '');
        }
    }
}
