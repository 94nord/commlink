<?php

namespace App\Mail;

use App\Mail\Concerns\WithCorporationLogo;
use App\Models\Visit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Booking / reschedule confirmation (E1): sent when a time is set with no
 * previous appointment being moved by an admin. Includes the manage link
 * only for round visits — a standalone visit is admin-managed.
 */
class VisitBookedMail extends Mailable
{
    use WithCorporationLogo;

    public function __construct(public Visit $visit)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your visit is booked — '.$this->visit->starts_at->format('j M Y H:i'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.visit-booked',
            with: [
                'corporationName' => $this->visit->corporation->name,
                'customerName' => $this->visit->owner->name,
                'address' => $this->visit->workOrder?->address,
                'startsAt' => $this->visit->starts_at->format('l j F Y, H:i'),
                'endsAt' => $this->visit->ends_at->format('H:i'),
                'bookingUrl' => $this->visit->bookingUrl(),
                'logo' => $this->logoData($this->visit->corporation),
            ],
        );
    }
}
