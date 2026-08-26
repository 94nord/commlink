<?php

namespace App\Inbox\Email\Transport;

use App\Inbox\Email\OutboundEmail;
use Illuminate\Mail\Mailer;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * The real thing, through Laravel's own mailer — which means Mailpit in
 * development and the provider's SMTP relay in production, with nothing here
 * knowing the difference. That is the point: no provider SDK, no provider
 * names, just SMTP configured per environment.
 *
 * Built as a raw MIME message rather than a Mailable because the headers are
 * the whole job. `Message-ID`, `In-Reply-To` and `References` are what make a
 * reply land in the customer's existing thread instead of starting a new one
 * in their mail client, and Laravel's mailer will happily mint its own
 * `Message-ID` if we do not insist on ours.
 */
class MailEmailTransport implements EmailTransport
{
    public function __construct(private readonly Mailer $mailer) {}

    public function send(OutboundEmail $email): string
    {
        $message = (new MimeEmail)
            ->from($email->from)
            ->to($email->to)
            ->subject($email->subject)
            ->text($email->text);

        if ($email->html !== null) {
            $message->html($email->html);
        }

        $headers = $message->getHeaders();

        // Symfony writes the angle brackets; ours are stored without them,
        // which is also how they are matched on the way back in.
        $headers->addIdHeader('Message-ID', $email->messageId);

        if ($email->inReplyTo !== null) {
            $headers->addIdHeader('In-Reply-To', $email->inReplyTo);
        }

        if ($email->references !== []) {
            $headers->addIdHeader('References', $email->references);
        }

        $this->mailer->getSymfonyTransport()->send($message);

        return $email->messageId;
    }
}
