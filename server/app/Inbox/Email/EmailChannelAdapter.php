<?php

namespace App\Inbox\Email;

use App\Enums\AddressType;
use App\Enums\Channel;
use App\Inbox\CanonicalInbound;
use App\Inbox\Contracts\ChannelAdapter;
use App\Inbox\Contracts\ChannelCapabilities;
use App\Inbox\Contracts\ResolvesThreads;
use App\Inbox\Email\Transport\EmailTransport;
use App\Inbox\Support\HtmlSanitizer;
use App\Models\CorporationAddress;
use App\Models\EmailMessageId;
use App\Models\Identity;
use App\Models\Message;
use App\Models\Thread;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Email. Every email-shaped assumption in the system is meant to be in this
 * file and its neighbours: subjects, reference chains, and the fact that one
 * mailbox carries many unrelated exchanges at once.
 *
 * The inbound payload shape is deliberately generic — a flat array of the
 * fields every provider gives under some name or other — because which
 * provider parses the mail for us is not yet decided. Whatever it turns out
 * to be, only the controller that receives its webhook has to translate into
 * this shape.
 */
class EmailChannelAdapter implements ChannelAdapter, ResolvesThreads
{
    public function __construct(
        private readonly EmailTransport $transport,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    public function channel(): Channel
    {
        return Channel::Email;
    }

    public function addressType(): AddressType
    {
        return AddressType::Email;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            // One mailbox, many exchanges — the reason ResolvesThreads
            // exists at all.
            threadsByKey: true,
            // Mail has no practical length limit worth enforcing here.
            maxBodyLength: null,
            supportsAttachments: true,
            supportsRichText: true,
        );
    }

