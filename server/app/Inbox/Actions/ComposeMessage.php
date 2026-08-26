<?php

namespace App\Inbox\Actions;

use App\Enums\AddressType;
use App\Enums\ChannelStatus;
use App\Enums\CustomerKind;
use App\Inbox\ChannelAvailability;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\Identity;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Starts an exchange the firm initiated, from the compose box.
 *
 * The one rule it exists to enforce: writing to somebody the firm already
 * knows must reach that person, never a second copy of them. The UI tries to
 * arrange this by autocompleting known people, but the UI can be wrong or
 * out of date — a number typed by hand is matched here, on the normalized
 * address, and that is what actually guarantees it.
 *
 * Writing to an address nobody has seen produces a customer nobody has
 * named, exactly as an inbound message from a stranger would.
 */
class ComposeMessage
{
    public function __construct(
        private readonly MergeCustomers $merge,
        private readonly OpenConversation $open,
        private readonly SendOutboundMessage $send,
        private readonly ChannelAvailability $availability,
    ) {}

    /**
     * Keyed by the kind of address rather than a channel: which transport
     * carries the message is the address's own business (its
     * `defaultChannel()`), and the compose box only knows what was typed.
     */
    public function handle(
        Corporation $corporation,
        AddressType $addressType,
        string $body,
        ?string $address = null,
        ?Customer $customer = null,
        ?User $author = null,
    ): Message {
        if ($address === null && $customer === null) {
            throw ValidationException::withMessages([
                'address' => __('inbox.say_who_this_is_going_to'),
            ]);
        }

        // Checked here, and before anything is resolved: a channel the firm
        // cannot use should not have a customer and an address invented for
        // it on the way to being refused. This is broader than the module
        // alone — no address of its own to send from, or no adapter to send
        // with, are equally reasons the message cannot go.
        if ($this->availability->forCorporation($corporation, $addressType) !== ChannelStatus::Active) {
            throw ValidationException::withMessages([
                'address' => __('inbox.channel_not_available', ['channel' => $addressType->defaultChannel()->value]),
            ]);
        }

        // One transaction over resolution *and* the send: if the message
        // cannot go out, the person and address it would have invented must
        // not be left behind.
        return DB::transaction(function () use ($corporation, $addressType, $body, $address, $customer, $author): Message {
            $identity = $address === null
                ? $this->addressOf($customer, $addressType)
                : $this->resolveAddress($corporation, $addressType, $address, $customer);

            $thread = $this->defaultThread($identity);

            // There is an exchange now, so there is something to keep team
            // state about.
            $this->open->handle($identity->customer);

            return $this->send->handle($thread, $body, $author);
        });
    }

    /**
     * Writing to a known person without saying which address: only possible
     * if the firm holds one for them.
     */
    private function addressOf(Customer $customer, AddressType $addressType): Identity
    {
        $identity = $customer->identities->firstWhere('address_type', $addressType);

        if ($identity === null) {
            throw ValidationException::withMessages([
                'address' => __('inbox.no_number_for_customer'),
            ]);
        }

        return $identity;
    }

    /**
     * Writing to an address: reach whoever already owns it, or bring both
     * the address and its owner into being.
     */
    private function resolveAddress(
        Corporation $corporation,
        AddressType $addressType,
        string $address,
        ?Customer $customer,
    ): Identity {
        try {
            $address = $addressType->normalize($address);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['address' => $e->getMessage()]);
        }

        $identity = Identity::query()
            ->where('corporation_id', $corporation->id)
            ->where('address_type', $addressType)
            ->where('value', $address)
            ->first();

        if ($identity !== null) {
            // Known address. Saying whose it is now may fold the placeholder
            // it belongs to into the customer being written to.
            if ($customer !== null && ! $identity->customer->is($customer)) {
                $this->merge->handle($identity->customer, $customer);
                $identity->refresh();
            }

            return $identity;
        }

        $owner = $customer ?? $corporation->customers()->create([
            'kind' => CustomerKind::Person,
            'name' => null,
        ]);

        return $owner->identities()->create([
            'corporation_id' => $corporation->id,
            'address_type' => $addressType,
            'value' => $address,
        ]);
    }

    /**
     * SMS has exactly one thread per address. A channel that threads will
     * want a new key here instead, which is the adapter's business to say.
     */
    private function defaultThread(Identity $identity): Thread
    {
        return $identity->threads()->firstOrCreate(
            ['external_key' => null],
            [
                'corporation_id' => $identity->corporation_id,
                // Bound at creation and never resolved again — see the
                // migration that added the column.
                'corporation_address_id' => $identity->corporation
                    ->primaryAddress($identity->address_type)?->id,
            ],
        );
    }
}
