<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Installer;
use App\Services\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class BackgroundTasksTest extends TestCase
{
    use RefreshDatabase;

    private string $heartbeatDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->heartbeatDir = sys_get_temp_dir().'/docket-heartbeat-'.Str::random(8);
        config(['docket.scheduler.heartbeat_path' => $this->heartbeatDir.'/heartbeat']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->heartbeatDir);

        parent::tearDown();
    }

    private function heartbeat(): SchedulerHeartbeat
    {
        return app(SchedulerHeartbeat::class);
    }

    public function test_a_beat_makes_the_heartbeat_healthy(): void
    {
        $this->assertNull($this->heartbeat()->lastBeat());
        $this->assertFalse($this->heartbeat()->isHealthy());

        $this->heartbeat()->beat();

        $this->assertNotNull($this->heartbeat()->lastBeat());
        $this->assertTrue($this->heartbeat()->isHealthy());
    }

    public function test_a_stale_heartbeat_is_unhealthy(): void
    {
        $this->heartbeat()->beat();
        touch($this->heartbeatDir.'/heartbeat', time() - 600);

        $this->assertFalse($this->heartbeat()->isHealthy());
    }

    public function test_the_dashboard_warns_until_the_scheduler_first_runs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('Background tasks are not running')
            ->assertSee('schedule:run');

        $this->heartbeat()->beat();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertDontSee('Background tasks are not running');
    }

    /**
     * routes/console.php registers its events when it is evaluated, so the
     * tests re-run it against a fresh Schedule instance after flipping the
     * config — the file may have been loaded at boot, before the test ran.
     *
     * @return Event[]
     */
    private function scheduledEvents(bool $viaScheduler, bool $installed = true): array
    {
        config(['docket.queue.via_scheduler' => $viaScheduler]);

        if ($installed) {
            app(Installer::class)->markInstalled();
        }
        // Both the container singleton and the facade's cached resolution.
        $this->app->forgetInstance(Schedule::class);
        \Illuminate\Support\Facades\Schedule::clearResolvedInstances();
        require base_path('routes/console.php');

        return app(Schedule::class)->events();
    }

    public function test_the_scheduler_runs_queue_work_and_the_heartbeat_when_via_scheduler(): void
    {
        $events = collect($this->scheduledEvents(true));

        $this->assertTrue(
            $events->contains(fn ($event): bool => is_string($event->command) && str_contains($event->command, 'queue:work')),
            'No scheduled queue:work event found.'
        );

        $this->assertTrue(
            $events->contains(fn ($event): bool => $event instanceof CallbackEvent),
            'No scheduled heartbeat callback found.'
        );
    }

    public function test_the_queue_worker_is_not_scheduled_when_via_scheduler_is_off(): void
    {
        $events = collect($this->scheduledEvents(false));

        $this->assertFalse(
            $events->contains(fn ($event): bool => is_string($event->command) && str_contains($event->command, 'queue:work')),
            'A queue:work event is registered even with via_scheduler off.'
        );

        $this->assertTrue(
            $events->contains(fn ($event): bool => $event instanceof CallbackEvent),
            'The heartbeat must beat regardless of the queue mode.'
        );
    }

    public function test_queue_and_backup_events_wait_for_the_installed_marker(): void
    {
        // Before installation there is no database or .env, so only the
        // heartbeat may be scheduled.
        $events = collect($this->scheduledEvents(true, installed: false));

        $this->assertFalse(
            $events->contains(fn ($event): bool => is_string($event->command) && str_contains($event->command, 'queue:work')),
        );
        $this->assertFalse(
            $events->contains(fn ($event): bool => is_string($event->command) && str_contains($event->command, 'backup:')),
        );
        $this->assertTrue(
            $events->contains(fn ($event): bool => $event instanceof CallbackEvent),
            'The heartbeat must beat even before installation.'
        );

        // Once the marker exists they register.
        $events = collect($this->scheduledEvents(true, installed: true));

        $this->assertTrue(
            $events->contains(fn ($event): bool => is_string($event->command) && str_contains($event->command, 'queue:work')),
        );
        $this->assertTrue(
            $events->contains(fn ($event): bool => is_string($event->command) && str_contains($event->command, 'backup:run')),
        );
    }

    public function test_the_cron_line_targets_the_app_and_the_cli_php(): void
    {
        $installer = app(Installer::class);

        $this->assertSame(PHP_BINARY, $installer->cliPhp());

        $this->assertSame(
            '* * * * * cd '.base_path().' && '.PHP_BINARY.' artisan schedule:run >> /dev/null 2>&1',
            $installer->cronLine()
        );
    }

    public function test_check_requirements_succeeds_when_nothing_blocks(): void
    {
        $this->artisan('docket:check-requirements')->assertExitCode(0);
    }

    public function test_check_requirements_fails_when_a_blocking_check_fails(): void
    {
        $installer = Mockery::mock(Installer::class, [$this->app])->makePartial();
        $installer->shouldReceive('requirements')->andReturn([[
            'label' => 'A blocking check',
            'ok' => false,
            'blocking' => true,
            'hint' => 'Fix it.',
        ]]);
        $this->app->instance(Installer::class, $installer);

        $this->artisan('docket:check-requirements')->assertExitCode(1);
    }

    public function test_setup_code_prints_the_code(): void
    {
        $this->artisan('docket:setup-code')
            ->expectsOutputToContain(app(Installer::class)->setupCode())
            ->assertSuccessful();
    }

    public function test_setup_code_refuses_once_installed(): void
    {
        User::factory()->create();

        $this->artisan('docket:setup-code')->assertFailed();
    }
}
