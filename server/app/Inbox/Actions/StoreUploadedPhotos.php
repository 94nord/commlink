<?php

namespace App\Inbox\Actions;

use App\Enums\MessageDirection;
use App\Models\Message;
use App\Models\UploadLink;
use App\Support\EventRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Photos (and, since the link also takes PDFs, the odd invoice or
 * certificate) handed in through a texted link, filed as if the customer
 * had sent them — because they did. The link is only the door they came
 * through.
 *
 * One message per batch rather than one per file: somebody photographing a
 * leak from three angles said one thing, and three bubbles in the timeline
 * would misrepresent that.
 */
class StoreUploadedPhotos
{
    public function __construct(private readonly OpenConversation $open) {}

    /**
     * @param  list<UploadedFile>  $photos
     */
    public function handle(UploadLink $link, array $photos): Message
    {
        $conversation = $link->conversation;
        $corporation = $link->corporation;

        $thread = $conversation->replyThread();

        if ($thread === null) {
            // Nothing to hang a message on: no address has ever been used
            // here, so there is no thread and no channel that reached them.
            throw new RuntimeException("conversation {$conversation->id} has no thread to file photos in");
        }

        $message = DB::transaction(function () use ($corporation, $thread, $photos) {
            return $thread->messages()->create([
                'corporation_id' => $corporation->id,
                // The picture did not travel by SMS, but it belongs to the
                // same exchange with the same person, so it keeps the
                // thread's channel and says how it really arrived in
                // channel_data rather than inventing a transport with no
                // address space behind it.
                'channel' => $thread->identity->address_type->defaultChannel(),
                'direction' => MessageDirection::Inbound,
                'body' => '',
                'channel_data' => ['source' => 'upload_link', 'photo_count' => count($photos)],
                'sent_at' => now(),
            ]);
        });

        $this->storePhotos($message, $photos);

        $this->open->handle($conversation->customer)->touchLastMessage($message);

        $link->increment('upload_count', count($photos));

        EventRecorder::record($corporation, 'message.received', subject: $message);
        EventRecorder::record($corporation, 'upload_link.used', subject: $link, payload: [
            'message_id' => $message->id,
            'photo_count' => count($photos),
        ]);

        return $message;
    }

    /**
     * @param  list<UploadedFile>  $photos
     */
    private function storePhotos(Message $message, array $photos): void
    {
        foreach ($photos as $index => $photo) {
            $path = "attachments/{$message->corporation_id}/{$message->id}/{$index}";

            // Unlike an inbound MMS, nothing here is best-effort: the
            // customer is standing at the page waiting to be told it worked,
            // so a write that failed has to be an error they can see rather
            // than a picture that quietly never arrived.
            if (Storage::put($path, $photo->get()) === false) {
                throw new RuntimeException("could not write {$path}");
            }

            $message->attachments()->create([
                'corporation_id' => $message->corporation_id,
                'content_type' => $photo->getMimeType(),
                'filename' => $photo->getClientOriginalName(),
                'path' => $path,
                'byte_size' => $photo->getSize(),
            ]);
        }
    }
}
