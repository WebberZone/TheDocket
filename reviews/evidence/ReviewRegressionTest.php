<?php

use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use App\Jobs\FetchItemContent;
use App\Services\ImageLocaliser;
use App\Services\Fetching\SafeHttpClient;
use App\Services\Installer;
use App\Services\Exporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_hostname_fetch_throws_with_locked_guzzle(): void
    {
        Http::allowStrayRequests();
        // Isolated loopback fixture only. No external destination or proxy bypass.
        Http::globalOptions(['proxy' => '']);
        $this->dns->set('docket-rebind.test', '93.184.216.34');
        $this->expectException(\GuzzleHttp\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Passing the "curl" request option to the stream handler is not supported');
        app(SafeHttpClient::class)->get('http://docket-rebind.test:8999/secret', 1024);
    }

    public function test_lists_load_full_article_bodies(): void
    {
        Item::factory()->count(30)->create(['content_html' => str_repeat('h', 262144), 'content_text' => str_repeat('t', 262144)]);
        gc_collect_cycles();
        $start = memory_get_usage();
        $items = Item::query()->with('tags')->paginate(30);
        $used = memory_get_usage() - $start;
        fwrite(STDERR, "\nLIST MEMORY: {$used} bytes for 30 items with 256 KiB HTML + 256 KiB text each\n");
        $this->assertGreaterThan(15 * 1024 * 1024, $used);
        $this->assertSame(262144, strlen($items[0]->content_html));
    }

    public function test_failed_images_do_not_count_towards_attempt_cap(): void
    {
        config(['docket.images.max_per_item' => 2]);
        Http::fake(['*' => Http::response('', 404)]);
        $item = Item::factory()->create();
        $html = '';
        for ($i = 0; $i < 7; $i++) {
            $html .= '<img src="https://images.example.com/'.$i.'.png">';
        }
        app(ImageLocaliser::class)->localise($item, $html);
        Http::assertSentCount(7);
    }

    public function test_disabled_image_downloads_still_fetch_lead_image(): void
    {
        Storage::fake(Item::DISK);
        config(['docket.images.enabled' => false, 'docket.images.max_per_item' => 0]);
        Http::fake([
            'https://example.com/article' => Http::response(file_get_contents('/app/tests/Fixtures/article.html'), 200, ['Content-Type' => 'text/html']),
            '*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')),
        ]);
        $item = Item::factory()->create(['url' => 'https://example.com/article']);
        FetchItemContent::dispatchSync($item);
        $this->assertNotNull($item->fresh()->lead_image_path);
        Http::assertSentCount(2);
    }

    public function test_failed_storage_write_still_rewrites_image(): void
    {
        Http::fake(['*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='))]);
        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with(Item::DISK)->andReturn($disk);
        $item = Item::factory()->create();
        $html = app(ImageLocaliser::class)->localise($item, '<img src="https://example.com/image.png">');
        $this->assertStringContainsString('/items/'.$item->id.'/images/', $html);
        $this->assertStringNotContainsString('src="https://example.com/image.png"', $html);
    }

    public function test_export_keeps_spreadsheet_formula(): void
    {
        Item::factory()->create(['title' => '=1+1']);
        $handle = fopen('php://temp', 'w+');
        app(Exporter::class)->writeCsv($handle);
        rewind($handle);
        fgetcsv($handle, escape: '');
        $row = fgetcsv($handle, escape: '');
        fclose($handle);
        $this->assertSame('=1+1', $row[0]);
    }

    public function test_installed_check_fails_open_on_database_exception(): void
    {
        Schema::shouldReceive('hasTable')->with('users')->andThrow(new RuntimeException('database locked'));
        $this->assertFalse(app(Installer::class)->isInstalled());
    }

    public function test_slash_tag_cannot_be_opened(): void
    {
        $tag = Tag::create(['name' => 'work/personal', 'slug' => Tag::slugFor('work/personal')]);
        $url = route('items.tagged', $tag, false);
        fwrite(STDERR, "\nTAG URL: {$url}\n");
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_sync_refetch_skips_items_after_first_page(): void
    {
        config(['queue.default' => 'sync', 'docket.import.spacing_seconds' => 0]);
        Http::fake(fn () => Http::response('<html><head><title>Article</title></head><body><article><p>'.str_repeat('A readable paragraph about reading and libraries. ', 30).'</p></article></body></html>', 200, ['Content-Type' => 'text/html']));
        Item::factory()->count(1001)->failed()->create(['fetch_attempts' => 0]);
        // Remove factory payload from serialized jobs: it isn't needed for this reproduction.
        Item::query()->update(['content_html' => null, 'content_text' => null]);
        $this->artisan('docket:refetch')->assertSuccessful();
        $this->assertSame(1, Item::query()->where('fetch_attempts', 0)->count());
    }

    public function test_database_queue_reserves_same_job_before_its_timeout(): void
    {
        $connection = Queue::connection('database');
        $connection->push(new FetchItemContent(Item::factory()->create()));
        $first = $connection->pop();
        $this->travel(91)->seconds();
        $second = $connection->pop();
        $this->assertNotNull($second);
        $this->assertSame($first->getJobId(), $second->getJobId());
        $this->assertLessThan((new FetchItemContent(Item::factory()->create()))->timeout, 91);
    }

    public function test_refetch_failure_hides_previous_good_article(): void
    {
        Http::fake(fn () => Http::response('', 404));
        $item = Item::factory()->fetched('The preserved article body.')->create();
        $this->assertTrue($item->hasArticle());
        FetchItemContent::dispatchSync($item);
        $item->refresh();
        $this->assertNotNull($item->content_html);
        $this->assertFalse($item->hasArticle());
    }

    public function test_interleaved_installs_create_two_owners(): void
    {
        $originalEnvPath = $this->app->environmentPath();
        $temporary = sys_get_temp_dir().'/docket-review-install-'.bin2hex(random_bytes(5));
        mkdir($temporary);
        copy('/app/.env.example', $temporary.'/.env.example');
        $this->app->useEnvironmentPath($temporary);
        $real = new Installer($this->app);
        $partial = Mockery::mock(Installer::class, [$this->app])->makePartial();
        $entered = false;
        $payload = ['url' => 'https://docket.example.com', 'name' => 'Review owner', 'email' => 'owner-a@example.com', 'password' => 'review-pass-123', 'password_confirmation' => 'review-pass-123'];
        $partial->shouldReceive('createOwner')->andReturnUsing(function ($name, $email, $password) use (&$entered, $real, $payload) {
            if (! $entered) {
                $entered = true;
                $this->post('/install/setup', array_replace($payload, ['email' => 'owner-b@example.com']))->assertOk();
            }
            return $real->createOwner($name, $email, $password);
        });
        $this->app->instance(Installer::class, $partial);
        try {
            $this->post('/install/setup', $payload)->assertOk();
            $this->assertSame(2, User::count());
        } finally {
            $this->app->useEnvironmentPath($originalEnvPath);
            @unlink($temporary.'/.env');
            @unlink($temporary.'/.env.example');
            @rmdir($temporary);
        }
    }

    public function test_seeding_with_changed_owner_email_adds_known_password_account(): void
    {
        config(['docket.user.email' => 'old-owner@example.com', 'docket.user.name' => 'Old owner', 'docket.user.password' => 'change-me-on-first-login']);
        User::factory()->create(['email' => 'new-owner@example.com']);
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->assertSame(2, User::count());
        $seeded = User::where('email', 'old-owner@example.com')->firstOrFail();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('change-me-on-first-login', $seeded->password));
    }
}
