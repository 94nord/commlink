<?php

namespace App\Inbox\Sms\Transport;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the text to the log instead of sending it. The default everywhere
 * except production, so a developer can work the whole inbox — compose,
 * reply, read the thread back — without a Twilio account.
 */
class LogSmsTransport implements SmsTransport
{
    public function send(string $from, string $to, string $body): ?string
    {
        Log::info('SMS (not actually sent — log transport)', [
            'from' => $from,
            'to' => $to,
            'body' => $body,
        ]);

        // Shaped like a Twilio SID so anything reading it downstream behaves
        // the same as it would in production.
        return 'SM'.Str::lower(Str::random(32));
    }
}
