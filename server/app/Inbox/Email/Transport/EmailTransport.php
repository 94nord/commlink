<?php

namespace App\Inbox\Email\Transport;

use App\Inbox\Email\OutboundEmail;

/**
 * How an email actually leaves the building. Swappable the way
 * `SmsTransport` is, so development and the test suite never send a real
 * message to a real mailbox.
 */
interface EmailTransport
{
    /**
     * Deliver the message and return the `Message-ID` it went out with.
     */
    public function send(OutboundEmail $email): string;
}
