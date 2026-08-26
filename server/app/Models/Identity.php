<?php

namespace App\Models;

use App\Enums\AddressType;
use Database\Factories\IdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One address a person can be reached at: a phone number, later a mailbox.
 * It belongs to a customer — directly, because an address is a fact about a
 * person and not about whatever exchange happens to be going on.
 *
 * Keyed by the *kind* of address, not by transport. Everything that reaches
 * a phone number — a text today, WhatsApp or a voicemail later — resolves to
 * this one identity, so it all lands in one thread under one person instead
 * of fragmenting them across channels.
 *
 * Values arrive already normalized: the adapter that received the message
 * does that, because only it knows what canonical means for its channel.
 * Nothing in this model may learn the difference between a phone number and
 * a mailbox.
 */
class Identity extends Model
{
    /** @use HasFactory<IdentityFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'customer_id',
        'address_type',
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address_type' => AddressType::class,
        ];
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }
}
