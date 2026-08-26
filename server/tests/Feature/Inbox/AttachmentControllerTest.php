<?php

namespace Tests\Feature\Inbox;

use App\Enums\AddressType;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\Identity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Corporation $corporation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $this->corporation = Corporation::factory()->texting('+4533221100')->create();
        $this->user = User::factory()->for($this->corporation)->create();
    }

    public function test_it_streams_the_stored_picture()
    {
        $attachment = $this->attachmentIn($this->corporation, 'the-image-bytes');

        $response = $this->actingAs($this->user)
            ->get("/api/attachments/{$attachment->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->assertSame('the-image-bytes', $response->streamedContent());
    }

    public function test_a_guest_gets_nothing()
    {
        $attachment = $this->attachmentIn($this->corporation, 'the-image-bytes');

        $this->getJson("/api/attachments/{$attachment->id}")
            ->assertUnauthorized();
    }

    public function test_another_firms_picture_is_not_ours_to_serve()
    {
        $other = Corporation::factory()->texting('+4533221101')->create();
        $attachment = $this->attachmentIn($other, 'their-image-bytes');

        $this->actingAs($this->user)
            ->getJson("/api/attachments/{$attachment->id}")
            ->assertNotFound();
    }

    public function test_a_picture_is_reachable_without_knowing_its_message()
    {
        // The whole point of addressing a file on its own: a case pointing at
        // this picture holds no message id, and should not have to.
        $attachment = $this->attachmentIn($this->corporation, 'another-image');

        $response = $this->actingAs($this->user)
            ->get("/api/attachments/{$attachment->id}")
            ->assertOk();

        $this->assertSame('another-image', $response->streamedContent());
    }

    public function test_a_record_whose_file_is_gone_is_a_404_not_a_crash()
    {
        $attachment = $this->attachmentIn($this->corporation, 'the-image-bytes');
        Storage::delete($attachment->path);

        $this->actingAs($this->user)
            ->getJson("/api/attachments/{$attachment->id}")
            ->assertNotFound();
    }

    public function test_download_forces_a_save_even_for_an_image()
    {
        $attachment = $this->attachmentIn($this->corporation, 'the-image-bytes', filename: 'leak.jpg');

        $this->actingAs($this->user)
            ->get("/api/attachments/{$attachment->id}?download=1")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=leak.jpg')
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_non_image_is_always_served_as_a_download()
    {
        // Nothing in the app previews a PDF inline, so it is never handed
        // back as a same-origin response a link could point at instead of a
        // plain file save — no ?download=1 needed to force it.
        $attachment = $this->attachmentIn(
            $this->corporation,
            'the-pdf-bytes',
            contentType: 'application/pdf',
            filename: 'invoice.pdf',
        );

        $this->actingAs($this->user)
            ->get("/api/attachments/{$attachment->id}")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=invoice.pdf');
    }

    public function test_a_missing_filename_falls_back_to_a_generated_one()
    {
        // Inbound MMS attachments have no filename — Twilio gives only a
        // URL and content type.
        $attachment = $this->attachmentIn($this->corporation, 'the-image-bytes', filename: null);

        $response = $this->actingAs($this->user)->get("/api/attachments/{$attachment->id}?download=1")->assertOk();

        $this->assertStringContainsString("attachment-{$attachment->id}", $response->headers->get('Content-Disposition'));
    }

    private function attachmentIn(
        Corporation $corporation,
        string $bytes,
        string $contentType = 'image/jpeg',
        ?string $filename = 'photo.jpg',
    ): MessageAttachment {
        $customer = Customer::factory()->for($corporation)->unnamed()->create();

        $identity = Identity::factory()->create([
            'corporation_id' => $corporation->id,
            'customer_id' => $customer->id,
            'address_type' => AddressType::Phone,
            'value' => '+4520'.fake()->numerify('######'),
        ]);

        $thread = Thread::factory()->create([
            'corporation_id' => $corporation->id,
            'identity_id' => $identity->id,
        ]);

        $message = Message::factory()->create([
            'corporation_id' => $corporation->id,
            'thread_id' => $thread->id,
        ]);

        $path = "attachments/{$corporation->id}/{$message->id}/0";
        Storage::put($path, $bytes);

        return $message->attachments()->create([
            'corporation_id' => $corporation->id,
            'content_type' => $contentType,
            'filename' => $filename,
            'path' => $path,
            'byte_size' => strlen($bytes),
        ]);
    }
}
