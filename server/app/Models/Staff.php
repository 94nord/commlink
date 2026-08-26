<?php

namespace App\Models;

use App\Models\Concerns\HasExternalReference;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person who does the work — the future owner of bookable time (the shared
 * calendar per S1). Availability windows are a later slice: for now every
 * staff member is treated as available all the time, so slot computation has
 * nothing to intersect with yet.
 *
 * A corporation always has at least one staff member (S2): the founder is
 * provisioned at corporation creation, and the last one cannot be deleted.
 * The founder is additionally marked with `is_founder` — a stronger,
 * unconditional guard than "last admin"/"last staff member": that one row
 * can never be deleted or demoted, independent of how many other admins
 * exist.
 */
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasExternalReference, HasFactory, SoftDeletes;

    /**
     * Laravel would otherwise guess "staffs".
     *
     * @var string
     */
    protected $table = 'staff';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'user_id',
        'name',
        'email',
        'is_admin',
        'is_founder',
        'external_ref',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'is_founder' => 'boolean',
        ];
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    /**
     * The staff member's login, if one has been provisioned (D3: never
     * self-serve).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
