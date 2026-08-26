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

        $user = $corporation->users()->forceCreate([
            'name' => env('SEED_USER_NAME', 'Example Admin'),
            'email' => $email,
            'email_verified_at' => now(),
            'password' => $password,
        ]);

        // S2: a corporation always has at least one staff member — the
        // founder, who is the admin and can log in.
        $corporation->staff()->create([
            'user_id' => $user->id,
            'name' => env('SEED_USER_NAME', 'Example Admin'),
            'email' => $email,
            'is_admin' => true,
            'is_founder' => true,
        ]);
    }
}
