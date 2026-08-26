<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\UploadLink;
use App\Support\EventRecorder;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asking a customer for photos.
 *
 * The firm's number usually cannot receive MMS, so this is how a picture
 * gets in: generate a link, send it, let them upload from their phone. One
 * live link per conversation — generating another closes the one before it,
 * so there is never a question of which URL is the current one.
 */
class UploadLinkController extends Controller
{
    /**
     * The conversation's current link, if it still has one.
     */
    public function show(Request $request, int $conversation): JsonResponse
    {
        $link = $this->current($this->conversation($request, $conversation));

        return response()->json(['link' => $link ? $this->present($link) : null]);
    }

    public function store(Request $request, int $conversation): JsonResponse
    {
        $conversation = $this->conversation($request, $conversation);
        $user = $request->user();

        // Only the newest link works. The customer is meant to be looking at
        // one URL, and leaving older ones alive would mean a link the office
        // believes it has replaced is still open.
        $replaced = 0;
        foreach ($conversation->uploadLinks()->usable()->get() as $previous) {
            $previous->revoke();
            $replaced++;
        }

        $token = UploadLink::newToken();

        $link = $conversation->corporation->uploadLinks()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'token_hash' => UploadLink::hash($token),
            'token' => $token,
            'expires_at' => now()->addDays((int) config('inbox.uploads.link_ttl_days')),
        ]);

        EventRecorder::record($user->corporation, 'upload_link.created', subject: $link, payload: [
            'replaced' => $replaced,
        ]);

        return response()->json(['link' => $this->present($link)], 201);
    }

    /**
     * Resolved through the corporation rather than bound from the route, so
     * another firm's conversation is a 404 and not an authorization question
     * asked later.
     */
    private function conversation(Request $request, int $conversation): Conversation
    {
        return $request->user()->corporation->conversations()->findOrFail($conversation);
    }

    private function current(Conversation $conversation): ?UploadLink
    {
        return $conversation->uploadLinks()
            ->usable()
            // A link minted before the token was kept cannot be shown again;
            // as far as the dialog is concerned there is none.
            ->whereNotNull('token')
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(UploadLink $link): array
    {
        return [
            'url' => FrontendUrl::to("/u/{$link->token}"),
            'expiresAt' => $link->expires_at,
        ];
    }
}
