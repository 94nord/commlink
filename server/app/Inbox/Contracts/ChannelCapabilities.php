<?php

namespace App\Inbox\Contracts;

/**
 * What a channel is capable of. SMS needs almost none of this, which is
 * exactly why it exists now: the first richer channel to arrive (WhatsApp
 * with its read receipts and 24-hour send window, email with its subjects
 * and reference chains) must be able to say so without the core having
 * hardcoded SMS's limitations as universal truths.
 */
final class ChannelCapabilities
{
    public function __construct(
        /**
         * Whether the channel splits an address into several threads of its
         * own accord. False means one thread per address, forever.
         */
        public readonly bool $threadsByKey = false,

        /**
         * Longest body the channel will carry, or null for no practical
         * limit.
         */
        public readonly ?int $maxBodyLength = null,

        public readonly bool $supportsAttachments = false,

        /**
         * Whether the channel carries formatting. False means a message is
         * words and nothing else — sending markup would put the tags
         * themselves in front of the customer.
         */
        public readonly bool $supportsRichText = false,
    ) {}
}
