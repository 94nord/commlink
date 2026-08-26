<?php

namespace App\Http\Controllers;

use App\Enums\AddressType;
use App\Enums\ChannelStatus;
use App\Enums\MessageDirection;
use App\Http\Requests\LinkConversationRequest;
use App\Http\Requests\StoreConversationRequest;
use App\Inbox\Actions\ComposeMessage;
use App\Inbox\Actions\MergeCustomers;
use App\Inbox\ChannelAvailability;
use App\Inbox\ChannelRegistry;
use App\Models\Conversation;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\Identity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The shared inbox. Every conversation in the firm is visible to everyone in
 * it — that is the product — so the only scoping here is the corporation,
 * resolved from the signed-in user and never from the URL.
 */
class ConversationController extends Controller
{
    /**
     * The queue: most recently active first, each with the last thing said.
     */
    public function index(Request $request, ChannelAvailability $availability): JsonResponse
    {
        $corporation = $request->user()->corporation;

        $conversations = $corporation->conversations()
            ->with(['customer.identities', 'lastMessage.attachments'])
            // A conversation exists only where something was said; one whose
            // history has all moved away is no longer an inbox item.
            ->whereNotNull('last_message_id')
            // A conversation nobody has said anything in yet belongs at the
            // bottom, not the top. PostgreSQL and SQLite disagree on where
            // nulls land by default, so say it explicitly.
            ->orderByRaw('last_message_at desc nulls last')
            ->orderByDesc('id')
            ->get();

        $unread = $this->unreadCounts($request->user(), $conversations->modelKeys());

        return response()->json([
            'conversations' => $conversations->map(
                fn (Conversation $conversation) => $this->summary($conversation) + [
                    'unreadCount' => $unread[$conversation->id] ?? 0,
                ],
            ),
            // What the firm could start something new on. Empty means the
            // compose box has nothing to offer and says so, rather than
            // taking a message and failing on send.
            'availableAddressTypes' => array_map(
                fn (AddressType $type) => $type->value,
                $availability->availableTo($corporation),
            ),
        ]);
    }

