<?php

namespace App\Http\Controllers;

use App\Enums\RoundStatus;
use App\Enums\VisitType;
use App\Support\EventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoundController extends Controller
{
    public function index(Request $request): Response
    {
        $corporation = $request->user()->corporation;

        $rounds = $corporation->rounds()
            ->with('project')
            ->withCount('workOrders')
            ->latest()
            ->get();

        return Inertia::render('rounds/index', [
            'rounds' => $rounds->map(fn ($round) => [
                'id' => $round->id,
                'type' => $round->type->value,
                'status' => $round->status->value,
                'windowStartsOn' => $round->window_starts_on?->toDateString(),
                'windowEndsOn' => $round->window_ends_on?->toDateString(),
                'closeOffOn' => $round->close_off_on->toDateString(),
                'scheduledOpenAt' => $round->scheduled_open_at?->toIso8601String(),
                'caseCount' => $round->work_orders_count,
                'projectName' => $round->project?->name,
            ]),
            'projects' => $corporation->projects()->orderBy('name')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
            ]),
        ]);
    }

    public function show(Request $request, int $round): Response
    {
        $corporation = $request->user()->corporation;

        $round = $corporation->rounds()
            ->with(['project', 'workOrders.customer'])
            ->findOrFail($round);

        $memberIds = $round->workOrders->pluck('id');

        $bookedByCase = $round->visits()
            ->whereNull('cancelled_at')
            ->get()
            ->keyBy('work_order_id');

        return Inertia::render('rounds/show', [
            'round' => [
                'id' => $round->id,
                'type' => $round->type->value,
                'status' => $round->status->value,
                'appointmentMinutes' => $round->appointment_minutes,
                'bufferMinutes' => $round->buffer_minutes,
                'windowStartsOn' => $round->window_starts_on?->toDateString(),
                'windowEndsOn' => $round->window_ends_on?->toDateString(),
                'dayStartsAt' => substr($round->day_starts_at, 0, 5),
                'dayEndsAt' => substr($round->day_ends_at, 0, 5),
                'closeOffOn' => $round->close_off_on->toDateString(),
                'scheduledOpenAt' => $round->scheduled_open_at?->toIso8601String(),
                'projectId' => $round->project?->id,
                'projectName' => $round->project?->name,
            ],
            'cases' => $round->workOrders->map(fn ($workOrder) => [
                'id' => $workOrder->id,
                'title' => $workOrder->title,
                'address' => $workOrder->address,
                'customerName' => $workOrder->customer->name,
                'customerEmail' => $workOrder->customer->email,
                'bookingUrl' => $workOrder->pivot->token !== null
                    ? route('booking.show', $workOrder->pivot->token)
                    : null,
                'bookedAt' => $bookedByCase->get($workOrder->id)?->starts_at->toIso8601String(),
                'inviteSentAt' => $workOrder->pivot->invite_sent_at,
                'inviteFailedAt' => $workOrder->pivot->invite_failed_at,
            ]),
            'addableCases' => $corporation->workOrders()
                ->with('customer')
                ->whereNotIn('id', $memberIds)
                ->orderBy('address')
                ->get()
                ->map(fn ($workOrder) => [
                    'id' => $workOrder->id,
                    'title' => $workOrder->title,
                    'address' => $workOrder->address,
                    'customerName' => $workOrder->customer->name,
                    'projectId' => $workOrder->project_id,
                ]),
            'projects' => $corporation->projects()->orderBy('name')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $corporation = $request->user()->corporation;

        $validated = $this->validateRound($request);

        $project = filled($validated['project_id'] ?? null)
            ? $corporation->projects()->findOrFail($validated['project_id'])
            : null;

        // A round needs assignable staff to exist (S3). Every corporation is
        // guaranteed at least one staff member (S2) and availability windows
        // are deferred (staff always free), so the check is trivially true.
        $round = $corporation->rounds()->create([
            'project_id' => $project?->id,
            'type' => $validated['type'],
            'appointment_minutes' => $validated['appointment_minutes'],
            'buffer_minutes' => $validated['buffer_minutes'] ?? 0,
            'window_starts_on' => $validated['window_starts_on'] ?? null,
            'window_ends_on' => $validated['window_ends_on'] ?? null,
            'day_starts_at' => $validated['day_starts_at'] ?? '08:00',
            'day_ends_at' => $validated['day_ends_at'] ?? '16:00',
            'close_off_on' => $validated['close_off_on'],
            'scheduled_open_at' => $validated['scheduled_open_at'] ?? null,
        ]);

        EventRecorder::record($corporation, 'round.created', subject: $round);

        return to_route('rounds.show', $round->id);
    }

    public function update(Request $request, int $round): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $round = $corporation->rounds()->findOrFail($round);

        if (! $round->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Only a draft round can be edited.',
            ]);
        }

        $validated = $this->validateRound($request);

        $project = filled($validated['project_id'] ?? null)
            ? $corporation->projects()->findOrFail($validated['project_id'])
            : null;

        $round->update([
            'project_id' => $project?->id,
            'type' => $validated['type'],
            'appointment_minutes' => $validated['appointment_minutes'],
            'buffer_minutes' => $validated['buffer_minutes'] ?? 0,
            'window_starts_on' => $validated['window_starts_on'] ?? null,
            'window_ends_on' => $validated['window_ends_on'] ?? null,
            'day_starts_at' => $validated['day_starts_at'] ?? '08:00',
            'day_ends_at' => $validated['day_ends_at'] ?? '16:00',
            'close_off_on' => $validated['close_off_on'],
            'scheduled_open_at' => $validated['scheduled_open_at'] ?? null,
        ]);

        EventRecorder::record($corporation, 'round.updated', subject: $round);

        return back();
    }

    public function open(Request $request, int $round): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $round = $corporation->rounds()->findOrFail($round);

        if (! $round->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Only a draft round can be opened.',
            ]);
        }

        if ($round->workOrders()->count() === 0) {
            throw ValidationException::withMessages([
                'status' => 'Add at least one case before opening the round.',
            ]);
        }

        // Slots need a bounded set of days: no window, nothing to offer.
        if ($round->window_starts_on === null) {
            throw ValidationException::withMessages([
                'status' => 'Set a booking window before opening the round.',
            ]);
        }

        // A manual start overrides any scheduled one.
        $round->update(['status' => RoundStatus::Open, 'scheduled_open_at' => null]);

        EventRecorder::record($corporation, 'round.opened', subject: $round, payload: [
            'trigger' => 'manual',
        ]);

        return back();
    }

    public function close(Request $request, int $round): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $round = $corporation->rounds()->findOrFail($round);

        if (! $round->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'Only an open round can be closed.',
            ]);
        }

        $round->update(['status' => RoundStatus::Closed]);

        EventRecorder::record($corporation, 'round.closed', subject: $round);

        return back();
    }

    public function destroy(Request $request, int $round): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $round = $corporation->rounds()->findOrFail($round);

        if (! $round->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Only a draft round can be deleted.',
            ]);
        }

        EventRecorder::record($corporation, 'round.deleted', subject: $round);

        $round->delete();

        return to_route('rounds.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRound(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::enum(VisitType::class)],
            'appointment_minutes' => ['required', 'integer', 'min:1'],
            'buffer_minutes' => ['nullable', 'integer', 'min:0'],
            'window_starts_on' => ['nullable', 'date', 'required_with:window_ends_on'],
            'window_ends_on' => ['nullable', 'date', 'required_with:window_starts_on', 'after_or_equal:window_starts_on'],
            'day_starts_at' => ['nullable', 'date_format:H:i', 'required_with:day_ends_at'],
            'day_ends_at' => ['nullable', 'date_format:H:i', 'required_with:day_starts_at', 'after:day_starts_at'],
            'close_off_on' => ['required', 'date'],
            'scheduled_open_at' => ['nullable', 'date', 'after:now'],
            'project_id' => ['nullable', 'integer'],
        ]);
    }
}
