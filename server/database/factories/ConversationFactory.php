<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Corporation;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * About somebody nobody has named, which is how exchanges start.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'corporation_id' => Corporation::factory(),
            'customer_id' => fn (array $attributes) => Customer::factory()
                ->create(['corporation_id' => $attributes['corporation_id']]),
            'status' => ConversationStatus::Open,
            'last_message_at' => null,
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn () => [
            'corporation_id' => $customer->corporation_id,
            'customer_id' => $customer->id,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => ConversationStatus::Archived,
        ]);
    }
}
