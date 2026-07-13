<?php

namespace App\Mail;

use App\Mail\Concerns\WithCorporationLogo;
use App\Models\Visit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Cancellation notice (E1). No manage link — the appointment is gone; a
 * round link would only confuse (rebooking flows are the admin's call).
 */
class VisitCancelledMail extends Mailable
{
    use WithCorporationLogo;

    public function __construct(public Visit $visit)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your visit has been cancelled',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.visit-cancelled',
            with: [
                'corporationName' => $this->visit->corporation->name,
                'customerName' => $this->visit->owner->name,
                'address' => $this->visit->workOrder?->address,
                'startsAt' => $this->visit->starts_at->format('l j F Y, H:i'),
                'logo' => $this->logoData($this->visit->corporation),
            ],
        );
    }
}
