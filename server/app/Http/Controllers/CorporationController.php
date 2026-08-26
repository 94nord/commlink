<?php

namespace App\Http\Controllers;

use App\Support\EventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The corporation's own settings — currently just its logo. The name is set
 * once, at `app:create-corporation`, and has no edit endpoint: it is what
 * identifies the firm in every text and email, so changing it is not a
 * self-service form action.
 */
class CorporationController extends Controller
{
    public function edit(Request $request): JsonResponse
    {
        $corporation = $request->user()->corporation;

        return response()->json([
            'corporation' => [
                'name' => $corporation->name,
                'hasLogo' => $corporation->logo_path !== null,
            ],
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $corporation = $request->user()->corporation;

        $validated = $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        // Replace any previous logo so old objects don't accumulate.
        if ($corporation->logo_path !== null) {
            Storage::delete($corporation->logo_path);
        }

        $path = $validated['logo']->store("logos/{$corporation->id}");

        $corporation->update(['logo_path' => $path]);

        EventRecorder::record($corporation, 'corporation.updated', subject: $corporation);

        return response()->json(['hasLogo' => true]);
    }

    public function deleteLogo(Request $request): Response
    {
        $corporation = $request->user()->corporation;

        if ($corporation->logo_path !== null) {
            Storage::delete($corporation->logo_path);
            $corporation->update(['logo_path' => null]);

            EventRecorder::record($corporation, 'corporation.updated', subject: $corporation);
        }

        return response()->noContent();
    }

    /**
     * Stream the stored logo. Serving it through the app keeps it
     * env-independent — the S3/MinIO object never needs to be publicly
     * reachable from the browser (it isn't in dev).
     */
    public function logo(Request $request): StreamedResponse
    {
        $corporation = $request->user()->corporation;

        abort_if($corporation->logo_path === null || ! Storage::exists($corporation->logo_path), 404);

        return Storage::response($corporation->logo_path);
    }
}
