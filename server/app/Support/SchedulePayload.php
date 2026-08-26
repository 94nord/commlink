<?php

namespace App\Support;

use App\Models\Corporation;
use App\Models\Round;
use Illuminate\Support\Collection;

/**
 * Builds the schedule page props shared by the visits page and the dashboard
 * calendar — one shape, so the two surfaces can't drift apart.
 */
class SchedulePayload
{
    public static function visits(Corporation $corporation): Collection
    {
        return $corporation->visits()
            ->with(['staff', 'workOrder.customer'])
            ->whereNull('cancelled_at')
            ->orderBy('starts_at')
            ->get()
            ->map(fn ($visit) => [
                'id' => $visit->id,
                'type' => $visit->type->value,
                'staffId' => $visit->staff_id,
                'staffName' => $visit->staff?->name,
                'startsAt' => $visit->starts_at?->toIso8601String(),
                'endsAt' => $visit->ends_at?->toIso8601String(),
                'cancelledAt' => $visit->cancelled_at?->toIso8601String(),
                'caseId' => $visit->work_order_id,
                'caseAddress' => $visit->workOrder?->address,
                'customerName' => $visit->workOrder?->customer?->name,
                'roundId' => $visit->round_id,
            ]);
    }

    public static function caseOptions(Corporation $corporation): Collection
    {
        return $corporation->workOrders()
            ->with(['customer', 'rounds'])
            ->orderBy('address')
            ->get()
            ->map(fn ($wo) => [
                'id' => $wo->id,
                'address' => $wo->address,
                'customerName' => $wo->customer->name,
                'rounds' => $wo->rounds
                    ->filter(fn ($round) => ! $round->isClosed())
                    ->values()
                    ->map(fn ($round) => [
                        'id' => $round->id,
                        'label' => self::roundLabel($round),
                    ]),
            ]);
    }

    public static function roundLabel(Round $round): string
    {
        return ucfirst($round->type->value).' round · closes '.$round->close_off_on->format('j M Y');
    }

    public static function staffOptions(Corporation $corporation): Collection
    {
        return $corporation->staff()
            ->orderBy('name')
            ->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);
    }
}
