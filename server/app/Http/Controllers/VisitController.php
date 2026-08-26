<?php

namespace App\Http\Controllers;

use App\Actions\ScheduleVisit;
use App\Enums\VisitType;
use App\Http\Requests\StoreVisitRequest;
use App\Http\Requests\UpdateVisitRequest;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\Round;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Support\EventRecorder;
use App\Support\SchedulePayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VisitController extends Controller
{
    public function index(Request $request): Response
    {
        $corporation = $request->user()->corporation;

        return Inertia::render('visits/index', [
            'visits' => SchedulePayload::visits($corporation),
            'cases' => SchedulePayload::caseOptions($corporation),
            'staffOptions' => SchedulePayload::staffOptions($corporation),
        ]);
    }

    public function show(Request $request, int $visit): Response
    {
        $corporation = $request->user()->corporation;

        // Own query, not SchedulePayload::visits() — that one hides
        // cancelled visits, and this page must render them as history.
        $visit = $corporation->visits()
            ->with(['staff', 'workOrder.customer', 'workOrder.rounds', 'round'])
            ->findOrFail($visit);

        $workOrder = $visit->workOrder;

        return Inertia::render('visits/show', [
            'visit' => [
                'id' => $visit->id,
                'type' => $visit->type->value,
                'staffId' => $visit->staff_id,
                'staffName' => $visit->staff?->name,
                'startsAt' => $visit->starts_at?->toIso8601String(),
                'endsAt' => $visit->ends_at?->toIso8601String(),
                'cancelledAt' => $visit->cancelled_at?->toIso8601String(),
                'caseId' => $visit->work_order_id,
                'caseTitle' => $workOrder?->title,
                'caseAddress' => $workOrder?->address,
                'customerId' => $workOrder?->customer?->id,
                'customerName' => $workOrder?->customer?->name,
                'roundId' => $visit->round_id,
                'roundLabel' => $visit->round ? SchedulePayload::roundLabel($visit->round) : null,
            ],
            'cases' => $workOrder === null ? [] : [[
                'id' => $workOrder->id,
                'address' => $workOrder->address,
                'customerName' => $workOrder->customer->name,
                'rounds' => $workOrder->rounds
                    ->filter(fn ($round) => ! $round->isClosed())
                    ->values()
                    ->map(fn ($round) => [
                        'id' => $round->id,
                        'label' => SchedulePayload::roundLabel($round),
                    ]),
            ]],
            'staffOptions' => SchedulePayload::staffOptions($corporation),
        ]);
    }

    public function storeFromIndex(Request $request, ScheduleVisit $scheduler): RedirectResponse
    {
        $corporation = $request->user()->corporation;

        $validated = $request->validate([
            'work_order_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(VisitType::class)],
            'staff_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'round_id' => ['nullable', 'integer'],
        ]);

        $workOrder = $corporation->workOrders()->findOrFail($validated['work_order_id']);
        $staff = $corporation->staff()->findOrFail($validated['staff_id']);
        $round = $this->resolveRound($corporation, $workOrder, $validated['round_id'] ?? null);

        DB::transaction(function () use ($validated, $corporation, $workOrder, $staff, $round, $scheduler) {
            $visit = $corporation->visits()->create([
                'owner_type' => Customer::class,
                'owner_id' => $workOrder->customer_id,
                'work_order_id' => $workOrder->id,
                'round_id' => $round?->id,
                'type' => $validated['type'],
            ]);

            EventRecorder::record($corporation, 'visit.created', subject: $visit);

            $scheduler->handle(
                $visit,
                $staff,
                CarbonImmutable::parse($validated['starts_at']),
                CarbonImmutable::parse($validated['ends_at']),
            );
        });

        return back();
    }

    public function store(StoreVisitRequest $request, int $workOrder, ScheduleVisit $scheduler): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $workOrder = $corporation->workOrders()->findOrFail($workOrder);
        $staff = $corporation->staff()->findOrFail($request->validated('staff_id'));
        $round = $this->resolveRound($corporation, $workOrder, $request->validated('round_id'));

        DB::transaction(function () use ($request, $corporation, $workOrder, $staff, $round, $scheduler) {
            $visit = $corporation->visits()->create([
                'owner_type' => Customer::class,
                'owner_id' => $workOrder->customer_id,
                'work_order_id' => $workOrder->id,
                'round_id' => $round?->id,
                'type' => $request->validated('type'),
            ]);

            EventRecorder::record($corporation, 'visit.created', subject: $visit);

            $scheduler->handle(
                $visit,
                $staff,
                CarbonImmutable::parse($request->validated('starts_at')),
                CarbonImmutable::parse($request->validated('ends_at')),
            );
        });

        return back();
    }

    public function update(UpdateVisitRequest $request, int $visit, ScheduleVisit $scheduler): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $visit = $corporation->visits()->findOrFail($visit);

        if ($visit->isCancelled()) {
            throw ValidationException::withMessages([
                'starts_at' => 'A cancelled visit cannot be rescheduled.',
            ]);
        }

        $staff = $corporation->staff()->findOrFail($request->validated('staff_id'));

        $round = $visit->workOrder === null
            ? null
            : $this->resolveRound($corporation, $visit->workOrder, $request->validated('round_id'), ignore: $visit);

        $visit->round_id = $round?->id;

        $scheduler->handle(
            $visit,
            $staff,
            CarbonImmutable::parse($request->validated('starts_at')),
            CarbonImmutable::parse($request->validated('ends_at')),
        );

        return back();
    }

    /**
     * Resolve and guard an optional round link for a visit: the round must
     * be this corporation's, still takable (not closed), already contain
     * the case, and not already hold an active visit for it — a membership
     * link manages exactly one appointment.
     */
    private function resolveRound(Corporation $corporation, WorkOrder $workOrder, ?int $roundId, ?Visit $ignore = null): ?Round
    {
        if ($roundId === null) {
            return null;
        }

        $round = $corporation->rounds()->findOrFail($roundId);

        if ($round->isClosed()) {
            throw ValidationException::withMessages([
                'round_id' => 'A closed round cannot take visits.',
            ]);
        }

        if (! $round->workOrders()->whereKey($workOrder->id)->exists()) {
            throw ValidationException::withMessages([
                'round_id' => 'The case is not part of that round — add it to the round first.',
            ]);
        }

        $taken = $round->visits()
            ->where('work_order_id', $workOrder->id)
            ->whereNull('cancelled_at')
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'round_id' => 'This round already has a visit for this case.',
            ]);
        }

        return $round;
    }

    public function destroy(Request $request, int $visit): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $visit = $corporation->visits()->findOrFail($visit);

        EventRecorder::record($corporation, 'visit.deleted', subject: $visit);

        $visit->delete();

        return back();
    }

    public function cancel(Request $request, int $visit): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $visit = $corporation->visits()->findOrFail($visit);

        if ($visit->isCancelled()) {
            throw ValidationException::withMessages([
                'visit' => 'This visit is already cancelled.',
            ]);
        }

        $visit->update(['cancelled_at' => now()]);

        EventRecorder::record($corporation, 'visit.cancelled', subject: $visit);

        return back();
    }
}
