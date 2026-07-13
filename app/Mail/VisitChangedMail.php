<?php

namespace App\Mail;

use App\Mail\Concerns\WithCorporationLogo;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Appointment-change notice (E1): an admin moved an already-scheduled
 * visit. Shows old → new; the manage link only for round visits.
 */
class VisitChangedMail extends Mailable
{
    use WithCorporationLogo;

    public function __construct(public Visit $visit, public string $previousStartsAt)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your visit has been moved — now '.$this->visit->starts_at->format('j M Y H:i'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.visit-changed',
            with: [
                'corporationName' => $this->visit->corporation->name,
                'customerName' => $this->visit->owner->name,
                'address' => $this->visit->workOrder?->address,
                'previousStartsAt' => CarbonImmutable::parse($this->previousStartsAt)->format('l j F Y, H:i'),
                'startsAt' => $this->visit->starts_at->format('l j F Y, H:i'),
                'endsAt' => $this->visit->ends_at->format('H:i'),
                'bookingUrl' => $this->visit->bookingUrl(),
                'logo' => $this->logoData($this->visit->corporation),
            ],
        );
    }
}
