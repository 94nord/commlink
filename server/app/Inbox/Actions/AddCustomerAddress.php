<?php

namespace App\Inbox\Actions;

use App\Enums\AddressType;
use App\Models\Customer;
use App\Models\Identity;
use App\Support\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Writes down a way to reach a customer. Nothing else happens: no
 * conversation, no inbox entry. A number somebody wrote down is not an
 * exchange.
 *
 * The interesting case is a number the firm has *already* been texting
 * without knowing whose it was. Typing it on a customer's record is the same
 * statement as naming them in the inbox — this is Hanne — so it does exactly
 * that, history and all, rather than refusing because the number is taken.
 */
class AddCustomerAddress
{
    public function __construct(private readonly MergeCustomers $merge) {}

    /**
     * Keyed by the *kind* of address, not by a channel: writing a mailbox on
     * somebody's record is a fact about the person, and it must work whether
     * or not the installation can currently send on that channel. That is
     * why this does not go through the channel registry.
     *
     * @param  string  $errorKey  which form field a refusal belongs to
     */
    public function handle(Customer $customer, AddressType $addressType, string $address, string $errorKey = 'phone'): Identity
    {
        try {
            $address = $addressType->normalize($address);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([$errorKey => $e->getMessage()]);
        }

        $existing = Identity::query()
            ->where('corporation_id', $customer->corporation_id)
            ->where('address_type', $addressType)
            ->where('value', $address)
            ->first();

        if ($existing !== null) {
            return $this->claim($existing, $customer, $errorKey);
        }

        return DB::transaction(function () use ($customer, $addressType, $address) {
            $identity = $customer->identities()->create([
                'corporation_id' => $customer->corporation_id,
                'address_type' => $addressType,
                'value' => $address,
            ]);

            EventRecorder::record(
                $customer->corporation,
                'customer.address_added',
                subject: $customer,
                // Deliberately the id and not the number: the event log is
                // append-only, so anything personal in it cannot be erased.
                payload: ['identity_id' => $identity->id],
            );

            return $identity;
        });
    }

    /**
     * The address is known already. Either it is this customer's, or it
     * belongs to somebody nobody has named — who turns out to be them.
     */
    private function claim(Identity $existing, Customer $customer, string $errorKey): Identity
    {
        if ($existing->customer->is($customer)) {
            return $existing;
        }

        if ($existing->customer->isNamed()) {
            throw ValidationException::withMessages([
                $errorKey => __('customers.address_taken', ['name' => $existing->customer->name]),
            ]);
        }

        $this->merge->handle($existing->customer, $customer);

        return $existing->fresh();
    }
}
