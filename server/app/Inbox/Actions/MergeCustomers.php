<?php

namespace App\Inbox\Actions;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Support\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Two customer records turn out to be one person.
 *
 * Almost always this is somebody saying who an unnamed number belongs to:
 * the number's placeholder customer folds into the real one. It is the same
 * operation either way — move the addresses, keep the history, retire the
 * record nobody needs.
 *
 * Messages are not touched. They hang off threads, which hang off the
 * addresses being moved, so the whole history follows on its own.
 */
class MergeCustomers
{
    public function __construct(private readonly OpenConversation $open) {}

    public function handle(Customer $source, Customer $target): Customer
    {
        if ($source->is($target)) {
            return $target;
        }

        if ($source->corporation_id !== $target->corporation_id) {
            throw ValidationException::withMessages([
                'customerId' => __('customers.cross_corporation'),
            ]);
        }

        if ($source->isNamed()) {
            throw ValidationException::withMessages([
                'customerId' => __('inbox.conversation_already_linked', ['name' => $source->name]),
            ]);
        }

        return DB::transaction(function () use ($source, $target) {
            $source->identities()->update(['customer_id' => $target->getKey()]);

            $sourceConversation = $source->conversation;
            $targetConversation = $target->conversation;

            if ($sourceConversation !== null && $targetConversation === null) {
                // Nothing to merge into: the exchange simply turns out to be
                // with somebody known. Keep it — and its id, which the caller
                // is probably looking at — rather than starting a new one.
                $sourceConversation->update(['customer_id' => $target->getKey()]);
            } elseif ($sourceConversation !== null) {
                // An archived conversation that just absorbed a live one
                // belongs back in the queue.
                if ($sourceConversation->status === ConversationStatus::Open) {
                    $targetConversation->update(['status' => ConversationStatus::Open]);
                }

                $sourceConversation->delete();
                $targetConversation->refresh()->recomputeLastMessage();
            }

            $source->delete();

            EventRecorder::record(
                $target->corporation,
                'customer.merged',
                subject: $target,
                payload: ['merged_customer_id' => $source->getKey()],
            );

            return $target->refresh();
        });
    }

    /**
     * The conversation the caller should be looking at afterwards, if there
     * is one. A merge that carried no history leaves nothing to open.
     */
    public function survivingConversation(Customer $target): ?Conversation
    {
        return $target->fresh()->conversation;
    }
}
