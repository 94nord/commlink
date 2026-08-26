<?php

namespace App\Inbox\Contracts;

use App\Enums\AddressType;
use App\Enums\Channel;
use App\Inbox\CanonicalInbound;
use App\Models\Message;

/**
 * The seam between the inbox and the outside world. Everything that knows
 * how a particular channel behaves — its provider's payload shape, how it
 * writes an address, whether it threads, how a message is actually put on
 * the wire — lives behind this interface and nowhere else.
 *
 * The core calls these three methods and nothing more. Adding WhatsApp, or
 * webchat, or email is therefore a new class implementing this interface: no
 * change to conversations, identities, threads, the inbox, or the UI.
 */
interface ChannelAdapter
{
    /**
     * The channel this adapter speaks for — the transport.
     */
    public function channel(): Channel;

    /**
     * The kind of address this channel reaches.
     *
     * Channels that share an address space share identities, and therefore
     * threads and conversations: a voicemail and a text to the same number
     * are one exchange with one person, not two.
     */
    public function addressType(): AddressType;

    /**
     * What this channel can and cannot do, so callers can adapt instead of
     * assuming every channel looks like SMS.
     */
    public function capabilities(): ChannelCapabilities;

    /**
     * Put an address into the one form this channel stores it in, so that
     * the same person typed two different ways resolves to one identity.
     *
     * Only this adapter knows what canonical means on its channel — E.164
     * for a phone number, lowercased for a mailbox — which is what lets the
     * core accept an address from a compose box without knowing what kind of
     * thing it is.
     *
     * @throws \InvalidArgumentException when the value is not a usable
     *                                   address on this channel
     */
    public function normalizeAddress(string $address): string;

    /**
     * Normalize a provider's inbound payload into the one shape the core
     * understands.
     *
     * @param  array<string, mixed>  $payload
     */
    public function receive(array $payload): CanonicalInbound;

    /**
     * Put an outbound message on the wire.
     *
     * Returns whatever the channel wants remembered about the delivery — a
     * provider's message id, a reference chain, or nothing. The core merges
     * it into the message's channel_data without ever reading it.
     *
     * @return array<string, mixed>
     */
    public function send(Message $message): array;
}
