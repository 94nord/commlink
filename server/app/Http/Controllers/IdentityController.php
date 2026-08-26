<?php

namespace App\Http\Controllers;

use App\Inbox\Actions\UnlinkIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Correcting who an address belongs to.
 */
class IdentityController extends Controller
{
    /**
     * Take one address off the person it is filed under. Its history goes
     * with it, to somebody nobody has named — which is what comes back, along
     * with their conversation if there was anything to carry over.
     */
    public function unlink(Request $request, int $identity, UnlinkIdentity $unlink): JsonResponse
    {
        $identity = $request->user()->corporation->identities()->findOrFail($identity);

        $customer = $unlink->handle($identity);
        $conversation = $customer->conversation;

        return response()->json([
            'customerId' => $customer->id,
            'conversation' => $conversation === null ? null : [
                'id' => $conversation->id,
                'displayName' => $customer->display_name,
                'customerId' => $customer->id,
                'isAnonymous' => ! $customer->isNamed(),
                'status' => $conversation->status->value,
                'lastMessageAt' => $conversation->last_message_at,
            ],
        ]);
    }
}
