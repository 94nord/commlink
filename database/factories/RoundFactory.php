<?php

namespace Database\Factories;

use App\Enums\RoundStatus;
use App\Enums\VisitType;
use App\Models\Corporation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Round>
 */
class RoundFactory extends Factory
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
            'type' => VisitType::Survey,
            'status' => RoundStatus::Draft,
            'appointment_minutes' => 60,
            'buffer_minutes' => 0,
            'window_starts_on' => now()->addWeek()->toDateString(),
            'window_ends_on' => now()->addWeeks(2)->toDateString(),
            'day_starts_at' => '08:00',
            'day_ends_at' => '16:00',
            'close_off_on' => now()->addMonth()->toDateString(),
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => RoundStatus::Open]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => RoundStatus::Closed]);
    }
}
