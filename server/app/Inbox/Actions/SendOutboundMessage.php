<?php

namespace App\Inbox\Actions;

use App\Enums\MessageDirection;
use App\Inbox\ChannelRegistry;
use App\Inbox\Support\HtmlSanitizer;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use App\Support\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a message on a thread and records that it happened.
 *
 * Sending is synchronous. A queued send would need a worker watching it, and
 * a text that silently failed in a jobs table nobody reads is worse than one
 * that fails in front of the person who wrote it. The whole thing is wrapped
 * in a transaction so a refused send leaves no trace: the timeline must
 * never show a message to the customer that the customer never got.
 *
 * Channel-agnostic: which channel this goes out on is whatever the thread's
 * address speaks, and the adapter behind it does the rest.
 */
class SendOutboundMessage
{
    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    /**
     * `$bodyHtml` is what the composer produced, raw and untrusted. It is
     * sanitized here rather than at the edge because this is the one place
     * every send passes through, and because the plain-text `body` is
     * *derived* from it rather than taken from the caller: two versions of
     * the same message that disagree is worse than either one alone, and
     * the client has no business deciding what the searchable text says.
     */
    public function handle(Thread $thread, string $body, ?User $author = null, ?string $bodyHtml = null): Message
    {
        $addressType = $thread->identity->address_type;

        // Every send funnels through here, so this is where being switched
        // off actually bites — a bypassed UI, an old browser tab or a direct
        // API call all hit the same refusal.
        $module = $addressType->module();

        if (! $thread->corporation->hasModule($module)) {
            throw ValidationException::withMessages([
                'body' => __('inbox.module_not_enabled', ['module' => $module->value]),
            ]);
        }

        // The address decides what can reach it; a plain message goes out on
        // that address type's ordinary channel.
        $channel = $addressType->defaultChannel();
        $adapter = $this->channels->for($channel);

        $capabilities = $adapter->capabilities();

        // A channel that carries no formatting gets none: the markup would
        // otherwise arrive as literal tags in front of the customer. The
        // words survive either way, because the plain form is derived from
        // the rich one rather than being a separate thing to keep in step.
        if ($bodyHtml !== null) {
            $body = $this->sanitizer->toPlainText($bodyHtml);
            $bodyHtml = $capabilities->supportsRichText ? $this->sanitizer->sanitize($bodyHtml) : null;
        }

        if (trim($body) === '') {
            throw ValidationException::withMessages([
                'body' => __('inbox.nothing_to_send'),
            ]);
        }

        // How much fits in one message is the channel's business, so it is
        // checked here against what the adapter declares rather than being
        // guessed at by a form request that cannot know the channel.
        $limit = $capabilities->maxBodyLength;

        if ($limit !== null && mb_strlen($body) > $limit) {
            throw ValidationException::withMessages([
                'body' => __('inbox.message_too_long', ['channel' => $channel->value, 'limit' => $limit]),
            ]);
        }

        return DB::transaction(function () use ($thread, $body, $bodyHtml, $author, $channel, $adapter) {
            $message = $thread->messages()->create([
                'corporation_id' => $thread->corporation_id,
                'user_id' => $author?->id,
                'channel' => $channel,
                'direction' => MessageDirection::Outbound,
                'body' => $body,
                'body_html' => $bodyHtml,
                'sent_at' => now(),
            ]);

            $channelData = $adapter->send($message);

            if ($channelData !== []) {
                $message->update(['channel_data' => $channelData]);
            }

            $conversation = $thread->identity->customer->conversation;
            $conversation?->touchLastMessage($message);

            EventRecorder::record(
                $thread->corporation,
                'message.sent',
                subject: $message,
                actor: $author,
            );

            return $message;
        });
    }
}
