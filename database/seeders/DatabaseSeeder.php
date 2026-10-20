<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Single-user app. The installer creates the owner; this exists for
        // rebuilding an empty database and never adds a second account,
        // whatever email the existing one now has.
        if (User::query()->exists()) {
            $this->command->info('An owner account already exists, so no user was seeded.');

            return;
        }

        $password = config('docket.user.password');

        // No published fallback: a seeded account with a known password
        // would open the whole library to anyone who read the source.
        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set DOCKET_USER_PASSWORD in .env before seeding, or run php artisan docket:install.');
        }

        User::query()->create([
            'name' => config('docket.user.name'),
            'email' => config('docket.user.email'),
            'password' => Hash::make($password),
        ]);
    }
}
