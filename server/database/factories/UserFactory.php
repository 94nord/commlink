<?php

namespace Database\Factories;

use App\Models\Corporation;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'corporation_id' => Corporation::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Provision the user as an admin: link a staff member in the same
     * corporation with the admin flag set (the founder shape).
     */
    public function admin(): static
    {
        return $this->afterCreating(function ($user) {
            Staff::factory()->create([
                'corporation_id' => $user->corporation_id,
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => true,
            ]);
        });
    }
}
