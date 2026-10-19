<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Single-user app: one seeded account, no registration flow.
        // Set DOCKET_USER_PASSWORD in .env before seeding in production.
        User::firstOrCreate(
            ['email' => config('docket.user.email')],
            [
                'name' => config('docket.user.name'),
                'password' => Hash::make(config('docket.user.password')),
            ]
        );
    }
}
