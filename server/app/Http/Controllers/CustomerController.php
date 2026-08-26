<?php

namespace App\Http\Controllers;

use App\Enums\AddressType;
use App\Enums\CustomerKind;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Inbox\Actions\AddCustomerAddress;
use App\Inbox\Actions\RemoveCustomerAddress;
use App\Models\Customer;
use App\Support\EventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Only people somebody has named. The rest are numbers the inbox
        // knows about and the address book has nothing to say about.
        $customers = $request->user()->corporation->customers()
            ->named()
            ->with(['identities' => $this->withHistoryFlag()])
            ->orderBy('name')
            ->get();

        return response()->json([
            'customers' => $customers->map(fn ($customer) => $this->present($customer)),
        ]);
    }

    public function show(Request $request, int $customer): JsonResponse
    {
        $customer = $request->user()->corporation->customers()
            ->with(['identities' => $this->withHistoryFlag()])
            ->findOrFail($customer);

        return response()->json(['customer' => $this->present($customer)]);
    }

    public function store(StoreCustomerRequest $request, AddCustomerAddress $add): JsonResponse
    {
        $corporation = $request->user()->corporation;

        // One transaction over the person *and* their addresses: an address
        // already held by somebody else refuses the whole thing, and a
        // half-made customer with no way to reach them must not be what is
        // left behind.
        $customer = DB::transaction(function () use ($corporation, $request, $add) {
            $customer = $corporation->customers()->create([
                'kind' => CustomerKind::Person,
                'name' => $request->validated('name'),
                'address' => $request->validated('address'),
            ]);

            EventRecorder::record($corporation, 'customer.created', subject: $customer);

            // A number or a mailbox given at creation is an address like any
            // other, so both become identities rather than columns.
            if (filled($request->validated('phone'))) {
                $add->handle($customer, AddressType::Phone, $request->validated('phone'));
            }

            if (filled($request->validated('email'))) {
                $add->handle($customer, AddressType::Email, $request->validated('email'), errorKey: 'email');
            }

            return $customer;
        });

        return response()->json([
            'customer' => $this->present($customer->fresh(['identities' => $this->withHistoryFlag()])),
        ], 201);
    }

    public function update(
        UpdateCustomerRequest $request,
        int $customer,
        AddCustomerAddress $add,
        RemoveCustomerAddress $remove,
    ): JsonResponse {
        $corporation = $request->user()->corporation;
        $customer = $corporation->customers()->findOrFail($customer);

        // Changing the mailbox is a remove and an add, and the add can be
        // refused — without a transaction a rejected change would take the
        // address the customer already had with it.
        DB::transaction(function () use ($customer, $corporation, $request, $add, $remove) {
            $customer->update([
                'name' => $request->validated('name'),
                'address' => $request->validated('address'),
            ]);

            $this->syncEmail($customer, $request->validated('email'), $add, $remove);

            EventRecorder::record($corporation, 'customer.updated', subject: $customer);
        });

        return response()->json([
            'customer' => $this->present($customer->fresh(['identities' => $this->withHistoryFlag()])),
        ]);
    }

    /**
     * GDPR erasure — anonymize, keep history. The customer's PII (name and
     * address here, every contact address below) is scrubbed and the row
     * soft-deleted. Their email needs no scrubbing of its own any more: it
     * is an identity like their number, and soft-deleting the identities
     * releases both for reuse (the partial unique index covers live rows
     * only).
     *
     * The person's addresses go with them. What is *not* touched is the
     * message log itself: the bodies, and the from/to pair kept in each
     * message's channel_data, are a record of what the firm actually said
     * and are a separate question — see docs/inbox-plan.md.
     */
    public function destroy(Request $request, int $customer): Response
    {
        $corporation = $request->user()->corporation;
        $customer = $corporation->customers()->findOrFail($customer);

        $customer->update([
            'name' => 'Erased customer',
            'address' => null,
        ]);

        // Their addresses are personal data too, and leaving them behind
        // would strand them on a deleted person — the next text from that
        // number would have nobody to belong to. Releasing them means it
        // arrives as a stranger, which is what it now is.
        $customer->identities()->delete();
        $customer->conversation?->delete();

        $customer->delete();

        EventRecorder::record($corporation, 'customer.erased', subject: $customer);

        return response()->noContent();
    }

    /**
     * Make the customer's mailbox match what the form says.
     *
     * The email field on this form edits one address out of several, so it
     * has to say what *removing* it means as well as adding it — and that is
     * `RemoveCustomerAddress`'s judgement, not this controller's: a mailbox
     * that carried messages is unlinked rather than deleted, because those
     * messages happened.
     */
    private function syncEmail(
        Customer $customer,
        ?string $email,
        AddCustomerAddress $add,
        RemoveCustomerAddress $remove,
    ): void {
        $current = $customer->identities()
            ->where('address_type', AddressType::Email)
            ->first();

        if (blank($email)) {
            if ($current !== null) {
                $remove->handle($current);
            }

            return;
        }

        // Unchanged is not a no-op worth a write, and re-adding would look
        // like the address moved.
        if ($current !== null && $current->value === AddressType::Email->normalize($email)) {
            return;
        }

        if ($current !== null) {
            $remove->handle($current);
        }

        $add->handle($customer, AddressType::Email, $email, errorKey: 'email');
    }

    /**
     * Flags addresses that have carried messages, so the UI can warn that
     * removing one moves history rather than deleting it.
     */
    private function withHistoryFlag(): callable
    {
        return fn ($query) => $query->withExists([
            'threads as has_messages' => fn ($threads) => $threads->whereHas('messages'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'address' => $customer->address,
            // Numbers only. Every kind of address is an identity now, so
            // this has to say which kind it wants or the customer's mailbox
            // would turn up in the list of phone numbers.
            'phones' => $customer->identities
                ->where('address_type', AddressType::Phone)
                ->map(fn ($identity) => [
                    'id' => $identity->id,
                    'value' => $identity->value,
                    // Removing an address that carried messages does not
                    // delete them — it moves the address off this person.
                    'hasMessages' => (bool) $identity->has_messages,
                ])
                ->values(),
        ];
    }
}
