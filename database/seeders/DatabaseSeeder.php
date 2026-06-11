<?php

namespace Database\Seeders;

use App\Models\Corporation;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Creates the first corporation and admin user from the SEED_* env vars.
     * Dev convenience only — production provisions customers with
     * `php artisan app:create-corporation`.
     */
    public function run(): void
    {
        $email = env('SEED_USER_EMAIL');
        $password = env('SEED_USER_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('SEED_USER_EMAIL / SEED_USER_PASSWORD not set — nothing seeded.');

            return;
        }

        if (User::where('email', $email)->exists()) {
            return;
        }

        $corporation = Corporation::create([
            'name' => env('SEED_CORPORATION_NAME', 'Example Corp'),
        ]);

        $corporation->users()->forceCreate([
            'name' => env('SEED_USER_NAME', 'Example Admin'),
            'email' => $email,
            'email_verified_at' => now(),
            'password' => $password,
        ]);
    }
}
