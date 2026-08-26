<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\MessageDirection;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single message that was actually sent or received. Append-only: no
 * updates, no soft deletes, no moving between threads. Everything the inbox
 * does above this row — linking a number to a customer, merging two of them,
 * unlinking a wrong guess — is arranged so this record never has to change.
 *
 * channel_data carries whatever the receiving or sending channel needs to
 * remember (for SMS: the from/to pair and the provider's message id). The
 * core reads none of it; only that channel's adapter does.
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'thread_id',
        'user_id',
        'channel',
        'direction',
        'body',
        'body_html',
        'channel_data',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'direction' => MessageDirection::class,
            'channel_data' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * The staff member who wrote an outbound message. Inbound messages come
     * from the customer and have none.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Files that arrived with this message — pictures from an MMS today.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function isInbound(): bool
    {
        return $this->direction === MessageDirection::Inbound;
    }
}
