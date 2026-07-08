<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Support\EventRecorder;
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
                'address' => $workOrder->address,
                'customerName' => $workOrder->customer->name,
                'customerEmail' => $workOrder->customer->email,
                'projectId' => $workOrder->project?->id,
                'projectName' => $workOrder->project?->name,
            ]),
            'customers' => $corporation->customers()->orderBy('name')->get()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
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

        $validated = $request->validate([
            'customer_id' => ['required', 'integer'],
            'address' => ['required', 'string', 'max:255'],
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
            'address' => $validated['address'],
        ]);

        EventRecorder::record($corporation, 'work_order.created', subject: $workOrder);

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
