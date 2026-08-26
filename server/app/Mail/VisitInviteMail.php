<?php

namespace App\Mail;

use App\Mail\Concerns\WithCorporationLogo;
use App\Models\RoundWorkOrder;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The booking invite (E1): the personal magic link for one case in one
 * round. Content is rebuilt from the membership at send time, so a resend
 * always reflects the current round.
 */
class VisitInviteMail extends Mailable
{
    use WithCorporationLogo;

    public function __construct(public RoundWorkOrder $membership)
    {
    }

    public function envelope(): Envelope
    {
        $type = $this->membership->round->type->value;

        return new Envelope(
            subject: 'Book your '.$type.' visit — '.$this->membership->workOrder->address,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.visit-invite',
            with: [
                'corporationName' => $this->membership->round->corporation->name,
                'customerName' => $this->membership->workOrder->customer->name,
                'address' => $this->membership->workOrder->address,
                'type' => $this->membership->round->type->value,
                'closeOffOn' => $this->membership->round->close_off_on->format('j F Y'),
                'bookingUrl' => route('booking.show', $this->membership->token),
                'logo' => $this->logoData($this->membership->round->corporation),
            ],
        );
    }
}
