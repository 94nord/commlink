<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Support\EventRecorder;
use App\Support\SchedulePayload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $corporation = $request->user()->corporation;

        $workOrders = $corporation->workOrders()
            ->with(['customer', 'project'])
            ->latest()
            ->get();

        return Inertia::render('cases/index', [
            'cases' => $workOrders->map(fn ($workOrder) => [
                'id' => $workOrder->id,
                'title' => $workOrder->title,
                'address' => $workOrder->address,
                'customerId' => $workOrder->customer->id,
                'customerName' => $workOrder->customer->name,
                'customerEmail' => $workOrder->customer->email,
                'projectId' => $workOrder->project?->id,
                'projectName' => $workOrder->project?->name,
            ]),
            'customers' => $corporation->customers()->orderBy('name')->get()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'address' => $c->address,
            ]),
            'projects' => $corporation->projects()->orderBy('name')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
            ]),
        ]);
    }

    public function show(Request $request, int $workOrder): Response
    {
        $corporation = $request->user()->corporation;

        $workOrder = $corporation->workOrders()
            ->with(['customer', 'project', 'rounds', 'visits' => fn ($query) => $query->with('staff')->latest()])
            ->findOrFail($workOrder);

        return Inertia::render('cases/show', [
            'workOrder' => [
                'id' => $workOrder->id,
                'title' => $workOrder->title,
                'address' => $workOrder->address,
                'notes' => $workOrder->notes,
                'customerId' => $workOrder->customer->id,
                'customerName' => $workOrder->customer->name,
                'customerEmail' => $workOrder->customer->email,
                'projectId' => $workOrder->project?->id,
                'projectName' => $workOrder->project?->name,
            ],
            'projectOptions' => $corporation->projects()->orderBy('name')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
            ]),
            'visits' => $workOrder->visits->map(fn ($visit) => [
                'id' => $visit->id,
                'type' => $visit->type->value,
                'staffId' => $visit->staff_id,
                'staffName' => $visit->staff?->name,
                'startsAt' => $visit->starts_at?->toIso8601String(),
                'endsAt' => $visit->ends_at?->toIso8601String(),
                'cancelledAt' => $visit->cancelled_at?->toIso8601String(),
                'caseId' => $visit->work_order_id,
                'caseAddress' => $workOrder->address,
                'customerName' => $workOrder->customer->name,
                'roundId' => $visit->round_id,
            ]),
            'staffOptions' => $corporation->staff()->orderBy('name')->get()->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
            ]),
            'roundOptions' => $workOrder->rounds
                ->filter(fn ($round) => ! $round->isClosed())
                ->values()
                ->map(fn ($round) => [
                    'id' => $round->id,
                    'label' => SchedulePayload::roundLabel($round),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $corporation = $request->user()->corporation;

        $validated = $request->validate([
            'customer_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'project_id' => ['nullable', 'integer'],
        ]);

        $customer = $corporation->customers()->findOrFail($validated['customer_id']);

        $project = filled($validated['project_id'] ?? null)
            ? $corporation->projects()->findOrFail($validated['project_id'])
            : null;

        $workOrder = WorkOrder::create([
            'corporation_id' => $corporation->id,
            'customer_id' => $customer->id,
            'project_id' => $project?->id,
            'title' => $validated['title'] ?? null,
            'address' => $validated['address'],
            'notes' => $validated['notes'] ?? null,
        ]);

        EventRecorder::record($corporation, 'work_order.created', subject: $workOrder);

        return back();
    }

    public function update(Request $request, int $workOrder): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $workOrder = $corporation->workOrders()->findOrFail($workOrder);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'project_id' => ['nullable', 'integer'],
        ]);

        $project = filled($validated['project_id'] ?? null)
            ? $corporation->projects()->findOrFail($validated['project_id'])
            : null;

        $workOrder->update([
            'title' => $validated['title'] ?? null,
            'address' => $validated['address'],
            'notes' => $validated['notes'] ?? null,
            'project_id' => $project?->id,
        ]);

        EventRecorder::record($corporation, 'work_order.updated', subject: $workOrder);

        return back();
    }

    public function destroy(Request $request, int $workOrder): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $workOrder = $corporation->workOrders()->findOrFail($workOrder);

        EventRecorder::record($corporation, 'work_order.deleted', subject: $workOrder);

        $workOrder->delete();

        return back();
    }
}
