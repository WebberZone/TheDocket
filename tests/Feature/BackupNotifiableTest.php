<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\BackupNotifiable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupNotifiableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_configured_backup_address_wins(): void
    {
        config(['backup.notifications.mail.to' => 'alerts@example.com']);
        User::factory()->create(['email' => 'owner@example.com']);

        $this->assertSame('alerts@example.com', app(BackupNotifiable::class)->email());
        $this->assertSame('alerts@example.com', app(BackupNotifiable::class)->routeNotificationForMail());
    }

    public function test_it_falls_back_to_the_owner_email_in_the_database(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $this->assertSame('owner@example.com', app(BackupNotifiable::class)->email());
    }

    public function test_it_follows_a_profile_email_change_immediately(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $this->assertSame('old@example.com', app(BackupNotifiable::class)->email());

        $user->forceFill(['email' => 'new@example.com'])->save();
        $this->assertSame('new@example.com', app(BackupNotifiable::class)->email());
    }

    public function test_no_account_and_no_config_means_nothing_to_route(): void
    {
        $this->assertNull(app(BackupNotifiable::class)->email());
        $this->assertSame([], app(BackupNotifiable::class)->routeNotificationForMail());
    }

    public function test_an_invalid_configured_address_falls_back_to_the_owner(): void
    {
        config(['backup.notifications.mail.to' => 'not-an-email']);
        User::factory()->create(['email' => 'owner@example.com']);

        $this->assertSame('owner@example.com', app(BackupNotifiable::class)->email());
    }

    public function test_the_settings_page_shows_the_resolved_address(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->actingAs($user)
            ->get('/settings')
            ->assertOk()
            ->assertSee('Backup failure notifications')
            ->assertSee('owner@example.com');

        config(['backup.notifications.mail.to' => 'alerts@example.com']);

        $this->actingAs($user)
            ->get('/settings')
            ->assertOk()
            ->assertSee('alerts@example.com');
    }
}
