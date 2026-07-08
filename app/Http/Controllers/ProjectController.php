<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Support\EventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = $request->user()->corporation->projects()
            ->withCount('workOrders')
            ->latest()
            ->get();

        return Inertia::render('projects/index', [
            'projects' => $projects->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'workOrderCount' => $project->work_orders_count,
            ]),
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $corporation = $request->user()->corporation;

        $project = $corporation->projects()->create($request->validated());

        EventRecorder::record($corporation, 'project.created', subject: $project);

        return to_route('projects.show', $project);
    }

    public function update(UpdateProjectRequest $request, int $project): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $project = $corporation->projects()->findOrFail($project);

        $project->update($request->validated());

        EventRecorder::record($corporation, 'project.updated', subject: $project);

        return back();
    }

    public function destroy(Request $request, int $project): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $project = $corporation->projects()->findOrFail($project);

        // Soft-deletes don't fire FK nullOnDelete; detach work orders manually.
        $project->workOrders()->update(['project_id' => null]);

        EventRecorder::record($corporation, 'project.deleted', subject: $project);

        $project->delete();

        return to_route('projects.index');
    }

    public function show(Request $request, int $project): Response
    {
        $corporation = $request->user()->corporation;

        $project = $corporation->projects()
            ->with(['workOrders' => fn ($query) => $query->with('customer')->latest()])
            ->findOrFail($project);

        $customers = $corporation->customers()
            ->orderBy('name')
            ->get()
            ->map(fn ($customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
            ]);

        return Inertia::render('projects/show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'workOrders' => $project->workOrders->map(fn ($workOrder) => [
                'id' => $workOrder->id,
                'address' => $workOrder->address,
                'customerName' => $workOrder->customer->name,
                'customerEmail' => $workOrder->customer->email,
            ]),
            'customers' => $customers,
        ]);
    }
}
