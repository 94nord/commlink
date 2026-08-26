<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUploadedPhotosRequest;
use App\Inbox\Actions\StoreUploadedPhotos;
use App\Models\UploadLink;
use Illuminate\Http\JsonResponse;

/**
 * The customer's end of a photo link — the only way into this system that
 * asks for no account at all.
 *
 * Because anyone holding the link can open it, the page behind this is told
 * as little as possible: which firm is asking, and until when. Never who the
 * link was sent to, and never anything that was said in the conversation.
 */
class PhotoUploadController extends Controller
{
    public function show(UploadLink $uploadLink): JsonResponse
    {
        return response()->json($this->state($uploadLink));
    }

    public function store(
        StoreUploadedPhotosRequest $request,
        UploadLink $uploadLink,
        StoreUploadedPhotos $store,
    ): JsonResponse {
        $store->handle($uploadLink, array_values($request->file('photos')));

        return response()->json($this->state($uploadLink), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(UploadLink $link): array
    {
        return [
            'corporationName' => $link->corporation->name,
            'expiresAt' => $link->expires_at,
        ];
    }
}
