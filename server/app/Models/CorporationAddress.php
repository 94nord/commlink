<?php

namespace App\Models;

use App\Enums\AddressType;
use Database\Factories\CorporationAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One address the firm itself is reachable at — the number customers text,
 * later the mailbox they write to. The mirror of `Identity`, which is one
 * address a *customer* is reachable at.
 *
 * Keyed by the kind of address, never by transport: a number is one address
 * whether a text, a picture or a voicemail arrives on it.
 *
 * Values arrive already normalized, from whoever knows what canonical means
 * for that kind of address. Nothing in this model may learn the difference
 * between a phone number and a mailbox.
 */
class CorporationAddress extends Model
{
    /** @use HasFactory<CorporationAddressFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'address_type',
        'value',
        'is_primary',
        'verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address_type' => AddressType::class,
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }
}
