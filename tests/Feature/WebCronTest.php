<?php

namespace Tests\Feature;

use App\Enums\FetchStatus;
use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\User;
use App\Services\Installer;
use App\Services\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebCronTest extends TestCase
{
    use RefreshDatabase;

    private string $heartbeatDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->heartbeatDir = sys_get_temp_dir().'/docket-webcron-hb-'.Str::random(8);
        config(['docket.scheduler.heartbeat_path' => $this->heartbeatDir.'/heartbeat']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->heartbeatDir);

        parent::tearDown();
    }

    private function cronUrl(): string
    {
        return '/api/cron/'.app(Installer::class)->cronToken();
    }

    public function test_a_wrong_token_gets_a_404(): void
    {
        $this->get('/api/cron/not-the-token')->assertNotFound();
    }

    public function test_the_route_404s_before_installation(): void
    {
        // No installed marker, no account: the URL stays secret.
        $this->get($this->cronUrl())->assertNotFound();
    }

    public function test_the_right_token_runs_the_work_after_the_response(): void
    {
        app(Installer::class)->markInstalled();

        $response = $this->get($this->cronUrl());

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));

        // The heartbeat was beaten by the after-response work.
        $this->assertFileExists($this->heartbeatDir.'/heartbeat');
        $this->assertTrue(app(SchedulerHeartbeat::class)->isHealthy());
    }

    public function test_the_web_cron_drains_the_queue_inline(): void
    {
        app(Installer::class)->markInstalled();
        config(['queue.default' => 'database']);

        Http::fake([
            'example.com/*' => Http::response(
                (string) file_get_contents(base_path('tests/Fixtures/article.html')),
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
        ]);

        $item = Item::factory()->create(['url' => 'https://example.com/article']);
        FetchItemContent::dispatch($item);
        $this->assertSame(FetchStatus::Pending, $item->fresh()->fetch_status);

        $this->get($this->cronUrl())->assertOk();

        $item->refresh();
        $this->assertSame(FetchStatus::Fetched, $item->fetch_status, (string) $item->fetch_error);
    }

    public function test_back_to_back_pings_each_do_the_work(): void
    {
        // The overlap lock is released when the run finishes — a ping
        // during an active run skips, the next ping must not.
        app(Installer::class)->markInstalled();

        $this->get($this->cronUrl())->assertOk();
        $firstBeat = filemtime($this->heartbeatDir.'/heartbeat');

        // Second ping straight after: must still beat and run, not be
        // skipped because the lock is still held.
        touch($this->heartbeatDir.'/heartbeat', time() - 10);
        $this->get($this->cronUrl())->assertOk();
        clearstatcache();
        $this->assertGreaterThan($firstBeat - 10, filemtime($this->heartbeatDir.'/heartbeat'));
    }

    public function test_regenerating_the_token_invalidates_the_old_url(): void
    {
        app(Installer::class)->markInstalled();
        $user = User::factory()->create();

        $old = $this->cronUrl();

        $this->actingAs($user)
            ->post('/settings/cron-token')
            ->assertRedirect(route('settings.edit'));

        $new = $this->cronUrl();
        $this->assertNotSame($old, $new);

        $this->get($old)->assertNotFound();
        $this->get($new)->assertOk();
    }

    public function test_the_route_is_throttled_and_sets_no_session_cookie(): void
    {
        app(Installer::class)->markInstalled();

        // Wrong tokens still count toward the limit but skip the work.
        for ($i = 0; $i < 10; $i++) {
            $this->get('/api/cron/not-the-token')->assertNotFound();
        }

        $this->get('/api/cron/not-the-token')->assertStatus(429);

        $response = $this->get($this->cronUrl());
        $this->assertEmpty($response->headers->getCookies());
    }
}