    /**
     * Unread counts for a whole page of conversations in one query.
     *
     * Per user, and inbound only: what you have not caught up on is what the
     * customer said, not what a colleague answered. Doing this per row would
     * be an N+1 on the inbox's hottest, most-polled query.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, int>
     */
    private function unreadCounts($user, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        return DB::table('conversations')
            ->join('identities', 'identities.customer_id', '=', 'conversations.customer_id')
            ->join('threads', 'threads.identity_id', '=', 'identities.id')
            ->join('messages', 'messages.thread_id', '=', 'threads.id')
            ->leftJoin('conversation_reads', function ($join) use ($user) {
                $join->on('conversation_reads.conversation_id', '=', 'conversations.id')
                    ->where('conversation_reads.user_id', '=', $user->id);
            })
            ->whereIn('conversations.id', $conversationIds)
            ->whereNull('identities.deleted_at')
            ->whereNull('threads.deleted_at')
            ->where('messages.direction', MessageDirection::Inbound->value)
            ->where(fn ($query) => $query
                ->whereNull('conversation_reads.last_read_message_id')
                ->orWhereColumn('messages.id', '>', 'conversation_reads.last_read_message_id'))
            ->groupBy('conversations.id')
            ->selectRaw('conversations.id as conversation_id, count(messages.id) as unread')
            ->pluck('unread', 'conversation_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * One conversation and a page of its timeline, newest page first.
     */
    public function show(
        Request $request,
        int $conversation,
        ChannelAvailability $availability,
        ChannelRegistry $channels,
    ): JsonResponse {
        $corporation = $request->user()->corporation;

        $conversation = $corporation->conversations()
            ->with('customer.identities')
            ->findOrFail($conversation);

        // Two levels of narrowing, both resolved through what this
        // conversation actually owns — never from an id alone, or one firm
        // could read another's timeline by guessing.
        //
        // An address is the outer level (everything said to this mailbox or
        // this number) and one exchange inside it the inner. A thread
        // already implies its address, so it wins when both arrive.
        $threads = $this->threads($conversation, $channels);
        $thread = $request->integer('thread') ?: null;
        $identity = $request->integer('identity') ?: null;

        if ($thread !== null && ! in_array($thread, array_column($threads, 'id'), true)) {
            abort(404);
        }

        if ($identity !== null && ! $conversation->addresses()->contains('id', $identity)) {
            abort(404);
        }

        [$messages, $hasMore] = $this->timelinePage(
            $conversation,
            $request->integer('before') ?: null,
            $thread,
            $thread === null ? $identity : null,
        );

        // Read *before* marking read, or the per-address counts would always
        // be zero: this response is the one that says where the new traffic
        // came in, and marking read is what makes that true only once.
        $identities = $this->identities($conversation, $corporation, $availability, $channels, $request->user());

        // Opening a conversation is what "reading" means. The pane polls, so
        // anything arriving while it is on screen is seen as it lands.
        $conversation->markReadBy($request->user());

        return response()->json([
            'conversation' => $this->summary($conversation) + [
                'identities' => $identities,
                'threads' => $threads,
                'messages' => $messages->map(fn (Message $message) => $this->message($message)),
                'hasMore' => $hasMore,
            ],
        ]);
    }

    /**
     * Who does this address belong to, if anyone?
     *
     * Asked before starting a conversation, so that writing to a number the
     * firm already has history with opens that history instead of a blank
     * page. Also the first point where a mistyped number is rejected, while
     * the person can still fix it.
     */
    public function resolve(Request $request, ChannelAvailability $availability): JsonResponse
    {
        $validated = $request->validate(['address' => ['required', 'string']]);

        // What was typed decides the channel. Neither a dialable number nor
        // a well-formed mailbox is a typo, and the person can still fix it.
        $addressType = AddressType::sniff($validated['address']);

        if ($addressType === null) {
            throw ValidationException::withMessages(['address' => __('inbox.not_an_address')]);
        }

        $corporation = $request->user()->corporation;

        // Right shape, wrong channel for this firm. Said by name here rather
        // than after a whole message has been written.
        if ($availability->forCorporation($corporation, $addressType) !== ChannelStatus::Active) {
            throw ValidationException::withMessages([
                'address' => __('inbox.channel_not_available', ['channel' => $addressType->defaultChannel()->value]),
            ]);
        }

        $address = $addressType->normalize($validated['address']);

        $identity = $corporation->identities()
            ->where('address_type', $addressType)
            ->where('value', $address)
            ->first();

        $conversation = $identity?->customer?->conversation()
            ->with(['customer.identities', 'lastMessage.attachments'])
            ->first();

        return response()->json([
            'address' => $address,
            // So the caller can label the draft and know which channel it
            // is about to start on.
            'addressType' => $addressType->value,
            'conversation' => $conversation ? $this->summary($conversation) : null,
        ]);
    }

    /**
     * Compose: write to a number, a known customer, or both at once.
     */
    public function store(
        StoreConversationRequest $request,
        ComposeMessage $compose,
        ChannelAvailability $availability,
    ): JsonResponse {
        $corporation = $request->user()->corporation;

        $customer = $request->validated('customerId') !== null
            ? $corporation->customers()->findOrFail($request->validated('customerId'))
            : null;

        $address = $request->validated('address');

        // Writing to a bare customer with no address given: whichever kind
        // the firm already holds for them, preferring what it can actually
        // send on.
        $addressType = $address === null
            ? $this->addressTypeFor($customer, $availability, $corporation)
            : AddressType::sniff($address);

        if ($addressType === null) {
            throw ValidationException::withMessages(['address' => __('inbox.not_an_address')]);
        }

        $message = $compose->handle(
            corporation: $corporation,
            addressType: $addressType,
            body: $request->validated('body'),
            address: $address,
            customer: $customer,
            author: $request->user(),
        );

        $conversation = $message->thread->identity->customer->conversation->fresh(['customer.identities', 'lastMessage.attachments']);

        return response()->json(['conversation' => $this->summary($conversation)], 201);
    }

    /**
     * Say who an unnamed conversation is with.
     *
     * That is a merge: the placeholder person the number belongs to folds
     * into the customer named here, taking their addresses and history. If
     * the customer was already talking to the firm the two exchanges become
     * one, so the surviving conversation comes back.
     */
    public function link(
        LinkConversationRequest $request,
        int $conversation,
        MergeCustomers $merge,
    ): JsonResponse {
        $corporation = $request->user()->corporation;

        $conversation = $corporation->conversations()->findOrFail($conversation);
        $customer = $corporation->customers()->findOrFail($request->validated('customerId'));

        $merge->handle($conversation->customer, $customer);

        // The survivor may be a different conversation than the one asked
        // about — the two people were folded into one — so the caller is told
        // where to go.
        $survivor = $customer->fresh()->conversation;

        return response()->json([
            'conversation' => $this->summary($survivor->fresh(['customer.identities', 'lastMessage.attachments'])),
        ]);
    }

    /**
     * A page of the timeline, walking backwards from `before`.
     *
     * The cursor is a message id, but the ordering is by time — a merge can
     * bring older messages in under newer ids — so it is resolved to that
     * message's (sent_at, id) pair and compared as one.
     *
     * `$threadId` narrows to one exchange, `$identityId` to everything on
     * one address. The caller has already checked both belong here.
     *
     * @return array{0: Collection<int, Message>, 1: bool}
     */
    private function timelinePage(
        Conversation $conversation,
        ?int $before,
        ?int $threadId = null,
        ?int $identityId = null,
    ): array {
        $perPage = 50;

        $query = $conversation->timeline()
            ->with(['user', 'attachments'])
            ->when($threadId !== null, fn ($q) => $q->where('thread_id', $threadId))
            ->when($identityId !== null, fn ($q) => $q->whereIn(
                'thread_id',
                Thread::query()->where('identity_id', $identityId)->select('id'),
            ))
            ->reorder()
            ->orderByDesc('sent_at')
            ->orderByDesc('id');

        if ($before !== null) {
            $cursor = Message::query()->find($before);

            if ($cursor !== null) {
                $query->where(fn ($q) => $q
                    ->where('sent_at', '<', $cursor->sent_at)
                    ->orWhere(fn ($q) => $q
                        ->where('sent_at', $cursor->sent_at)
                        ->where('id', '<', $cursor->id)));
            }
        }

        $page = $query->limit($perPage + 1)->get();

        return [
            $page->take($perPage)->reverse()->values(),
            $page->count() > $perPage,
        ];
    }

    /**
     * Which of a customer's addresses to write to when none was named.
     *
     * Prefers one the firm can actually send on; falls back to whatever it
     * holds so the refusal names the real reason rather than "no address".
     */
    private function addressTypeFor(
        ?Customer $customer,
        ChannelAvailability $availability,
        Corporation $corporation,
    ): ?AddressType {
        if ($customer === null) {
            return null;
        }

        $held = $customer->identities->pluck('address_type')->unique();

        return $held->first(
            fn (AddressType $type) => $availability->forCorporation($corporation, $type) === ChannelStatus::Active,
        ) ?? $held->first();
    }

    /**
     * The addresses to show in the header, each with what can be done on it.
     *
     * An address on a module the firm is switched off for is kept only if
     * something was actually said on it — that history is the reason to
     * still show it, greyed. A number on a switched-off module that nobody
     * ever used is just noise, so it is left out entirely.
     *
     * @return list<array<string, mixed>>
     */
    private function identities(
        Conversation $conversation,
        Corporation $corporation,
        ChannelAvailability $availability,
        ChannelRegistry $channels,
        User $reader,
    ): array {
        $addresses = $conversation->addresses();
        $withHistory = $this->identitiesWithHistory($addresses->modelKeys());
        $repliedOn = $this->identitiesWithHistory($addresses->modelKeys(), MessageDirection::Inbound);
        $unread = $this->unreadByIdentity($conversation, $reader, $addresses->modelKeys());

        return $addresses
            ->map(function (Identity $identity) use ($corporation, $availability, $channels, $withHistory, $repliedOn, $unread) {
                $hasHistory = in_array($identity->id, $withHistory, true);
                $moduleOn = $corporation->hasModule($identity->address_type->module());

                if (! $moduleOn && ! $hasHistory) {
                    return null;
                }

                // What the channel can do belongs to the *address*, not to
                // any thread on it — an address nobody has written to yet
                // still threads and still carries formatting, and reading
                // capabilities off an existing thread leaves a brand-new
                // mailbox looking like it can do nothing at all.
                $channel = $identity->address_type->defaultChannel();
                $capabilities = $channels->supports($channel) ? $channels->for($channel)->capabilities() : null;

                return [
                    'id' => $identity->id,
                    'addressType' => $identity->address_type->value,
                    'value' => $identity->value,
                    'status' => $availability->forIdentity($corporation, $identity, $hasHistory)->value,
                    // Which address the unread traffic is actually on, so
                    // somebody reachable two ways can see where to look.
                    'unreadCount' => $unread[$identity->id] ?? 0,
                    // Whether this person has ever written back on this
                    // specific address — not the same as `hasHistory`, which
                    // is also true for an address the firm has only ever
                    // sent to.
                    'hasReplied' => in_array($identity->id, $repliedOn, true),
                    'supportsRichText' => $capabilities?->supportsRichText ?? false,
                    'threadsByKey' => $capabilities?->threadsByKey ?? false,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Unread inbound messages per address, for one reader, in one query.
     *
     * Derived from the same `conversation_reads` watermark the queue count
     * uses — there is no per-address read state and deliberately so: opening
     * a conversation still marks the whole of it read. This only says where
     * the new traffic came in.
     *
     * @param  list<int>  $identityIds
     * @return array<int, int>
     */
    private function unreadByIdentity(Conversation $conversation, User $reader, array $identityIds): array
    {
        if ($identityIds === []) {
            return [];
        }

        $lastRead = DB::table('conversation_reads')
            ->where('conversation_id', $conversation->getKey())
            ->where('user_id', $reader->getKey())
            ->value('last_read_message_id');

        return DB::table('threads')
            ->join('messages', 'messages.thread_id', '=', 'threads.id')
            ->whereIn('threads.identity_id', $identityIds)
            ->whereNull('threads.deleted_at')
            ->where('messages.direction', MessageDirection::Inbound->value)
            ->when($lastRead !== null, fn ($query) => $query->where('messages.id', '>', $lastRead))
            ->groupBy('threads.identity_id')
            ->selectRaw('threads.identity_id as identity_id, count(messages.id) as unread')
            ->pluck('unread', 'identity_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * The exchanges inside this conversation, newest activity first.
     *
     * SMS gives every address exactly one, so this is a list of one until a
     * channel that threads arrives — but the shape is what the view selector
     * reads, and it is the same shape either way.
     *
     * @return list<array<string, mixed>>
     */
    private function threads(Conversation $conversation, ChannelRegistry $channels): array
    {
        if ($conversation->customer === null) {
            return [];
        }

        return $conversation->customer->threads()
            ->with('identity')
            ->withMax('messages as last_activity_at', 'sent_at')
            ->withCount('messages')
            ->get()
            ->sortByDesc('last_activity_at')
            ->values()
            ->map(function (Thread $thread) use ($channels) {
                $addressType = $thread->identity->address_type;
                // What the channel can do travels with the thread, so the
                // composer never has to infer it from the address type — the
                // adapter is the only thing that knows, and it says once.
                $capabilities = $channels->for($addressType->defaultChannel())->capabilities();

                return [
                    'id' => $thread->id,
                    'identityId' => $thread->identity_id,
                    // Null on SMS: a number is one unbroken exchange with
                    // nothing to name it.
                    'subject' => $thread->subject,
                    'lastActivityAt' => $thread->last_activity_at,
                    'messageCount' => (int) $thread->messages_count,
                    'addressType' => $addressType->value,
                    'supportsRichText' => $capabilities->supportsRichText,
                    'threadsByKey' => $capabilities->threadsByKey,
                ];
            })
            ->all();
    }

    /**
     * Which of these addresses anything has ever been said on, in one query
     * — or, with `$direction`, which have ever carried a message *that way*,
     * the same shape narrowed to answer "has this person ever replied here".
     *
     * @param  list<int>  $identityIds
     * @return list<int>
     */
    private function identitiesWithHistory(array $identityIds, ?MessageDirection $direction = null): array
    {
        if ($identityIds === []) {
            return [];
        }

        return DB::table('threads')
            ->join('messages', 'messages.thread_id', '=', 'threads.id')
            ->whereIn('threads.identity_id', $identityIds)
            ->whereNull('threads.deleted_at')
            ->when($direction !== null, fn ($query) => $query->where('messages.direction', $direction->value))
            ->distinct()
            ->pluck('threads.identity_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'displayName' => $conversation->display_name,
            'customerId' => $conversation->customer_id,
            'isAnonymous' => $conversation->isAnonymous(),
            'status' => $conversation->status->value,
            'lastMessageAt' => $conversation->last_message_at,
            'lastMessage' => $conversation->lastMessage
                ? $this->message($conversation->lastMessage)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function message(Message $message): array
    {
        return [
            'id' => $message->id,
            'channel' => $message->channel->value,
            'threadId' => $message->thread_id,
            'direction' => $message->direction->value,
            'body' => $message->body,
            // Present only where a channel produced a rich body. The UI
            // renders on the presence of this, never on which channel the
            // message came from — sanitized before it was ever stored.
            'bodyHtml' => $message->body_html,
            'sentAt' => $message->sent_at,
            'author' => $message->user ? [
                'id' => $message->user->id,
                'name' => $message->user->name,
            ] : null,
            'attachments' => $message->attachments->map(fn (MessageAttachment $attachment) => [
                'id' => $attachment->id,
                'contentType' => $attachment->content_type,
                'filename' => $attachment->filename,
                'byteSize' => $attachment->byte_size,
                'url' => "/api/attachments/{$attachment->id}",
            ])->all(),
        ];
    }
}
