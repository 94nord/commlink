<?php

namespace App\Models;

use App\Enums\RoundStatus;
use App\Enums\VisitType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The batch-scheduler: opens booking for many visits of one type at once
 * (S3). Membership is an explicit set of cases (`round_work_orders`), not
 * pre-created visits — a visit only comes into existence when a customer
 * books (the self-booking slice will create it through ScheduleVisit and
 * stamp it with a round_id).
 *
 * Three dates do three jobs (S4): the window is which days the round is
 * offered in, staff availability is when someone is actually free, and
 * close-off is the booking/reschedule deadline. `project_id` is
 * organizational belonging only (SO8) — which project page the round lists
 * on — never membership: a round filed under a project may target any cases.
 */
class Round extends Model
{
    /** @use HasFactory<\Database\Factories\RoundFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'corporation_id',
        'project_id',
        'type',
        'status',
        'appointment_minutes',
        'buffer_minutes',
        'window_starts_on',
        'window_ends_on',
        'day_starts_at',
        'day_ends_at',
        'close_off_on',
        'scheduled_open_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VisitType::class,
            'status' => RoundStatus::class,
            'window_starts_on' => 'immutable_date',
            'window_ends_on' => 'immutable_date',
            'close_off_on' => 'immutable_date',
            'scheduled_open_at' => 'immutable_datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === RoundStatus::Draft;
    }

    public function isOpen(): bool
    {
        return $this->status === RoundStatus::Open;
    }

    public function isClosed(): bool
    {
        return $this->status === RoundStatus::Closed;
    }

    /**
     * Customers can book/reschedule while the round is open and the
     * close-off date has not passed (B2).
     */
    public function isBookable(): bool
    {
        return $this->isOpen() && $this->close_off_on->endOfDay()->isFuture();
    }

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workOrders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'round_work_orders')
            ->using(RoundWorkOrder::class)
            ->withPivot('id', 'token', 'invite_sent_at', 'invite_failed_at')
            ->withTimestamps();
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }
}
