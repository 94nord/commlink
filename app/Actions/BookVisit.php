<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\RoundWorkOrder;
use App\Models\Visit;
use App\Support\EventRecorder;
use App\Support\RoundSlots;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer self-booking through a round membership (magic link): books a
 * first slot or reschedules the existing one (B1/B2). The requested time is
 * validated against the round's offered grid server-side — never trusted
 * from the client — and staff is resolved at booking time (whoever's free).
 * Occupying the slot still goes through ScheduleVisit, which holds the
 * per-staff no-double-book invariant; a simultaneous loser gets a clean
 * "just taken" error.
 */
class BookVisit
{
    public function __construct(private ScheduleVisit $scheduler)
    {
    }

    public function handle(RoundWorkOrder $membership, CarbonImmutable $startsAt): Visit
    {
        $round = $membership->round;
        $workOrder = $membership->workOrder;

        if (! $round->isBookable()) {
            throw ValidationException::withMessages([
                'starts_at' => 'Booking is closed for this round.',
            ]);
        }

        if (! RoundSlots::isOffered($round, $startsAt)) {
            throw ValidationException::withMessages([
                'starts_at' => 'That time is not available — pick one of the offered slots.',
            ]);
        }

        $endsAt = $startsAt->addMinutes($round->appointment_minutes);

        $existing = $round->visits()
            ->where('work_order_id', $workOrder->id)
            ->whereNull('cancelled_at')
            ->first();

        // Whoever's free takes the visit; with one staff member this is
        // trivial. Each attempt is all-or-nothing so a collision never
        // leaves a half-created visit behind.
        foreach ($round->corporation->staff()->orderBy('id')->get() as $staff) {
            try {
                return DB::transaction(function () use ($round, $workOrder, $existing, $staff, $startsAt, $endsAt) {
                    $visit = $existing;

                    if ($visit === null) {
                        $visit = $round->corporation->visits()->create([
                            'owner_type' => Customer::class,
                            'owner_id' => $workOrder->customer_id,
                            'work_order_id' => $workOrder->id,
                            'round_id' => $round->id,
                            'type' => $round->type,
                        ]);

                        EventRecorder::record($round->corporation, 'visit.created', subject: $visit);
                    }

                    return $this->scheduler->handle($visit, $staff, $startsAt, $endsAt);
                });
            } catch (ValidationException) {
                // This staff member lost the race or is booked — try the next.
                continue;
            }
        }

        throw ValidationException::withMessages([
            'starts_at' => 'That time was just taken — pick another slot.',
        ]);
    }
}
