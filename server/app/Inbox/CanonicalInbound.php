<?php

namespace App\Inbox;

use Carbon\CarbonInterface;

/**
 * An inbound message after its adapter has stripped away everything
 * channel-specific: who it is from (in that channel's canonical address
 * form), what it says, and when. This is the only shape the core ingests.
 */
final class CanonicalInbound
{
    public function __construct(
        /**
         * The sender's address, already normalized by the adapter — E.164
         * for a phone number, lowercased for a mailbox.
         */
        public readonly string $address,

        public readonly string $body,

        public readonly CarbonInterface $sentAt,

        /**
         * Which thread on that address this belongs to, for channels that
         * thread. Null means the address's single default thread, which is
         * all SMS ever has.
         */
        public readonly ?string $threadKey = null,

        /**
         * What the exchange is called, for channels that name them. Null on
         * SMS, where a number is one unbroken exchange with nothing to name.
         */
        public readonly ?string $subject = null,

        /**
         * The channel's own routing headers, if it has any — what a mail
         * client puts in `Message-ID`, `In-Reply-To` and `References`.
         * Opaque to the core: only the adapter that produced them knows what
         * they mean, and only it reads them back.
         *
         * @var array<string, mixed>
         */
        public readonly array $headers = [],

        /**
         * Anything the channel wants kept with the message. Opaque to the
         * core.
         *
         * @var array<string, mixed>
         */
        public readonly array $channelData = [],

        /**
         * Files that came with the message, each `{url, contentType}`. The
         * url is the channel's own — still to be fetched and copied, since
         * a provider's media link is authenticated and not kept forever.
         *
         * @var list<array{url: string, contentType: string}>
         */
        public readonly array $attachments = [],
    ) {}
}
