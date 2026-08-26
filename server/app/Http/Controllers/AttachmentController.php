<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a file the firm holds.
 *
 * Addressed by itself rather than through the message it arrived with: a
 * picture is one file, and the thread is only where it was first seen. Once
 * a case can point at the same picture, a URL that named the message would
 * make every other viewer look up an id it has no reason to know.
 *
 * Streamed through the app rather than handed out as a bucket URL, the same
 * way a corporation's logo is: the stored object never has to be publicly
 * reachable, and the firm's own auth decides who sees a customer's photo.
 * Scoping is by corporation, which every attachment carries — the same
 * tenant boundary the nested route enforced, one step shorter.
 */
class AttachmentController extends Controller
{
    public function __invoke(Request $request, int $attachment): StreamedResponse
    {
        $attachment = $request->user()->corporation->attachments()->findOrFail($attachment);

        // fileExists, not exists: the latter falls back to probing for a
        // directory of that name, which S3-compatible storage cannot answer
        // and raises rather than returning false.
        abort_if(! Storage::fileExists($attachment->path), 404);

        // A non-image is never rendered inline, download=1 or not: nothing in
        // this app previews a PDF in the browser, so there is no reason to
        // let one load as a same-origin response a link could point at
        // instead of a plain file save.
        $forceDownload = $request->boolean('download') || ! str_starts_with($attachment->content_type, 'image/');

        if ($forceDownload) {
            return Storage::download(
                $attachment->path,
                $attachment->filename ?? "attachment-{$attachment->id}",
                ['Content-Type' => $attachment->content_type],
            );
        }

        return Storage::response($attachment->path, headers: [
            'Content-Type' => $attachment->content_type,
        ]);
    }
}
