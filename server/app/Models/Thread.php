<?php

namespace App\Models;

use Database\Factories\ThreadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One continuous exchange on one identity. SMS keeps a single default thread
 * per number (null external_key); channels that really do thread put their
 * own discriminator in external_key and get several.
 *
 * Replies are built from a thread, not from a conversation, which is what
 * keeps an answer landing where the customer expects it.
 */
class Thread extends Model
{
    /** @use HasFactory<ThreadFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'identity_id',
        'external_key',
        'subject',
        'corporation_address_id',
    ];

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class);
    }

    /**
     * The firm's own address this exchange goes out from.
     *
     * Bound when the thread is created and never resolved again: changing
     * which address new exchanges start from must not move one already under
     * way, or a customer's reply would go somewhere they never wrote to.
     * Null on threads that predate the binding, which fall back to the
     * corporation's primary address for their kind.
     */
    public function corporationAddress(): BelongsTo
    {
        return $this->belongsTo(CorporationAddress::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
