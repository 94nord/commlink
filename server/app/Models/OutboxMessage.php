<?php

namespace App\Models;

use App\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued email (X1 outbox). `type` picks the Mailable
 * (invite/booked/changed/cancelled); `payload` carries the ids the Mailable
 * needs to rebuild its content at send time. Provider-agnostic: swapping
 * the mail transport never touches these rows.
 */
class OutboxMessage extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'type',
        'recipient_email',
        'payload',
        'status',
        'error',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', OutboxStatus::Pending);
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }
}
