<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Everyone in a corporation is an admin with the same view of the inbox
// (D2) — one channel per corporation, not per conversation.
Broadcast::channel('corporation.{corporationId}.inbox', function (User $user, int $corporationId) {
    return $user->corporation_id === $corporationId;
});
