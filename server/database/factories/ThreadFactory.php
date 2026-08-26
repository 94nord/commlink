<?php

namespace Database\Factories;

use App\Models\Corporation;
use App\Models\Identity;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Thread>
 */
class ThreadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A default (unkeyed) thread, which is the only kind SMS has.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'corporation_id' => Corporation::factory(),
            'identity_id' => fn (array $attributes) => Identity::factory()
                ->create(['corporation_id' => $attributes['corporation_id']]),
            'external_key' => null,
        ];
    }
}
