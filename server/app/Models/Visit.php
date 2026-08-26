<?php

namespace App\Models;

use App\Enums\VisitType;
use App\Models\Concerns\HasExternalReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A typed appointment — the thing a customer books. Always has a staff member
 * and times at creation; there is no "unscheduled" state. Carries three links:
 * owner (whose visit — polymorphic, so a Lead can own one later without
 * migration), work order (what case it's about — optional, late-bound), and
 * later a round (was it batch-scheduled — added with the Rounds slice).
 *
 * Cancellation is a state (`cancelled_at`), not row erasure: a cancelled
 * visit stays visible as history and releases its staff time. Scheduling
 * must go through App\Actions\ScheduleVisit — the only path that may occupy
 * staff time, because it holds the per-staff no-double-book invariant (S1).
 */
class Visit extends Model
{
    /** @use HasFactory<\Database\Factories\VisitFactory> */
    use HasExternalReference, HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'owner_type',
        'owner_id',
        'work_order_id',
        'round_id',
        'staff_id',
        'type',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'external_ref',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VisitType::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * The customer's self-management link — only exists for a round visit,
     * through its membership's magic-link token (A1/A3). Null for
     * standalone visits: those are admin-managed only.
     */
    public function bookingUrl(): ?string
    {
        if ($this->round_id === null || $this->work_order_id === null) {
            return null;
        }

        $token = RoundWorkOrder::query()
            ->where('round_id', $this->round_id)
            ->where('work_order_id', $this->work_order_id)
            ->value('token');

        return $token === null ? null : route('booking.show', $token);
    }

    /**
     * Visits that occupy staff time: not cancelled. The no-double-book check
     * runs against exactly this set.
     */
    public function scopeOccupying(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
