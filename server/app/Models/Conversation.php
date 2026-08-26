<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * The team's state about one person: status now, ownership and watchers
 * later. It owns neither messages nor addresses — those belong to the
 * customer — which is why it can be created late and still be complete.
 *
 * It exists only once something has been said. A customer whose number is
 * merely written down has no conversation and is not an inbox item; the
 * first message in either direction brings one into being.
 *
 * The person may have no name yet. That is a first-class state, not a
 * defect: the firm reads and replies the whole time, and the conversation
 * shows the number until somebody says who it is.
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'customer_id',
        'owner_id',
        'status',
        'last_message_at',
        'last_message_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_message_at' => 'datetime',
        ];
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    /**
     * The person this conversation belongs to, once somebody has said who
     * that is. Null means anonymous.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Reserved for the later "take over" feature — never written yet.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The addresses this exchange happens over — the customer's, since an
     * address is a fact about a person rather than about a conversation.
     *
     * @return Collection<int, Identity>
     */
    public function addresses(): Collection
    {
        return $this->customer?->identities ?? collect();
    }

    /**
     * The unified timeline: everything said to or by this person, oldest
     * first, across every address and channel.
     *
     * @return Builder<Message>
     */
    public function timeline(): Builder
    {
        if ($this->customer === null) {
            return Message::query()->whereRaw('1 = 0');
        }

        return $this->customer->timeline();
    }

    /**
     * The newest message, denormalized so the inbox list can show a preview
     * per row without walking every thread. Always written together with
     * last_message_at.
     */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    /**
     * Record that this message is now the newest one here.
     */
    public function touchLastMessage(Message $message): void
    {
        $this->update([
            'last_message_at' => $message->sent_at,
            'last_message_id' => $message->getKey(),
        ]);
    }

    /**
     * Work out the newest message again from scratch. Needed after a merge
     * or an unlink, when the set of threads gathered here has changed and
     * the previous answer may have walked off to another conversation.
     */
    public function recomputeLastMessage(): void
    {
        $latest = $this->timeline()
            ->reorder()
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        $this->update([
            'last_message_at' => $latest?->sent_at,
            'last_message_id' => $latest?->getKey(),
        ]);
    }

    /**
     * Where a reply written now should go out.
     *
     * The customer's last inbound message decides it: answering them on the
     * thread they last wrote on is what makes the reply land in the right
     * place on their phone or in their mail client. With nothing inbound yet
     * — a conversation the firm started — the most recent thread is the only
     * candidate there is.
     */
    public function replyThread(): ?Thread
    {
        $threadIds = $this->customer?->threads()->pluck('threads.id') ?? collect();

        if ($threadIds->isEmpty()) {
            return null;
        }

        $latestInbound = Message::query()
            ->whereIn('thread_id', $threadIds)
            ->where('direction', MessageDirection::Inbound)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        if ($latestInbound !== null) {
            return $latestInbound->thread;
        }

        return Thread::query()->whereIn('id', $threadIds)->orderByDesc('id')->first();
    }

    /**
     * The photo upload links handed out for this exchange. Only the newest
     * live one is ever current; generating another closes the rest.
     */
    public function uploadLinks(): HasMany
    {
        return $this->hasMany(UploadLink::class);
    }

    /**
     * Note that everything said here has now been seen by this person.
     *
     * The marker is the newest message, so anything that arrives afterwards
     * counts as unread again.
     */
    public function markReadBy(User $user): void
    {
        ConversationRead::updateOrCreate(
            ['conversation_id' => $this->getKey(), 'user_id' => $user->getKey()],
            ['corporation_id' => $this->corporation_id, 'last_read_message_id' => $this->last_message_id],
        );
    }

    /**
     * How many messages *from the customer* this person has not seen.
     *
     * Only inbound: a colleague's reply is not something you have to catch
     * up on, and counting it would leave the whole firm looking behind
     * whenever anybody answered anything.
     */
    public function unreadCountFor(User $user): int
    {
        $marker = ConversationRead::query()
            ->where('conversation_id', $this->getKey())
            ->where('user_id', $user->getKey())
            ->value('last_read_message_id');

        return $this->timeline()
            ->reorder()
            ->where('direction', MessageDirection::Inbound)
            ->when($marker !== null, fn ($query) => $query->where('messages.id', '>', $marker))
            ->count();
    }

    /**
     * Nobody has said who this is yet.
     */
    public function isAnonymous(): bool
    {
        return ! ($this->customer?->isNamed() ?? false);
    }

    /**
     * What the inbox calls this conversation — the person's name once known,
     * otherwise the address they were first reached at.
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->customer?->display_name,
        );
    }
}