    public function normalizeAddress(string $address): string
    {
        return $this->addressType()->normalize($address);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function receive(array $payload): CanonicalInbound
    {
        $html = isset($payload['html']) ? (string) $payload['html'] : null;
        $text = isset($payload['text']) ? (string) $payload['text'] : null;

        // The plain-text part is what a mail client *said* the text is; when
        // it sent none, derive it, so `body` is never empty on a message
        // that plainly has words in it.
        $body = $text !== null && trim($text) !== ''
            ? trim($text)
            : ($html !== null ? $this->sanitizer->toPlainText($html) : '');

        return new CanonicalInbound(
            address: $this->normalizeAddress($this->senderOf($payload)),
            body: $body,
            sentAt: isset($payload['date'])
                ? CarbonImmutable::parse((string) $payload['date'])
                : CarbonImmutable::now(),
            // Threading is decided by threadFor(), not by a key on the way
            // in: which exchange this belongs to depends on what we already
            // hold, which the message itself cannot know.
            threadKey: null,
            subject: isset($payload['subject']) ? trim((string) $payload['subject']) : null,
            headers: [
                'message_id' => $this->trimId($payload['message_id'] ?? null),
                'in_reply_to' => $this->trimId($payload['in_reply_to'] ?? null),
                'references' => $this->parseReferences($payload['references'] ?? null),
            ],
            channelData: array_filter([
                'from' => $payload['from'] ?? null,
                'to' => $payload['to'] ?? null,
                'message_id' => $this->trimId($payload['message_id'] ?? null),
                'body_html' => $html === null ? null : $this->sanitizer->sanitize($html),
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * Which exchange this belongs to.
     *
     * Three answers in order of confidence. A reference chain is what mail
     * clients actually thread on, so any id in it that we have seen settles
     * it outright. A broken chain — a client that dropped the headers, or a
     * person who replied by writing a fresh message — falls back to the
     * subject, which is weaker but is what a human would go by. Neither
     * matching means this genuinely starts something new.
     */
    public function threadFor(Identity $identity, CanonicalInbound $inbound): Thread
    {
        $thread = $this->threadByReferences($identity, $inbound)
            ?? $this->threadBySubject($identity, $inbound)
            ?? $this->startThread($identity, $inbound);

        $this->remember($thread, $inbound->headers['message_id'] ?? null);

        return $thread;
    }

    /**
     * Any id in the chain that we already hold. Deliberately not just the
     * root: a mail client quotes whichever message the person hit reply on.
     */
    private function threadByReferences(Identity $identity, CanonicalInbound $inbound): ?Thread
    {
        $candidates = array_filter(array_merge(
            [$inbound->headers['in_reply_to'] ?? null],
            $inbound->headers['references'] ?? [],
        ));

        if ($candidates === []) {
            return null;
        }

        return EmailMessageId::query()
            ->whereIn('message_id', $candidates)
            // Scoped to this address: an id we hold under somebody else is
            // not this person's exchange, whatever their client quoted.
            ->whereIn('thread_id', $identity->threads()->select('threads.id'))
            ->latest('id')
            ->first()?->thread;
    }

    /**
     * The same subject on the same address, ignoring whatever prefix the
     * replying client stuck on the front.
     */
    private function threadBySubject(Identity $identity, CanonicalInbound $inbound): ?Thread
    {
        $normalized = static::normalizeSubject($inbound->subject);

        if ($normalized === '') {
            return null;
        }

        return $identity->threads()
            ->get()
            ->first(fn (Thread $thread) => static::normalizeSubject($thread->subject) === $normalized);
    }

    private function startThread(Identity $identity, CanonicalInbound $inbound): Thread
    {
        return $identity->threads()->create([
            'corporation_id' => $identity->corporation_id,
            // Unique per identity, and never reused: this is what makes each
            // new exchange its own row rather than colliding on the single
            // default thread SMS uses.
            'external_key' => (string) Str::uuid(),
            'subject' => $inbound->subject,
            'corporation_address_id' => $this->sendingAddressFor($identity)?->id,
        ]);
    }

    private function remember(Thread $thread, ?string $messageId): void
    {
        if ($messageId === null || $messageId === '') {
            return;
        }

        EmailMessageId::firstOrCreate(['message_id' => $messageId], ['thread_id' => $thread->id]);
    }

    public function send(Message $message): array
    {
        $thread = $message->thread;

        // The address the exchange was started on wins over the firm's
        // current primary: changing where new exchanges start must not move
        // one already under way.
        $from = $thread->corporationAddress?->value
            ?? $this->sendingAddressFor($thread->identity)?->value;

        if ($from === null) {
            throw ValidationException::withMessages([
                'body' => __('inbox.no_email_address'),
            ]);
        }

        $references = $this->chainFor($thread);
        $messageId = $this->mintMessageId($from);

        $sent = $this->transport->send(new OutboundEmail(
            from: $from,
            to: $thread->identity->value,
            subject: $thread->subject ?? __('inbox.default_subject'),
            text: $message->body,
            html: $message->body_html,
            messageId: $messageId,
            inReplyTo: $references === [] ? null : end($references),
            references: $references,
        ));

        // Ours now too, so the customer's reply to it threads back here.
        $this->remember($thread, $sent);

        return array_filter([
            'from' => $from,
            'to' => $thread->identity->value,
            'message_id' => $sent,
            'in_reply_to' => $references === [] ? null : end($references),
        ], fn ($value) => $value !== null);
    }

    /**
     * Every id this exchange has carried, oldest first.
     *
     * @return list<string>
     */
    private function chainFor(Thread $thread): array
    {
        return EmailMessageId::query()
            ->where('thread_id', $thread->id)
            ->orderBy('id')
            ->pluck('message_id')
            ->all();
    }

    /**
     * A globally unique id on the sending domain, which is what makes a
     * reply we receive matchable back to the message that prompted it.
     */
    private function mintMessageId(string $from): string
    {
        $domain = Str::after($from, '@') ?: 'localhost';

        return Str::uuid().'@'.$domain;
    }

    private function sendingAddressFor(Identity $identity): ?CorporationAddress
    {
        return $identity->corporation->primaryAddress(AddressType::Email);
    }

    /**
     * A subject with the replying client's prefix taken off, for comparison
     * only — the thread keeps whatever it was originally called.
     *
     * Danish clients write `SV:` and `VS:` where English ones write `Re:`
     * and `Fwd:`, and a chain that has been round a few times stacks them.
     */
    public static function normalizeSubject(?string $subject): string
    {
        $subject = trim((string) $subject);

        do {
            $previous = $subject;
            $subject = trim((string) preg_replace('/^\s*(re|sv|vs|fwd|fw|vb)\s*(\[\d+\])?\s*:\s*/i', '', $subject));
        } while ($subject !== $previous);

        return mb_strtolower(preg_replace('/\s+/', ' ', $subject) ?? $subject);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function senderOf(array $payload): string
    {
        $from = trim((string) ($payload['from'] ?? ''));

        // Providers hand over either a bare address or a display name with
        // the address in brackets; both mean the same person.
        if (preg_match('/<([^>]+)>/', $from, $matches) === 1) {
            return $matches[1];
        }

        return $from;
    }

    private function trimId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value, " \t\n\r\0\x0B<>");

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    private function parseReferences(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $ids = is_array($value) ? $value : (preg_split('/\s+/', (string) $value) ?: []);

        return array_values(array_filter(array_map(
            fn ($id) => $this->trimId($id),
            $ids,
        )));
    }
}
