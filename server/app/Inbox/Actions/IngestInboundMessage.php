<?php

namespace App\Inbox\Actions;

use App\Enums\AddressType;
use App\Enums\CustomerKind;
use App\Enums\MessageDirection;
use App\Inbox\CanonicalInbound;
use App\Inbox\Contracts\ChannelAdapter;
use App\Inbox\Contracts\ResolvesThreads;
use App\Models\Corporation;
use App\Models\Identity;
use App\Models\Message;
use App\Models\Thread;
use App\Support\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Files an inbound message where the firm will find it: against the address
 * that sent it, in that address's thread, under whoever owns that address.
 *
 * An address nobody has seen before belongs to somebody nobody has named
 * yet, so both the person and the address are created on the spot. Nothing
 * is asked of the firm first — the customer is simply unnamed, shown by
 * their number, and stays out of the customer list until somebody says who
 * they are.
 *
 * The adapter is passed in rather than a bare channel because two different
 * facts are needed: which transport carried this message (kept on the
 * message) and what kind of address it arrived at (which identity owns it).
 * Conflating those is what would split one person across channels.
 */
class IngestInboundMessage
{
    public function __construct(private readonly OpenConversation $open) {}

    public function handle(Corporation $corporation, ChannelAdapter $adapter, CanonicalInbound $inbound): Message
    {
        $message = DB::transaction(function () use ($corporation, $adapter, $inbound) {
            $identity = $this->resolveIdentity($corporation, $adapter->addressType(), $inbound->address);

            $thread = $this->resolveThread($corporation, $adapter, $identity, $inbound);

            $channelData = $inbound->channelData;

            // A channel that carries a rich body hands it over sanitized in
            // its channel_data; the core lifts it into the generic column
            // without ever knowing which channel produced it.
            $bodyHtml = $channelData['body_html'] ?? null;
            unset($channelData['body_html']);

            $message = $thread->messages()->create([
                'corporation_id' => $corporation->id,
                'channel' => $adapter->channel(),
                'direction' => MessageDirection::Inbound,
                'body' => $inbound->body,
                'body_html' => $bodyHtml,
                'channel_data' => $channelData !== [] ? $channelData : null,
                'sent_at' => $inbound->sentAt,
            ]);

            $this->open->handle($identity->customer)->touchLastMessage($message);

            EventRecorder::record($corporation, 'message.received', subject: $message);

            return $message;
        });

        // Deliberately after the commit: fetching a picture means calling the
        // channel's servers, and a transaction held open across a network
        // round trip is its own kind of trouble. The message is already
        // filed, so a picture that will not come down leaves the text intact
        // rather than losing both.
        $this->storeAttachments($message, $inbound->attachments);

        return $message;
    }

    /**
     * Which exchange this belongs to.
     *
     * Most channels have nothing to decide — a phone number is one unbroken
     * exchange — so the default is the address's single thread. A channel
     * that genuinely threads says so by implementing `ResolvesThreads`, and
     * then it decides, because only it understands its own chain.
     */
    private function resolveThread(
        Corporation $corporation,
        ChannelAdapter $adapter,
        Identity $identity,
        CanonicalInbound $inbound,
    ): Thread {
        if ($adapter instanceof ResolvesThreads) {
            return $adapter->threadFor($identity, $inbound);
        }

        return $identity->threads()->firstOrCreate(
            ['external_key' => $inbound->threadKey],
            [
                'corporation_id' => $corporation->id,
                // Bound once, so a later change of primary address does not
                // move an exchange already under way.
                'corporation_address_id' => $corporation->primaryAddress($identity->address_type)?->id,
            ],
        );
    }

    /**
     * @param  list<array{url: string, contentType: string}>  $attachments
     */
    private function storeAttachments(Message $message, array $attachments): void
    {
        foreach ($attachments as $index => $attachment) {
            try {
                $response = Http::withBasicAuth(
                    (string) config('services.twilio.sid'),
                    (string) config('services.twilio.token'),
                )->get($attachment['url']);

                if ($response->failed()) {
                    throw new RuntimeException("media fetch returned {$response->status()}");
                }

                $body = $response->body();
                $path = "attachments/{$message->corporation_id}/{$message->id}/{$index}";

                // A write that quietly did not happen would otherwise leave a
                // row pointing at a file nobody can serve.
                if (Storage::put($path, $body) === false) {
                    throw new RuntimeException("could not write {$path}");
                }

                $message->attachments()->create([
                    'corporation_id' => $message->corporation_id,
                    'content_type' => $attachment['contentType'],
                    'path' => $path,
                    'byte_size' => strlen($body),
                ]);
            } catch (Throwable $e) {
                // One picture that would not come down is not worth losing
                // the message it came with.
                Log::warning('Inbound attachment could not be stored', [
                    'message_id' => $message->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Find the address, or let it arrive: a first message from an unknown
     * address creates both the address and the person it belongs to.
     */
    private function resolveIdentity(Corporation $corporation, AddressType $addressType, string $address): Identity
    {
        $existing = Identity::query()
            ->where('corporation_id', $corporation->id)
            ->where('address_type', $addressType)
            ->where('value', $address)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $customer = $corporation->customers()->create([
            'kind' => CustomerKind::Person,
            'name' => null,
        ]);

        EventRecorder::record($corporation, 'customer.created', subject: $customer);

        return $customer->identities()->create([
            'corporation_id' => $corporation->id,
            'address_type' => $addressType,
            'value' => $address,
        ]);
    }
}
