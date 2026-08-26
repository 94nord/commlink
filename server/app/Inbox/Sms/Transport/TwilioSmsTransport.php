<?php

namespace App\Inbox\Sms\Transport;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client;

/**
 * The real thing: Twilio Programmable Messaging.
 */
class TwilioSmsTransport implements SmsTransport
{
    public function __construct(private readonly Client $client) {}

    public function send(string $from, string $to, string $body): ?string
    {
        try {
            $message = $this->client->messages->create($to, [
                'from' => $from,
                'body' => $body,
            ]);
        } catch (RestException $e) {
            // A refusal from the provider is news for the person typing, not
            // a crash: they wrote something reasonable and it did not go out.
            // The reason is theirs to read — it names the number, the region
            // or the account — so it is passed through rather than flattened
            // into "something went wrong".
            Log::warning('Twilio refused an outbound text', [
                'code' => $e->getCode(),
                'reason' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'body' => __('inbox.sms_refused', ['reason' => $e->getMessage()]),
            ]);
        }

        return $message->sid;
    }
}
