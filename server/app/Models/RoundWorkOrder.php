<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A round's membership row — one targeted case, and the recipient of one
 * booking link. Carries the magic-link `token` (A3: unguessable, scoped to
 * exactly this case in this round). Per-recipient sendout status (SO4) will
 * land here with the emails slice.
 */
class RoundWorkOrder extends Pivot
{
    protected $table = 'round_work_orders';

    public $incrementing = true;

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
