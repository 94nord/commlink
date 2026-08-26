<?php

namespace App\Http\Controllers;

use App\Enums\AddressType;
use App\Http\Requests\StoreCustomerPhoneRequest;
use App\Inbox\Actions\AddCustomerAddress;
use App\Inbox\Actions\RemoveCustomerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The numbers a customer can be reached on.
 *
 * They are identities — the very rows inbound messages are matched against —
 * so a number written down here is immediately one the inbox can answer on,
 * and a number the customer writes from immediately appears here.
 *
 * Not admin-gated: everyone in a corporation is an admin (D2), and any staff
 * member can already take an address off a person from the inbox side.
 */
class CustomerPhoneController extends Controller
{
    public function store(StoreCustomerPhoneRequest $request, int $customer, AddCustomerAddress $add): JsonResponse
    {
        $customer = $request->user()->corporation->customers()->findOrFail($customer);

        $identity = $add->handle($customer, AddressType::Phone, $request->validated('phone'));

        return response()->json([
            'phone' => ['id' => $identity->id, 'value' => $identity->value],
        ], 201);
    }

    public function destroy(Request $request, int $customer, int $phone, RemoveCustomerAddress $remove): Response
    {
        $corporation = $request->user()->corporation;
        $customer = $corporation->customers()->findOrFail($customer);

        // Scoped through the customer: you cannot take somebody else's
        // number off this record.
        $identity = $customer->identities()->findOrFail($phone);

        $remove->handle($identity);

        return response()->noContent();
    }
}
