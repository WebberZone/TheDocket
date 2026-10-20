<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_never_adds_a_second_account(): void
    {
        // The owner changed their email on the Profile page; .env still has
        // the old one. Seeding must not resurrect the old address.
        config(['docket.user.email' => 'old-owner@example.com', 'docket.user.password' => 'some-password-1']);
        User::factory()->create(['email' => 'new-owner@example.com']);

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $this->assertSame(['new-owner@example.com'], User::query()->pluck('email')->all());
    }

    public function test_seeding_an_empty_database_needs_an_explicit_password(): void
    {
        config(['docket.user.password' => null]);

        try {
            Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
            $this->fail('Seeding without DOCKET_USER_PASSWORD should refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('DOCKET_USER_PASSWORD', $e->getMessage());
        }

        $this->assertSame(0, User::query()->count());
    }

    public function test_seeding_an_empty_database_with_a_password(): void
    {
        config([
            'docket.user.name' => 'Owner',
            'docket.user.email' => 'owner@example.com',
            'docket.user.password' => 'chosen-password-1',
        ]);

        Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $user = User::query()->sole();
        $this->assertSame('owner@example.com', $user->email);
        $this->assertTrue(Hash::check('chosen-password-1', $user->password));
    }
}
