<?php

namespace App\Http\Controllers;

use App\Actions\BookVisit;
use App\Models\RoundWorkOrder;
use App\Support\RoundSlots;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer-facing booking surface, reached only through a magic link
 * (A1): no account, no login — the token *is* the identity, resolving to
 * one case in one round (A2/A3).
 */
class BookingController extends Controller
{
    public function show(string $token): Response
    {
        $membership = $this->resolve($token);
        $round = $membership->round;
        $workOrder = $membership->workOrder;

        $booking = $round->visits()
            ->where('work_order_id', $workOrder->id)
            ->whereNull('cancelled_at')
            ->first();

        $days = $round->isBookable() ? RoundSlots::compute($round) : [];

        return Inertia::render('booking/show', [
            'token' => $token,
            'corporationName' => $round->corporation->name,
            'address' => $workOrder->address,
            'customerName' => $workOrder->customer->name,
            'type' => $round->type->value,
            'appointmentMinutes' => $round->appointment_minutes,
            'closeOffOn' => $round->close_off_on->toDateString(),
            'bookable' => $round->isBookable(),
            'days' => collect($days)->map(fn ($day) => [
                'date' => $day['date'],
                'slots' => collect($day['slots'])->map(fn ($slot) => [
                    'startsAt' => $slot['startsAt']->toIso8601String(),
                    'endsAt' => $slot['endsAt']->toIso8601String(),
                ]),
            ]),
            'booking' => $booking === null ? null : [
                'startsAt' => $booking->starts_at->toIso8601String(),
                'endsAt' => $booking->ends_at->toIso8601String(),
            ],
        ]);
    }

    public function store(Request $request, string $token, BookVisit $booker): RedirectResponse
    {
        $membership = $this->resolve($token);

        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
        ]);

        $booker->handle($membership, CarbonImmutable::parse($validated['starts_at']));

        return back();
    }

    private function resolve(string $token): RoundWorkOrder
    {
        $membership = RoundWorkOrder::query()
            ->where('token', $token)
            ->first();

        // No membership, a deleted round/case, or a still-draft round all
        // read as "this link does not exist" — never leak why.
        abort_if(
            $membership === null
            || $membership->round === null
            || $membership->workOrder === null
            || $membership->round->isDraft(),
            404,
        );

        return $membership;
    }
}
