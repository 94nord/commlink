<?php

namespace App\Listeners;

use App\Events\DomainEventRecorded;
use App\Models\Customer;
use App\Models\Event;
use App\Models\OutboxMessage;
use App\Models\RoundWorkOrder;
use App\Models\Visit;

/**
 * The outbox's subscription to the event spine (X1): state changes that
 * customers must hear about become durable outbox rows here, drained by
 * app:send-outbox. Nothing in this listener talks to a mail transport.
 *
 * Invite timing (SO1/SO5): opening a round queues invites for every
 * recipient not yet sent — both manual and scheduled opens. Late-added
 * recipients are never auto-emailed; they get an explicit
 * `round.invite_requested` (individual send / resend, SO2).
 */
class QueueOutboxEmails
{
    public function handle(DomainEventRecorded $recorded): void
    {
        $event = $recorded->event;

        match ($event->type) {
            'round.opened' => $this->queueInvitesForRound($event),
            'round.invite_requested' => $this->queueInvite(
                RoundWorkOrder::find($event->payload['round_work_order_id'] ?? null)
            ),
            'visit.scheduled' => $this->queueVisitScheduled($event),
            'visit.cancelled' => $this->queueVisitNotice($event, 'cancelled'),
            default => null,
        };
    }

    private function queueInvitesForRound(Event $event): void
    {
        $memberships = RoundWorkOrder::query()
            ->where('round_id', $event->subject_id)
            ->whereNull('invite_sent_at')
            ->get();

        foreach ($memberships as $membership) {
            $this->queueInvite($membership);
        }
    }

    private function queueInvite(?RoundWorkOrder $membership): void
    {
        $customer = $membership?->workOrder?->customer;

        if ($membership === null || $membership->round === null || $customer === null) {
            return;
        }

        OutboxMessage::create([
            'corporation_id' => $membership->round->corporation_id,
            'type' => 'invite',
            'recipient_email' => $customer->email,
            'payload' => ['round_work_order_id' => $membership->id],
        ]);
    }

    private function queueVisitScheduled(Event $event): void
    {
        // No actor = the customer acting through their link: both a first
        // booking and a self-reschedule get a confirmation (E1). An admin
        // moving an already-scheduled visit is an appointment change; an
        // admin scheduling a fresh visit reads as a confirmation too.
        $previousStart = $event->payload['previous']['starts_at'] ?? null;
        $type = $event->actor_id !== null && $previousStart !== null ? 'changed' : 'booked';

        $this->queueVisitNotice($event, $type, $previousStart === null ? [] : [
            'previous_starts_at' => $previousStart,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extraPayload
     */
    private function queueVisitNotice(Event $event, string $type, array $extraPayload = []): void
    {
        $visit = Visit::find($event->subject_id);
        $owner = $visit?->owner;

        if ($visit === null || ! $owner instanceof Customer) {
            return;
        }

        OutboxMessage::create([
            'corporation_id' => $visit->corporation_id,
            'type' => $type,
            'recipient_email' => $owner->email,
            'payload' => ['visit_id' => $visit->id] + $extraPayload,
        ]);
    }
}
