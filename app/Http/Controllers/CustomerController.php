<?php

namespace App\Http\Controllers;

use App\Enums\CustomerKind;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Support\EventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $customers = $request->user()->corporation->customers()
            ->orderBy('name')
            ->get();

        return Inertia::render('customers/index', [
            'customers' => $customers->map(fn ($customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'address' => $customer->address,
            ]),
        ]);
    }

    public function show(Request $request, int $customer): Response
    {
        $corporation = $request->user()->corporation;

        $customer = $corporation->customers()
            ->with(['workOrders' => fn ($query) => $query->with('project')->latest()])
            ->findOrFail($customer);

        return Inertia::render('customers/show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'address' => $customer->address,
            ],
            'cases' => $customer->workOrders->map(fn ($workOrder) => [
                'id' => $workOrder->id,
                'title' => $workOrder->title,
                'address' => $workOrder->address,
                'projectId' => $workOrder->project?->id,
                'projectName' => $workOrder->project?->name,
            ]),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $corporation = $request->user()->corporation;

        $customer = $corporation->customers()->create([
            'kind' => CustomerKind::Person,
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'address' => $request->validated('address'),
        ]);

        EventRecorder::record($corporation, 'customer.created', subject: $customer);

        return to_route('customers.index');
    }

    public function update(UpdateCustomerRequest $request, int $customer): RedirectResponse
    {
        $corporation = $request->user()->corporation;
        $customer = $corporation->customers()->findOrFail($customer);

        $customer->update([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'address' => $request->validated('address'),
        ]);

        EventRecorder::record($corporation, 'customer.updated', subject: $customer);

        return back();
    }
}
