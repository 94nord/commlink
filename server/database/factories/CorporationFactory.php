<?php

namespace Database\Factories;

use App\Enums\AddressType;
use App\Enums\Module;
use App\Models\Corporation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Corporation>
 */
class CorporationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
        ];
    }

    /**
     * A firm holding a number, and nothing else said about it. Deliberately
     * not switched on for anything: an address and a module are two separate
     * facts, and a test about one should not silently get the other.
     */
    public function withPhone(string $value = '+4533221100'): static
    {
        return $this->afterCreating(fn (Corporation $corporation) => $corporation->addresses()->create([
            'address_type' => AddressType::Phone,
            'value' => $value,
            'is_primary' => true,
            'verified_at' => now(),
        ]));
    }

    /**
     * A firm that can actually text: a number to send from *and* the module
     * switched on. What almost every inbox test wants, since either one
     * missing means nothing sends.
     */
    public function texting(string $value = '+4533221100'): static
    {
        return $this->withPhone($value)->hasModule(Module::Sms);
    }

    /**
     * A firm switched on for a module.
     */
    public function hasModule(Module $module): static
    {
        return $this->afterCreating(
            fn (Corporation $corporation) => $corporation->modules()->create(['module' => $module]),
        );
    }
}
