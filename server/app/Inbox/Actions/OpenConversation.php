<?php

namespace App\Inbox\Actions;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Support\EventRecorder;

/**
 * The team's state about a person, brought into being the moment there is
 * something to have state about.
 *
 * Deliberately not created alongside the customer: a number somebody wrote
 * down is not an exchange, and an inbox full of people nobody has spoken to
 * is not an inbox.
 */
class OpenConversation
{
    public function handle(Customer $customer): Conversation
    {
        $existing = Conversation::query()
            ->where('corporation_id', $customer->corporation_id)
            ->where('customer_id', $customer->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $conversation = $customer->corporation->conversations()->create([
            'customer_id' => $customer->id,
            'status' => ConversationStatus::Open,
        ]);

        EventRecorder::record($customer->corporation, 'conversation.started', subject: $conversation);

        return $conversation;
    }
}
