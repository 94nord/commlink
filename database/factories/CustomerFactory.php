<?php

namespace Database\Factories;

use App\Enums\CustomerKind;
use App\Models\Corporation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'corporation_id' => Corporation::factory(),
            'kind' => CustomerKind::Person,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->streetAddress(),
        ];
    }

    public function organization(): static
    {
        return $this->state(fn () => [
            'kind' => CustomerKind::Organization,
            'name' => fake()->company(),
        ]);
    }
}
