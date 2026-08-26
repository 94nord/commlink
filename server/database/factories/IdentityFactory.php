<?php

namespace Database\Factories;

use App\Enums\AddressType;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\Identity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Identity>
 */
class IdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The owner is created in the same corporation as the address — a
     * split-tenant pair is never a valid row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'corporation_id' => Corporation::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()
                ->create(['corporation_id' => $attributes['corporation_id']]),
            'address_type' => AddressType::Phone,
            'value' => CustomerFactory::danishMobile(),
        ];
    }
}
