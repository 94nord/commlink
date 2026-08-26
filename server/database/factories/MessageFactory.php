<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\MessageDirection;
use App\Models\Corporation;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
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
            'thread_id' => fn (array $attributes) => Thread::factory()
                ->create(['corporation_id' => $attributes['corporation_id']]),
            'user_id' => null,
            'channel' => Channel::Sms,
            'direction' => MessageDirection::Inbound,
            'body' => fake()->sentence(),
            'channel_data' => null,
            'sent_at' => now(),
        ];
    }

    /**
     * Written by the firm rather than the customer.
     */
    public function outbound(?User $author = null): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => MessageDirection::Outbound,
            'user_id' => $author?->id ?? User::factory()->create([
                'corporation_id' => $attributes['corporation_id'],
            ])->id,
        ]);
    }
}
