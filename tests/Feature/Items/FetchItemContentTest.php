<?php

namespace Tests\Feature\Items;

use App\Enums\FetchStatus;
use App\Enums\ItemSource;
use App\Exceptions\FetchFailedException;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\User;
use App\Services\UrlNormaliser;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class FetchItemContentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A valid 1×1 PNG, so the stored-image MIME sniffing has real bytes.
     */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private const ARTICLE_URL = 'https://example.com/article';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Item::DISK);
    }

    private function item(array $attributes = []): Item
    {
        $url = $attributes['url'] ?? self::ARTICLE_URL;

        return Item::factory()->create($attributes + [
            'url' => $url,
            'normalized_url' => app(UrlNormaliser::class)->normalise($url),
            'title' => null,
        ]);
    }

    private function fakeArticle(): void
    {
        $png = (string) base64_decode(self::PNG);

        Http::fake([
            self::ARTICLE_URL => Http::response(
                (string) file_get_contents(base_path('tests/Fixtures/article.html')),
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://example.com/images/reading-room.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'https://cdn.example.com/lead.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'https://cdn.example.com/diagram.svg' => Http::response(
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                200,
                ['Content-Type' => 'image/svg+xml']
            ),
        ]);
    }

    public function test_extracts_the_article_and_its_metadata(): void
    {
        $this->fakeArticle();
        $item = $this->item();

        FetchItemContent::dispatchSync($item);
        $item->refresh();

        $this->assertSame(FetchStatus::Fetched, $item->fetch_status);
        $this->assertNull($item->fetch_error);
        $this->assertNotNull($item->fetched_at);
        $this->assertSame(1, $item->fetch_attempts);

        $this->assertSame('Why slow reading matters', $item->title);
        $this->assertSame('The Example Review', $item->site_name);
        $this->assertSame('Jane Writer', $item->author);
        $this->assertSame('A short case for reading fewer things, more carefully.', $item->excerpt);
        $this->assertSame('en-GB', $item->language);
        $this->assertSame('2025-03-14', $item->published_at?->toDateString());
        $this->assertSame('https://cdn.example.com/lead.png', $item->lead_image_url);

        $this->assertStringContainsString('Slow reading is the opposite habit', (string) $item->content_html);
        $this->assertStringContainsString('Slow reading is the opposite habit', (string) $item->content_text);
        $this->assertStringNotContainsString('<p', (string) $item->content_text);

        $this->assertGreaterThan(150, $item->word_count);
        $this->assertSame((int) ceil($item->word_count / 230), $item->reading_time);
    }

    public function test_stored_html_is_sanitised(): void
    {
        $this->fakeArticle();
        $item = $this->item();

        FetchItemContent::dispatchSync($item);
        $html = (string) $item->refresh()->content_html;

        foreach (['<script', '<style', '<iframe', 'onclick', 'onerror', 'javascript:', 'alert(', 'tracking'] as $unsafe) {
            $this->assertStringNotContainsStringIgnoringCase($unsafe, $html, "Stored HTML still contains {$unsafe}");
        }

        // Safe markup survives, with links hardened.
        $this->assertStringContainsString('href="https://www.example.org/further-reading"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
        $this->assertStringContainsString('This link should lose its href.', $html);
    }

    public function test_inline_images_are_downloaded_and_rewritten(): void
    {
        $this->fakeArticle();
        $item = $this->item();

        FetchItemContent::dispatchSync($item);
        $item->refresh();

        $filename = sha1('https://example.com/images/reading-room.png').'.png';
        $path = "items/{$item->id}/{$filename}";

        Storage::disk(Item::DISK)->assertExists($path);
        $this->assertStringContainsString(
            'src="'.route('items.image', ['item' => $item, 'filename' => $filename], false).'"',
            (string) $item->content_html
        );
        $this->assertStringNotContainsString('src="https://example.com/images/reading-room.png"', (string) $item->content_html);

        // The lead image is kept locally too.
        $this->assertSame(sha1('https://cdn.example.com/lead.png').'.png', $item->lead_image_path);

        // SVG is never stored: it could carry scripts and would be served
        // from this origin.
        foreach (Storage::disk(Item::DISK)->files("items/{$item->id}") as $file) {
            $this->assertStringEndsWith('.png', $file);
        }

        // And the stored copy is served to the owner.
        $this->actingAs(User::factory()->create())
            ->get(route('items.image', ['item' => $item, 'filename' => $filename]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_images_are_only_served_to_the_owner_and_only_by_exact_name(): void
    {
        $item = $this->item();
        $filename = sha1('x').'.png';
        Storage::disk(Item::DISK)->put("items/{$item->id}/{$filename}", (string) base64_decode(self::PNG));

        $this->get(route('items.image', ['item' => $item, 'filename' => $filename]))->assertRedirect('/login');

        $user = User::factory()->create();
        $this->actingAs($user)->get("/items/{$item->id}/images/".sha1('missing').'.png')->assertNotFound();
        $this->actingAs($user)->get("/items/{$item->id}/images/..%2F..%2F.env")->assertNotFound();
    }

    public function test_deleting_an_item_removes_its_stored_images(): void
    {
        $this->fakeArticle();
        $item = $this->item();
        FetchItemContent::dispatchSync($item);

        $this->assertNotEmpty(Storage::disk(Item::DISK)->files("items/{$item->id}"));

        $this->actingAs(User::factory()->create())->delete("/items/{$item->id}")->assertRedirect();

        $this->assertModelMissing($item);
        Storage::disk(Item::DISK)->assertMissing("items/{$item->id}");
    }

    public function test_a_page_title_from_the_bookmarklet_is_replaced_by_the_extracted_one(): void
    {
        $this->fakeArticle();
        $item = $this->item(['title' => 'Why slow reading matters | The Example Review | Home', 'source' => ItemSource::Bookmarklet]);

        FetchItemContent::dispatchSync($item);

        $this->assertSame('Why slow reading matters', $item->refresh()->title);
    }

    public function test_a_title_given_by_hand_is_kept(): void
    {
        $this->fakeArticle();
        $item = $this->item(['title' => 'My own title', 'source' => ItemSource::Web]);

        FetchItemContent::dispatchSync($item);

        $this->assertSame('My own title', $item->refresh()->title);
    }

    public function test_a_missing_page_is_recorded_as_a_failure_without_retrying(): void
    {
        Http::fake([self::ARTICLE_URL => Http::response('Not found', 404)]);
        $item = $this->item();

        FetchItemContent::dispatchSync($item);
        $item->refresh();

        $this->assertSame(FetchStatus::Failed, $item->fetch_status);
        $this->assertSame('The site responded with HTTP 404.', $item->fetch_error);
        $this->assertSame(1, $item->fetch_attempts);
    }

    public function test_a_transient_failure_is_retried_while_attempts_remain(): void
    {
        Http::fake([self::ARTICLE_URL => Http::response('Busy', 503)]);
        $item = $this->item();

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);

        $job = new FetchItemContent($item);
        $job->setJob($queueJob);

        try {
            app()->call([$job, 'handle']);
            $this->fail('A transient failure on the first attempt should be rethrown for the queue to retry.');
        } catch (FetchFailedException $e) {
            $this->assertFalse($e->permanent);
        }

        $this->assertSame(FetchStatus::Pending, $item->refresh()->fetch_status);
    }

    public function test_a_transient_failure_on_the_last_attempt_is_recorded(): void
    {
        Http::fake([self::ARTICLE_URL => Http::response('Busy', 503)]);
        $item = $this->item();

        FetchItemContent::dispatchSync($item);
        $item->refresh();

        $this->assertSame(FetchStatus::Failed, $item->fetch_status);
        $this->assertSame('The site responded with HTTP 503.', $item->fetch_error);
    }

    public function test_links_that_are_not_web_pages_are_recorded_as_failures(): void
    {
        Http::fake([self::ARTICLE_URL => Http::response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf'])]);
        $item = $this->item();

        FetchItemContent::dispatchSync($item);
        $item->refresh();

        $this->assertSame(FetchStatus::Failed, $item->fetch_status);
        $this->assertStringContainsString('application/pdf', (string) $item->fetch_error);
    }

    public function test_a_page_without_an_article_is_recorded_as_a_failure(): void
    {
        Http::fake([self::ARTICLE_URL => Http::response('<html><head><title>Empty</title></head><body></body></html>', 200, ['Content-Type' => 'text/html'])]);
        $item = $this->item();

        FetchItemContent::dispatchSync($item);
        $item->refresh();

        $this->assertSame(FetchStatus::Failed, $item->fetch_status);
        $this->assertStringContainsString('Could not find any article text', (string) $item->fetch_error);
    }

    public function test_a_legacy_charset_is_converted_to_utf8(): void
    {
        $html = (string) file_get_contents(base_path('tests/Fixtures/article.html'));
        $html = str_replace('Most of what we read', 'Café culture: most of what we read', $html);

        Http::fake([
            self::ARTICLE_URL => Http::response(
                (string) mb_convert_encoding($html, 'ISO-8859-1', 'UTF-8'),
                200,
                ['Content-Type' => 'text/html; charset=ISO-8859-1']
            ),
            '*' => Http::response('', 404),
        ]);
        $item = $this->item();

        FetchItemContent::dispatchSync($item);

        $this->assertStringContainsString('Café culture', (string) $item->refresh()->content_text);
    }

    public function test_failed_items_can_be_retried(): void
    {
        Queue::fake();
        $item = Item::factory()->failed()->create();

        $this->actingAs(User::factory()->create())
            ->from('/')
            ->post("/items/{$item->id}/retry")
            ->assertRedirect('/');

        $item->refresh();
        $this->assertSame(FetchStatus::Pending, $item->fetch_status);
        $this->assertNull($item->fetch_error);
        $this->assertSame(0, $item->fetch_attempts);

        Queue::assertPushed(FetchItemContent::class, fn (FetchItemContent $job) => $job->item->is($item));
    }

    public function test_the_job_retries_on_the_configured_schedule(): void
    {
        config(['docket.fetch.tries' => 4, 'docket.fetch.backoff' => [5, 25]]);

        $job = new FetchItemContent($this->item());

        $this->assertSame(4, $job->tries());
        $this->assertSame([5, 25], $job->backoff());
    }
}
