<?php

namespace App\Support;

use App\Models\Round;
use App\Models\Visit;
use Carbon\CarbonImmutable;

/**
 * Pure slot computation for a round (S4): offered slots = round window ∩
 * staff availability − already-booked, sliced to appointment length +
 * buffer. Staff availability windows are deferred, so "availability" is the
 * round's own day hours and every staff member counts as free all day.
 *
 * The grid steps by appointment + buffer; a booked visit occupies only the
 * appointment itself (the buffer just spaces the offered grid). A slot is
 * offered while at least one staff member is free in it — parallel capacity
 * is simply how many staff are free at once (S1).
 */
class RoundSlots
{
    /**
     * @return list<array{date: string, slots: list<array{startsAt: CarbonImmutable, endsAt: CarbonImmutable}>}>
     */
    public static function compute(Round $round): array
    {
        if ($round->window_starts_on === null || $round->window_ends_on === null) {
            return [];
        }

        $today = CarbonImmutable::today();
        $firstDay = $round->window_starts_on->max($today);
        $lastDay = $round->window_ends_on;

        if ($firstDay->greaterThan($lastDay)) {
            return [];
        }

        $staffIds = $round->corporation->staff()->pluck('id');
        $step = $round->appointment_minutes + $round->buffer_minutes;

        // One query for everything that occupies staff time in the window,
        // grouped per staff for in-memory overlap checks.
        $occupied = Visit::query()
            ->occupying()
            ->whereIn('staff_id', $staffIds)
            ->where('starts_at', '<', $lastDay->endOfDay())
            ->where('ends_at', '>', $firstDay->startOfDay())
            ->get(['staff_id', 'starts_at', 'ends_at'])
            ->groupBy('staff_id');

        $days = [];

        for ($day = $firstDay; $day->lessThanOrEqualTo($lastDay); $day = $day->addDay()) {
            $dayEnd = $day->setTimeFromTimeString($round->day_ends_at);
            $slotStart = $day->setTimeFromTimeString($round->day_starts_at);
            $slots = [];

            while ($slotStart->addMinutes($round->appointment_minutes)->lessThanOrEqualTo($dayEnd)) {
                $slotEnd = $slotStart->addMinutes($round->appointment_minutes);

                if (self::someStaffFree($staffIds, $occupied, $slotStart, $slotEnd)) {
                    $slots[] = ['startsAt' => $slotStart, 'endsAt' => $slotEnd];
                }

                $slotStart = $slotStart->addMinutes($step);
            }

            if ($slots !== []) {
                $days[] = ['date' => $day->toDateString(), 'slots' => $slots];
            }
        }

        return $days;
    }

    /**
     * Whether a specific start time is currently an offered slot — the
     * server-side guard against clients posting arbitrary times.
     */
    public static function isOffered(Round $round, CarbonImmutable $startsAt): bool
    {
        foreach (self::compute($round) as $day) {
            foreach ($day['slots'] as $slot) {
                if ($slot['startsAt']->equalTo($startsAt)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $staffIds
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, Visit>>  $occupied
     */
    private static function someStaffFree($staffIds, $occupied, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($staffIds as $staffId) {
            $collides = ($occupied[$staffId] ?? collect())->contains(
                fn (Visit $visit) => $visit->starts_at < $end && $visit->ends_at > $start
            );

            if (! $collides) {
                return true;
            }
        }

        return false;
    }
}
