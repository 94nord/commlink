<?php

namespace Tests\Feature;

use App\Models\Round;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visit;
use App\Support\RoundSlots;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoundSlotsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->staff = Staff::factory()->for($this->user->corporation)->create();
    }

    private function makeRound(array $attributes = []): Round
    {
        return Round::factory()->for($this->user->corporation)->open()->create(array_merge([
            'window_starts_on' => now()->addDay()->toDateString(),
            'window_ends_on' => now()->addDays(2)->toDateString(),
            'day_starts_at' => '08:00',
            'day_ends_at' => '16:00',
            'appointment_minutes' => 60,
            'buffer_minutes' => 0,
        ], $attributes));
    }

    public function test_it_slices_the_window_into_appointment_slots()
    {
        $days = RoundSlots::compute($this->makeRound());

        // 08–16 with 60-minute appointments = 8 slots on each of 2 days.
        $this->assertCount(2, $days);
        $this->assertCount(8, $days[0]['slots']);
        $this->assertCount(8, $days[1]['slots']);

        $first = $days[0]['slots'][0];
        $this->assertSame('08:00', $first['startsAt']->format('H:i'));
        $this->assertSame('09:00', $first['endsAt']->format('H:i'));
        $this->assertSame('15:00', end($days[0]['slots'])['startsAt']->format('H:i'));
    }

    public function test_the_buffer_spaces_the_grid()
    {
        $days = RoundSlots::compute($this->makeRound([
            'window_ends_on' => now()->addDay()->toDateString(),
            'buffer_minutes' => 30,
        ]));

        // Step 90: 08:00, 09:30, 11:00, 12:30, 14:00 (15:30 would end 16:30).
        $starts = array_map(fn ($slot) => $slot['startsAt']->format('H:i'), $days[0]['slots']);
        $this->assertSame(['08:00', '09:30', '11:00', '12:30', '14:00'], $starts);
    }

    public function test_days_before_today_are_not_offered()
    {
        $days = RoundSlots::compute($this->makeRound([
            'window_starts_on' => now()->subDays(2)->toDateString(),
            'window_ends_on' => now()->addDay()->toDateString(),
        ]));

        $this->assertSame(now()->toDateString(), $days[0]['date']);
    }

    public function test_a_fully_past_window_offers_nothing()
    {
        $days = RoundSlots::compute($this->makeRound([
            'window_starts_on' => now()->subDays(5)->toDateString(),
            'window_ends_on' => now()->subDay()->toDateString(),
        ]));

        $this->assertSame([], $days);
    }

    public function test_a_booked_slot_disappears_with_a_single_staff_member()
    {
        $round = $this->makeRound(['window_ends_on' => now()->addDay()->toDateString()]);
        $slotStart = CarbonImmutable::parse(now()->addDay()->toDateString().' 08:00');

        Visit::factory()->for($this->user->corporation)
            ->scheduled($this->staff, $slotStart, $slotStart->addMinutes(60))
            ->create();

        $days = RoundSlots::compute($round);
        $starts = array_map(fn ($slot) => $slot['startsAt']->format('H:i'), $days[0]['slots']);

        $this->assertNotContains('08:00', $starts);
        $this->assertContains('09:00', $starts);
    }

    public function test_a_second_free_staff_member_keeps_the_slot_offered()
    {
        Staff::factory()->for($this->user->corporation)->create();

        $round = $this->makeRound(['window_ends_on' => now()->addDay()->toDateString()]);
        $slotStart = CarbonImmutable::parse(now()->addDay()->toDateString().' 08:00');

        Visit::factory()->for($this->user->corporation)
            ->scheduled($this->staff, $slotStart, $slotStart->addMinutes(60))
            ->create();

        $days = RoundSlots::compute($round);
        $starts = array_map(fn ($slot) => $slot['startsAt']->format('H:i'), $days[0]['slots']);

        $this->assertContains('08:00', $starts);
    }

    public function test_is_offered_accepts_grid_slots_and_rejects_off_grid_times()
    {
        $round = $this->makeRound();
        $day = now()->addDay()->toDateString();

        $this->assertTrue(RoundSlots::isOffered($round, CarbonImmutable::parse("$day 09:00")));
        $this->assertFalse(RoundSlots::isOffered($round, CarbonImmutable::parse("$day 09:17")));
        $this->assertFalse(RoundSlots::isOffered($round, CarbonImmutable::parse("$day 16:00")));
    }
}
