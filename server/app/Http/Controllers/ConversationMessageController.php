<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConversationMessageRequest;
use App\Inbox\Actions\SendOutboundMessage;
use App\Inbox\ChannelRegistry;
use App\Models\Conversation;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Replying in a conversation.
 *
 * Which address the reply goes out on is not the writer's decision: it is
 * whichever thread the customer last wrote on, so the answer arrives where
 * they are expecting it. See Conversation::replyThread().
 */
class ConversationMessageController extends Controller
{
    public function __construct(private readonly ChannelRegistry $channels) {}

    public function store(
        StoreConversationMessageRequest $request,
        int $conversation,
        SendOutboundMessage $send,
    ): JsonResponse {
        $conversation = $request->user()->corporation->conversations()->findOrFail($conversation);

        $thread = $this->targetThread($request, $conversation);

        if ($thread === null) {
            throw ValidationException::withMessages([
                'body' => __('inbox.no_address_to_reply_to'),
            ]);
        }

        $message = $send->handle(
            $thread,
            $request->validated('body'),
            $request->user(),
            $request->validated('bodyHtml'),
        );

        return response()->json([
            'message' => [
                'id' => $message->id,
                'channel' => $message->channel->value,
                'direction' => $message->direction->value,
                'body' => $message->body,
                'bodyHtml' => $message->body_html,
                'sentAt' => $message->sent_at,
                'threadId' => $message->thread_id,
                'author' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                ],
            ],
        ], 201);
    }

    /**
     * Which exchange the reply goes into.
     *
     * Three cases, in the order the composer offers them: a named existing
     * thread, a deliberately new one, or — when the writer said nothing —
     * whichever the customer last wrote on, which is where they are
     * expecting the answer.
     *
     * A named thread is resolved *through this conversation*, never by id
     * alone: an id belonging to another firm must not be answerable.
     */
    private function targetThread(StoreConversationMessageRequest $request, Conversation $conversation): ?Thread
    {
        $threadId = $request->validated('threadId');

        if ($threadId !== null) {
            $thread = $conversation->customer?->threads()->find($threadId);

            if ($thread === null) {
                abort(404);
            }

            return $thread;
        }

        if ($request->validated('newThread')) {
            return $this->startThread($request, $conversation);
        }

        return $conversation->replyThread();
    }

    /**
     * A new exchange with the same person, on the address the last one used.
     *
     * Only channels that thread can do this — on SMS a number *is* the
     * exchange, and a second thread on it would be an inbox entry the
     * customer's phone has no way of showing as separate.
     */
    private function startThread(StoreConversationMessageRequest $request, Conversation $conversation): Thread
    {
        $identityId = $request->validated('identityId');

        // Resolved through the conversation's own addresses, never the id
        // alone — starting an exchange on somebody else's mailbox would put
        // this firm's words in front of the wrong person.
        $identity = $identityId === null
            ? $conversation->replyThread()?->identity
            : $conversation->addresses()->firstWhere('id', $identityId);

        if ($identityId !== null && $identity === null) {
            abort(404);
        }

        $previous = $identity?->threads()->latest('id')->first();

        if ($identity === null) {
            throw ValidationException::withMessages([
                'body' => __('inbox.no_address_to_reply_to'),
            ]);
        }

        if (! $this->channels->for($identity->address_type->defaultChannel())->capabilities()->threadsByKey) {
            throw ValidationException::withMessages([
                'subject' => __('inbox.channel_has_one_thread'),
            ]);
        }

        return $identity->threads()->create([
            'corporation_id' => $conversation->corporation_id,
            'external_key' => (string) Str::uuid(),
            'subject' => $request->validated('subject'),
            'corporation_address_id' => $previous->corporation_address_id
                ?? $conversation->corporation->primaryAddress($identity->address_type)?->id,
        ]);
    }
}
