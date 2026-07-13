<?php

namespace App\Http\Controllers;

use App\Support\EventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoundWorkOrderController extends Controller
{
    public function store(Request $request, int $round): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $round = $corporation->rounds()->findOrFail($round);

        // Late-adds are allowed while a round is open (SO5); closed is final.
        if ($round->isClosed()) {
            throw ValidationException::withMessages([
                'work_order_ids' => 'Cases cannot be added to a closed round.',
            ]);
        }

        $validated = $request->validate([
            'work_order_ids' => ['required', 'array', 'min:1'],
            'work_order_ids.*' => ['integer'],
        ]);

        $workOrders = $corporation->workOrders()
            ->whereIn('id', $validated['work_order_ids'])
            ->get();

        abort_if($workOrders->count() !== count(array_unique($validated['work_order_ids'])), 404);

        // Skip cases already in the round (SO7 — populate is point-in-time
        // and re-running it picks up new matches, skips existing). Existing
        // rows are never touched so their booking tokens stay valid.
        $existingIds = $round->workOrders()->pluck('work_orders.id');
        $newIds = $workOrders->pluck('id')->diff($existingIds)->values();

        // Every membership row gets its magic-link token at attach time —
        // one code path for draft adds and open-round late-adds alike. The
        // link only resolves once the round is open.
        $round->workOrders()->attach(
            $newIds->mapWithKeys(fn ($id) => [$id => ['token' => Str::random(64)]])->all()
        );

        if ($newIds->isNotEmpty()) {
            EventRecorder::record($corporation, 'round.cases_added', subject: $round, payload: [
                'work_order_ids' => $newIds->all(),
            ]);
        }

        return back();
    }

    /**
     * Individual sendout: send (or resend, SO2) one recipient's invite.
     * This is how late-adds get their link (SO5 — never auto-emailed);
     * after close-off no sendout goes out at all.
     */
    public function sendInvite(Request $request, int $round, int $workOrder): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $round = $corporation->rounds()->findOrFail($round);

        if (! $round->isBookable()) {
            throw ValidationException::withMessages([
                'invite' => 'Invites can only be sent while the round is open for booking.',
            ]);
        }

        $workOrder = $round->workOrders()->findOrFail($workOrder);

        EventRecorder::record($corporation, 'round.invite_requested', subject: $round, payload: [
            'round_work_order_id' => $workOrder->pivot->id,
            'work_order_id' => $workOrder->id,
        ]);

        return back();
    }

    public function destroy(Request $request, int $round, int $workOrder): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $round = $corporation->rounds()->findOrFail($round);

        if (! $round->isDraft()) {
            throw ValidationException::withMessages([
                'work_order_ids' => 'Cases can only be removed from a draft round.',
            ]);
        }

        $workOrder = $round->workOrders()->findOrFail($workOrder);

        $round->workOrders()->detach($workOrder->id);

        EventRecorder::record($corporation, 'round.case_removed', subject: $round, payload: [
            'work_order_id' => $workOrder->id,
        ]);

        return back();
    }
}
