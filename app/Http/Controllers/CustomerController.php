<?php

namespace App\Http\Controllers;

use App\Enums\CustomerKind;
use App\Http\Requests\StoreCustomerRequest;
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
            ->withCount('workOrders')
            ->orderBy('name')
            ->get();

        return Inertia::render('customers/index', [
            'customers' => $customers->map(fn ($customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'workOrderCount' => $customer->work_orders_count,
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
        ]);

        EventRecorder::record($corporation, 'customer.created', subject: $customer);

        return to_route('customers.index');
    }
}
