<?php

namespace App\Inbox\Sms;

use App\Enums\AddressType;
use App\Enums\Channel;
use App\Inbox\CanonicalInbound;
use App\Inbox\Contracts\ChannelAdapter;
use App\Inbox\Contracts\ChannelCapabilities;
use App\Inbox\Sms\Transport\SmsTransport;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * SMS, via Twilio Programmable Messaging. Every SMS-shaped assumption in the
 * system is meant to be in this file: Twilio's field names, phone numbers as
 * addresses, and the fact that a number is a single unbroken conversation
 * with no threads inside it.
 */
class SmsChannelAdapter implements ChannelAdapter
{
    public function __construct(private readonly SmsTransport $transport) {}

    public function channel(): Channel
    {
        return Channel::Sms;
    }

    public function addressType(): AddressType
    {
        return AddressType::Phone;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            // A number is one conversation. There is nothing to thread by.
            threadsByKey: false,
            // What Twilio accepts in a single send, across concatenated
            // segments.
            maxBodyLength: 1600,
            // Inbound only: a picture texted to the firm is kept and shown.
            // Sending one back is not wired up.
            supportsAttachments: true,
        );
    }

    public function normalizeAddress(string $address): string
    {
        // Canonical form belongs to the address space, not the transport —
        // MMS to this same number has to agree with us, or one person ends
        // up as two identities.
        return $this->addressType()->normalize($address);
    }

    /**
     * @param  array<string, mixed>  $payload  Twilio's inbound webhook fields
     */
    public function receive(array $payload): CanonicalInbound
    {
        return new CanonicalInbound(
            address: $this->normalizeAddress((string) ($payload['From'] ?? '')),
            body: (string) ($payload['Body'] ?? ''),
            // Twilio's inbound webhook fires on receipt and carries no
            // send timestamp, so arrival is the best truth available.
            sentAt: CarbonImmutable::now(),
            threadKey: null,
            channelData: array_filter([
                'from' => $payload['From'] ?? null,
                'to' => $payload['To'] ?? null,
                'message_sid' => $payload['MessageSid'] ?? null,
            ], fn ($value) => $value !== null),
            attachments: $this->media($payload),
        );
    }

    /**
     * Twilio numbers an MMS's parts: NumMedia says how many, and each one is
     * a MediaUrl{i}/MediaContentType{i} pair. Only pictures are kept —
     * a vCard or an audio clip arrives the same way but has nowhere to go
     * yet, and a message with nothing but one of those still lands as text.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{url: string, contentType: string}>
     */
    private function media(array $payload): array
    {
        $count = (int) ($payload['NumMedia'] ?? 0);
        $media = [];

        for ($i = 0; $i < $count; $i++) {
            $url = $payload["MediaUrl{$i}"] ?? null;
            $contentType = $payload["MediaContentType{$i}"] ?? null;

            if ($url === null || $contentType === null || ! str_starts_with($contentType, 'image/')) {
                continue;
            }

            $media[] = ['url' => (string) $url, 'contentType' => (string) $contentType];
        }

        return $media;
    }

    public function send(Message $message): array
    {
        // The firm's primary number. Once a thread records the address it
        // was started on, that binding wins over the primary — an exchange
        // already under way must not change the number it comes from.
        $from = $message->corporation->primaryAddress(AddressType::Phone)?->value;

        if ($from === null) {
            throw ValidationException::withMessages([
                'body' => __('inbox.no_sms_number'),
            ]);
        }

        $to = $message->thread->identity->value;

        $sid = $this->transport->send($from, $to, $message->body);

        return array_filter([
            'from' => $from,
            'to' => $to,
            'message_sid' => $sid,
        ], fn ($value) => $value !== null);
    }
}
