<?php

namespace Database\Factories;

use App\Enums\VisitType;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Visit>
 */
class VisitFactory extends Factory
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
            'owner_type' => Customer::class,
            'owner_id' => fn (array $attributes) => Customer::factory()->for(
                Corporation::find($attributes['corporation_id'])
            ),
            'staff_id' => fn (array $attributes) => Staff::factory()->for(
                Corporation::find($attributes['corporation_id'])
            )->createOne()->id,
            'type' => VisitType::Survey,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ];
    }

    /**
     * Override with a specific staff member and time window.
     */
    public function scheduled(Staff $staff, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt): static
    {
        return $this->state(fn () => [
            'staff_id' => $staff->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'cancelled_at' => now(),
        ]);
    }
}
