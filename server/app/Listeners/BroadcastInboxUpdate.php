<?php

namespace App\Listeners;

use App\Events\DomainEventRecorded;
use App\Events\InboxUpdated;
use App\Models\Message;

/**
 * Turns the two message-related entries on the event spine into a push to
 * the inbox — sitting on top of the log rather than inside
 * IngestInboundMessage/SendOutboundMessage themselves, per
 * EventRecorder's own contract.
 */
class BroadcastInboxUpdate
{
    public function handle(DomainEventRecorded $event): void
    {
        if (! in_array($event->event->type, ['message.received', 'message.sent'], true)) {
            return;
        }

        $message = $event->event->subject;

        if (! $message instanceof Message) {
            return;
        }

        $conversation = $message->thread->identity->customer->conversation;

        if ($conversation === null) {
            return;
        }

        InboxUpdated::dispatch($message->corporation_id, $conversation->id);
    }
}
