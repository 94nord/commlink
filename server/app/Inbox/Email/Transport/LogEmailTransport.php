<?php

namespace App\Inbox\Email\Transport;

use App\Inbox\Email\OutboundEmail;
use Illuminate\Support\Facades\Log;

/**
 * Writes the email to the log instead of sending it. The default everywhere
 * except where mail is deliberately configured, so a developer can work the
 * whole inbox without anything leaving the machine.
 */
class LogEmailTransport implements EmailTransport
{
    public function send(OutboundEmail $email): string
    {
        Log::info('Email (not actually sent — log transport)', [
            'from' => $email->from,
            'to' => $email->to,
            'subject' => $email->subject,
            'message_id' => $email->messageId,
            'in_reply_to' => $email->inReplyTo,
            'body' => $email->text,
        ]);

        return $email->messageId;
    }
}
