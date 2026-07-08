<?php

namespace App\Http\Controllers;

use App\Actions\AddCustomerToProject;
use App\Http\Requests\StoreWorkOrderRequest;
use Illuminate\Http\RedirectResponse;

class ProjectWorkOrderController extends Controller
{
    public function store(StoreWorkOrderRequest $request, int $project, AddCustomerToProject $action): RedirectResponse
    {
        $corporation = $request->user()->corporation;

        $project = $corporation->projects()->findOrFail($project);
        $customer = $corporation->customers()->findOrFail($request->validated('customer_id'));

        $action->handle($project, $customer, $request->validated('address'));

        return back();
    }
}
