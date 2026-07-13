<?php

namespace App\Http\Controllers;

use App\Support\EventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class CorporationController extends Controller
{
    public function edit(Request $request): Response
    {
        $corporation = $request->user()->corporation;

        return Inertia::render('corporation/edit', [
            'corporation' => [
                'name' => $corporation->name,
                'hasLogo' => $corporation->logo_path !== null,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $corporation = $request->user()->corporation;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $corporation->update(['name' => $validated['name']]);

        EventRecorder::record($corporation, 'corporation.updated', subject: $corporation);

        return back();
    }

    public function uploadLogo(Request $request): RedirectResponse
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

        return back();
    }

    public function deleteLogo(Request $request): RedirectResponse
    {
        $corporation = $request->user()->corporation;

        if ($corporation->logo_path !== null) {
            Storage::delete($corporation->logo_path);
            $corporation->update(['logo_path' => null]);

            EventRecorder::record($corporation, 'corporation.updated', subject: $corporation);
        }

        return back();
    }

    /**
     * Stream the stored logo for the settings preview. Serving it through the
     * app keeps it env-independent — the S3/MinIO object never needs to be
     * publicly reachable from the browser (it isn't in dev).
     */
    public function logo(Request $request): StreamedResponse
    {
        $corporation = $request->user()->corporation;

        abort_if($corporation->logo_path === null || ! Storage::exists($corporation->logo_path), 404);

        return Storage::response($corporation->logo_path);
    }
}
