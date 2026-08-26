<?php

namespace App\Inbox\Sms\Transport;

/**
 * How an SMS actually leaves the building. Swappable the way Laravel's mail
 * drivers are, so development and the test suite never need real provider
 * credentials or send a real text to a real phone.
 */
interface SmsTransport
{
    /**
     * Deliver the text and return the provider's id for it, if it gives one.
     */
    public function send(string $from, string $to, string $body): ?string;
}
