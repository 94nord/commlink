<?php

namespace App\Inbox\Actions;

use App\Enums\CustomerKind;
use App\Models\Customer;
use App\Models\Identity;
use App\Support\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes one address off a person — the repair for a number attached to the
 * wrong customer, or a merge that turned out to be two people after all.
 *
 * The address moves to a customer of its own, unnamed, and its history goes
 * with it: the messages hang off its threads and always have. What stays
 * behind is the rest of that person — their other addresses, their name,
 * their conversation.
 *
 * An unnamed customer with nothing but this one address is the exception:
 * there is nothing to separate them from, and pulling the address out would
 * only churn the same person under a new id. That is refused.
 */
class UnlinkIdentity
{
    public function __construct(private readonly OpenConversation $open) {}

    public function handle(Identity $identity): Customer
    {
        $owner = $identity->customer;

        if (! $owner->isNamed() && $owner->identities()->count() <= 1) {
            throw ValidationException::withMessages([
                'identity_id' => __('inbox.nothing_to_unlink'),
            ]);
        }

        return DB::transaction(function () use ($identity, $owner) {
            $destination = $owner->corporation->customers()->create([
                'kind' => CustomerKind::Person,
                'name' => null,
            ]);

            $identity->update(['customer_id' => $destination->getKey()]);

            // The history moved with the address, so anything the inbox
            // sorts or previews on has to be rebuilt on both sides.
            if ($identity->threads()->whereHas('messages')->exists()) {
                $this->open->handle($destination)->recomputeLastMessage();
            }

            $owner->conversation?->recomputeLastMessage();

            EventRecorder::record(
                $owner->corporation,
                'identity.unlinked',
                subject: $identity,
                payload: [
                    'from_customer_id' => $owner->getKey(),
                    'to_customer_id' => $destination->getKey(),
                ],
            );

            return $destination->refresh();
        });
    }
}
