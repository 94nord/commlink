<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A thin "something changed" signal for one conversation, not the message
 * itself — the client already knows how to refetch a conversation
 * (`queryClient.invalidateQueries`), so this avoids a second serialization
 * path that could drift from the HTTP response shape.
 *
 * ShouldBroadcastNow, not ShouldBroadcast: no queue worker runs in this
 * stack (SendOutboundMessage sends synchronously for the same reason), so
 * a plain ShouldBroadcast event would just sit in the jobs table forever.
 *
 * ShouldRescue matters because of where this fires from: BroadcastInboxUpdate
 * runs on the DomainEventRecorded spine, which SendOutboundMessage triggers
 * from *inside* its own DB transaction. Without this, a Reverb hiccup would
 * throw synchronously and roll back the customer's message along with it —
 * exactly the failure the polling fallback exists to be immune to. Rescued
 * failures still land in the log via report(), so they're not silent.
 */
class InboxUpdated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $corporationId,
        public int $conversationId,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("corporation.{$this->corporationId}.inbox");
    }

    public function broadcastAs(): string
    {
        return 'inbox.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['conversationId' => $this->conversationId];
    }
}
