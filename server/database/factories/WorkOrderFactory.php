<?php

namespace Database\Factories;

use App\Models\Corporation;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkOrder>
 */
class WorkOrderFactory extends Factory
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
            'customer_id' => fn (array $attributes) => Customer::factory()->for(
                Corporation::find($attributes['corporation_id'])
            ),
            'address' => fake()->streetAddress(),
        ];
    }
}
