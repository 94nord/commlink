<?php

namespace App\Inbox\Email;

/**
 * One email on its way out, after the adapter has worked out everything the
 * transport should not have to: which address it comes from, what chain it
 * belongs to, and both forms of the body.
 */
final class OutboundEmail
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $subject,
        /** The canonical plain-text body. Always present. */
        public readonly string $text,
        /** The rich body, where there is one. */
        public readonly ?string $html,
        /** The id this message goes out with, minted by the adapter. */
        public readonly string $messageId,
        /** The message being replied to, if any. */
        public readonly ?string $inReplyTo = null,
        /**
         * The chain so far, oldest first. Mail clients thread on this, and a
         * client that only sees `In-Reply-To` still needs it to place a
         * message whose parent it never received.
         *
         * @var list<string>
         */
        public readonly array $references = [],
    ) {}
}
