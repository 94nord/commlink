<?php

namespace App\Support;

use App\Models\Corporation;
use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The single write-path to the central event log (X1). State changes call
 * record() explicitly; future consumers (notifications, connectors, outbox)
 * subscribe on top of the log.
 */
class EventRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        Corporation $corporation,
        string $type,
        ?Model $subject = null,
        array $payload = [],
        ?Model $actor = null,
    ): Event {
        $actor ??= Auth::user();

        return Event::create([
            'corporation_id' => $corporation->id,
            'type' => $type,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'payload' => $payload !== [] ? $payload : null,
        ]);
    }
}
