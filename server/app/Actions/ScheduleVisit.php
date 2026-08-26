<?php

namespace App\Actions;

use App\Models\Staff;
use App\Models\Visit;
use App\Support\EventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only sanctioned path to occupy staff time — create, move, and resize
 * all go through here, including customer self-booking. It holds the
 * per-staff no-double-book invariant (S1/B1) with three layers:
 *
 *  1. The staff row itself is locked first — a stable anchor that serializes
 *     all scheduling per staff member even when the slot is empty (a
 *     FOR UPDATE on the overlap query alone locks nothing when no rows
 *     match, so two simultaneous first-bookings could both pass it).
 *  2. The overlap check then runs serialized behind that lock.
 *  3. A Postgres exclusion constraint (visits_no_staff_overlap) backstops
 *     the database itself; a violation is mapped to the same clean
 *     validation error, so the loser always sees "just taken".
 *
 * Lock order is uniform (staff row, then visit rows) so no deadlocks; when
 * called inside BookVisit's outer transaction the staff lock is held until
 * that outer commit — exactly what makes the second racer's re-read see the
 * winner's committed insert.
 *
 * Staff availability windows are deferred: staff are assumed available all
 * the time, so only collisions are checked — never availability.
 */
class ScheduleVisit
{
    public function handle(Visit $visit, Staff $staff, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Visit
    {
        if ($endsAt <= $startsAt) {
            throw ValidationException::withMessages([
                'ends_at' => 'The end time must be after the start time.',
            ]);
        }

        if ($staff->corporation_id !== $visit->corporation_id) {
            throw ValidationException::withMessages([
                'staff_id' => 'The staff member does not belong to this corporation.',
            ]);
        }

        try {
            return $this->schedule($visit, $staff, $startsAt, $endsAt);
        } catch (QueryException $e) {
            if (self::isOverlapViolation($e)) {
                throw ValidationException::withMessages([
                    'starts_at' => 'This staff member is already booked in that time.',
                ]);
            }

            throw $e;
        }
    }

    private function schedule(Visit $visit, Staff $staff, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Visit
    {
        return DB::transaction(function () use ($visit, $staff, $startsAt, $endsAt) {
            Staff::query()->whereKey($staff->id)->lockForUpdate()->first();

            $collision = Visit::query()
                ->occupying()
                ->where('staff_id', $staff->id)
                ->whereKeyNot($visit->id)
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->lockForUpdate()
                ->exists();

            if ($collision) {
                throw ValidationException::withMessages([
                    'starts_at' => 'This staff member is already booked in that time.',
                ]);
            }

            $previous = [
                'staff_id' => $visit->staff_id,
                'starts_at' => $visit->starts_at?->toIso8601String(),
                'ends_at' => $visit->ends_at?->toIso8601String(),
            ];

            $visit->fill([
                'staff_id' => $staff->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ])->save();

            EventRecorder::record($visit->corporation, 'visit.scheduled', subject: $visit, payload: [
                'previous' => $previous,
                'staff_id' => $staff->id,
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
            ]);

            return $visit;
        });
    }

    /**
     * Postgres exclusion-constraint violation (SQLSTATE 23P01) from the
     * visits_no_staff_overlap backstop — the DB-level twin of the overlap
     * check above.
     */
    public static function isOverlapViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? $e->getCode()) === '23P01';
    }
}
