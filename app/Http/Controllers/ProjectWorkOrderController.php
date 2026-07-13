<?php

namespace App\Http\Controllers;

use App\Support\EventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProjectWorkOrderController extends Controller
{
    /**
     * Attach existing standalone cases to the project (cases are created on
     * the Cases page). Only cases not already in a project are eligible, so
     * this never silently moves a case out of another project.
     */
    public function store(Request $request, int $project): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $project = $corporation->projects()->findOrFail($project);

        $validated = $request->validate([
            'work_order_ids' => ['required', 'array', 'min:1'],
            'work_order_ids.*' => ['integer'],
        ]);

        $workOrders = $corporation->workOrders()
            ->whereNull('project_id')
            ->whereIn('id', $validated['work_order_ids'])
            ->get();

        abort_if($workOrders->count() !== count(array_unique($validated['work_order_ids'])), 404);

        $corporation->workOrders()
            ->whereIn('id', $workOrders->pluck('id'))
            ->update(['project_id' => $project->id]);

        EventRecorder::record($corporation, 'project.cases_added', subject: $project, payload: [
            'work_order_ids' => $workOrders->pluck('id')->all(),
        ]);

        return back();
    }

    /**
     * Detach a case from the project (the case itself is untouched).
     */
    public function destroy(Request $request, int $project, int $workOrder): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $project = $corporation->projects()->findOrFail($project);
        $workOrder = $project->workOrders()->findOrFail($workOrder);

        $workOrder->update(['project_id' => null]);

        EventRecorder::record($corporation, 'project.case_removed', subject: $project, payload: [
            'work_order_id' => $workOrder->id,
        ]);

        return back();
    }
}
