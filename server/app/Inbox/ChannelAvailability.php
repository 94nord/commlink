<?php

namespace App\Inbox;

use App\Enums\AddressType;
use App\Enums\ChannelStatus;
use App\Models\Corporation;
use App\Models\Identity;

/**
 * Whether a firm can actually use a kind of address right now, as one
 * answer the UI can switch on.
 *
 * Three separate facts decide it — the module being switched on, an address
 * of that kind being provisioned to send from, and whether there is history
 * worth still showing — and they are held here rather than in a controller
 * because two callers need the same answer: the per-identity status in a
 * conversation payload, and the corporation-level list the compose box uses
 * to decide whether it can offer anything at all.
 *
 * Nothing here knows about a specific channel; it reasons in address types
 * and the modules they map to.
 */
class ChannelAvailability
{
    public function __construct(private readonly ChannelRegistry $channels) {}

    /**
     * What the firm can do with this kind of address, ignoring any
     * particular person.
     */
    public function forCorporation(Corporation $corporation, AddressType $type): ChannelStatus
    {
        // An address space this installation has no adapter for cannot be
        // offered, whatever the firm is switched on for: the send would not
        // fail politely, it would blow up in the registry. This is what
        // keeps a half-finished channel invisible until it can actually
        // carry a message.
        if (! $this->channels->supports($type->defaultChannel())) {
            return ChannelStatus::Unavailable;
        }

        if (! $corporation->hasModule($type->module())) {
            return ChannelStatus::Unavailable;
        }

        $address = $corporation->primaryAddress($type);

        // Switched on but with nowhere to send from is not "active" — the
        // send would fail at the adapter. Better to say so before the
        // message is typed than after.
        if ($address === null) {
            return ChannelStatus::Unavailable;
        }

        // An address the firm has not proved is theirs is not one to send
        // from: mail from an unauthenticated domain is spam-filed or
        // rejected outright, which looks to the firm like silence. A number
        // is verified the moment the provider hands it over; a mailbox on
        // the firm's own domain has to prove itself through DNS.
        return $address->verified_at === null
            ? ChannelStatus::Unavailable
            : ChannelStatus::Active;
    }

    /**
     * What the firm can do with one customer's address.
     *
     * The one thing this adds over the corporation-level answer: history.
     * An address the firm has been texting for a year does not vanish
     * because the module was switched off this morning — it goes read-only,
     * so the record stays readable and nothing new goes out.
     */
    public function forIdentity(Corporation $corporation, Identity $identity, bool $hasHistory): ChannelStatus
    {
        $status = $this->forCorporation($corporation, $identity->address_type);

        if ($status === ChannelStatus::Active) {
            return $status;
        }

        return $hasHistory ? ChannelStatus::ReadOnly : ChannelStatus::Unavailable;
    }

    /**
     * Every kind of address the firm could start something new on today.
     *
     * The compose box asks this: an empty result is a real empty state
     * ("this firm has no channels switched on"), not a send that fails after
     * the message is written.
     *
     * @return list<AddressType>
     */
    public function availableTo(Corporation $corporation): array
    {
        return array_values(array_filter(
            AddressType::cases(),
            fn (AddressType $type) => $this->forCorporation($corporation, $type) === ChannelStatus::Active,
        ));
    }
}
