<?php

namespace Tests\Feature;

use App\Jobs\FetchItemContent;
use App\Models\Item;
use App\Models\User;
use App\Services\FetchDispatcher;
use App\Services\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FetchDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private string $heartbeatDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->heartbeatDir = sys_get_temp_dir().'/docket-dispatch-hb-'.Str::random(8);
        config(['docket.scheduler.heartbeat_path' => $this->heartbeatDir.'/heartbeat']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->heartbeatDir);

        parent::tearDown();
    }

    public function test_a_sync_queue_dispatches_the_job_inline(): void
    {
        config(['queue.default' => 'sync']);
        Queue::fake();

        app(FetchDispatcher::class)->dispatch(Item::factory()->create());

        Queue::assertPushed(FetchItemContent::class);
    }

    public function test_a_healthy_scheduler_means_the_job_is_queued(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();
        app(SchedulerHeartbeat::class)->beat();

        app(FetchDispatcher::class)->dispatch(Item::factory()->create());

        Bus::assertDispatched(FetchItemContent::class);
        Bus::assertNotDispatchedAfterResponse(FetchItemContent::class);
    }

    public function test_a_dead_cron_over_http_runs_the_fetch_after_the_response(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();

        // PHPUnit itself runs in the console; pretend to be the web SAPI.
        $console = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false);

        // With the fake web SAPI, CSRF applies again — send a real token.
        $this->actingAs(User::factory()->create())
            ->withSession(['_token' => 'test-token'])
            ->post('/items', ['url' => 'https://example.com/article', '_token' => 'test-token'])
            ->assertRedirect();

        Bus::assertDispatchedAfterResponse(FetchItemContent::class);
        Bus::assertNotDispatchedSync(FetchItemContent::class);
    }

    public function test_a_dead_cron_in_console_runs_the_fetch_inline(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();

        // runningInConsole() is true under the test runner.
        app(FetchDispatcher::class)->dispatch(Item::factory()->create());

        Bus::assertDispatchedSync(FetchItemContent::class);
        Bus::assertNotDispatchedAfterResponse(FetchItemContent::class);
    }
}
