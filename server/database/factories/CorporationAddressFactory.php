<?php

namespace Database\Factories;

use App\Enums\AddressType;
use App\Models\Corporation;
use App\Models\CorporationAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CorporationAddress>
 */
class CorporationAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'corporation_id' => Corporation::factory(),
            'address_type' => AddressType::Phone,
            'value' => '+4533221100',
            'is_primary' => true,
            'verified_at' => now(),
        ];
    }
}
