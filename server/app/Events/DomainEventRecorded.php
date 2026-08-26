<?php

namespace App\Events;

use App\Models\Event;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired for every row appended to the central event log (X1), so consumers
 * (the outbox today; notifications and host connectors later) subscribe to
 * the spine instead of being called at each state-change site.
 */
class DomainEventRecorded
{
    use Dispatchable;

    public function __construct(public Event $event)
    {
    }
}
