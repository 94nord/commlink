<?php

namespace Tests\Feature\Inbox;

use App\Enums\AddressType;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\Identity;
use App\Models\Message;
use App\Models\Thread;
use App\Models\UploadLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Corporation $corporation;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $this->corporation = Corporation::factory()->texting('+4533221100')->create();
        $this->user = User::factory()->for($this->corporation)->create();
        $this->conversation = $this->conversationIn($this->corporation);
    }

    public function test_the_firm_mints_a_link_to_text_over()
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/conversations/{$this->conversation->id}/upload-link")
            ->assertCreated();

        $url = $response->json('link.url');
        $this->assertStringContainsString('/u/', $url);

        $token = str($url)->afterLast('/u/')->value();
        $link = UploadLink::sole();
        $this->assertSame(UploadLink::hash($token), $link->token_hash);
        $this->assertSame($this->user->id, $link->user_id);

        // The token is readable back by the firm, but not lying in the
        // column in the clear — that is what the encrypted cast is for.
        $this->assertSame($token, $link->token);
        $this->assertNotSame($token, $link->getRawOriginal('token'));
    }

    public function test_the_dialog_reads_back_the_link_it_already_handed_out()
    {
        $minted = $this->actingAs($this->user)
            ->postJson("/api/conversations/{$this->conversation->id}/upload-link")
            ->json('link.url');

        // Reopening must show the same URL, not quietly replace the one the
        // customer is already using.
        $this->actingAs($this->user)
            ->getJson("/api/conversations/{$this->conversation->id}/upload-link")
            ->assertOk()
            ->assertJsonPath('link.url', $minted);

        $this->assertSame(1, UploadLink::count());
    }

    public function test_there_is_no_link_until_one_is_generated()
    {
        $this->actingAs($this->user)
            ->getJson("/api/conversations/{$this->conversation->id}/upload-link")
            ->assertOk()
            ->assertJsonPath('link', null);
    }

    public function test_generating_a_new_link_closes_the_one_before_it()
    {
        $first = $this->linkFor($this->conversation);

        $this->actingAs($this->user)
            ->postJson("/api/conversations/{$this->conversation->id}/upload-link")
            ->assertCreated();

        // The old URL stops working the moment it is replaced.
        $this->getJson("/api/uploads/{$first}")->assertNotFound();
        $this->assertNotNull(UploadLink::where('token_hash', UploadLink::hash($first))->sole()->revoked_at);
    }

    public function test_a_revoked_link_is_refused_even_before_it_expires()
    {
        $token = $this->linkFor($this->conversation, ['revoked_at' => now()]);

        $this->getJson("/api/uploads/{$token}")->assertNotFound();
        $this->postJson("/api/uploads/{$token}", ['photos' => []])->assertNotFound();
    }

    public function test_a_guest_cannot_mint_one()
    {
        $this->postJson("/api/conversations/{$this->conversation->id}/upload-link")
            ->assertUnauthorized();
    }

    public function test_another_firms_conversation_is_not_ours_to_open()
    {
        $other = $this->conversationIn(Corporation::factory()->texting('+4533221101')->create());

        $this->actingAs($this->user)
            ->postJson("/api/conversations/{$other->id}/upload-link")
            ->assertNotFound();
    }

    public function test_the_page_says_who_is_asking_and_nothing_about_the_customer()
    {
        $token = $this->linkFor($this->conversation);

        $response = $this->getJson("/api/uploads/{$token}")
            ->assertOk()
            ->assertJsonPath('corporationName', $this->corporation->name);

        // Anyone holding a forwarded text can read this, so it must give up
        // nothing about the person it was sent to.
        $body = $response->getContent();
        $this->assertStringNotContainsString($this->conversation->customer->display_name, $body);
        $this->assertSame(['corporationName', 'expiresAt'], array_keys($response->json()));
    }

    public function test_an_unknown_and_an_expired_token_are_both_the_same_404()
    {
        $expired = $this->linkFor($this->conversation, ['expires_at' => now()->subMinute()]);

        foreach ([str_repeat('a', 40), $expired] as $token) {
            $this->getJson("/api/uploads/{$token}")->assertNotFound();
            $this->postJson("/api/uploads/{$token}", ['photos' => []])->assertNotFound();
        }
    }

    public function test_photos_arrive_as_one_message_from_the_customer()
    {
        $token = $this->linkFor($this->conversation);

        $this->postJson("/api/uploads/{$token}", [
            'photos' => [
                UploadedFile::fake()->image('leak.jpg'),
                UploadedFile::fake()->image('meter.jpg'),
            ],
        ])->assertCreated()->assertJson(fn ($json) => $json->has('corporationName')->has('expiresAt'));

        // Two pictures of one problem are one thing said, not two.
        $message = Message::has('attachments')->sole();
        $this->assertSame(MessageDirection::Inbound, $message->direction);
        $this->assertCount(2, $message->attachments);
        $this->assertSame('upload_link', $message->channel_data['source']);

        foreach ($message->attachments as $attachment) {
            Storage::assertExists($attachment->path);
            $this->assertSame('image/jpeg', $attachment->content_type);
        }

        $this->assertSame(2, UploadLink::sole()->upload_count);
        $this->assertSame($message->id, $this->conversation->fresh()->last_message_id);
        $this->assertDatabaseHas('events', ['type' => 'upload_link.used']);
    }

    public function test_the_firm_can_see_what_was_uploaded()
    {
        $token = $this->linkFor($this->conversation);
        $this->postJson("/api/uploads/{$token}", ['photos' => [UploadedFile::fake()->image('leak.jpg')]])
            ->assertCreated();

        $attachment = Message::has('attachments')->sole()->attachments()->sole();

        $this->actingAs($this->user)
            ->get("/api/attachments/{$attachment->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_pdf_is_accepted_alongside_photos()
    {
        $token = $this->linkFor($this->conversation);

        $this->postJson("/api/uploads/{$token}", [
            'photos' => [
                UploadedFile::fake()->image('leak.jpg'),
                UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf'),
            ],
        ])->assertCreated();

        $message = Message::has('attachments')->sole();
        $this->assertCount(2, $message->attachments);

        $pdf = $message->attachments()->where('content_type', 'application/pdf')->sole();
        $this->assertSame('invoice.pdf', $pdf->filename);
    }

    public function test_an_unsupported_file_type_is_refused()
    {
        $token = $this->linkFor($this->conversation);

        // Note this only proves the rules reject an unsupported type: a
        // faked upload takes its mime from the filename, so the content
        // sniffing the `mimes` rule does in production cannot be exercised
        // here.
        $this->postJson("/api/uploads/{$token}", [
            'photos' => [UploadedFile::fake()->create('app.exe', 10, 'application/x-msdownload')],
        ])->assertJsonValidationErrors('photos.0');

        $this->assertDatabaseCount('message_attachments', 0);
        $this->assertSame(0, UploadLink::sole()->upload_count);
    }

    public function test_a_submission_over_the_total_size_budget_is_refused()
    {
        config(['inbox.uploads.max_total_kilobytes' => 100]);
        $token = $this->linkFor($this->conversation);

        // Neither file is oversized on its own — only their sum is.
        $this->postJson("/api/uploads/{$token}", [
            'photos' => [
                UploadedFile::fake()->image('a.jpg')->size(60),
                UploadedFile::fake()->image('b.jpg')->size(60),
            ],
        ])->assertJsonValidationErrors('photos');

        $this->assertDatabaseCount('message_attachments', 0);
    }

    public function test_a_link_has_no_lifetime_total_only_a_per_submission_one()
    {
        // upload_count is tracked for the audit trail, but nothing gates on
        // it — a link that has already taken ten files must still take an
        // eleventh.
        $token = $this->linkFor($this->conversation, ['upload_count' => 10]);

        $this->postJson("/api/uploads/{$token}", ['photos' => [UploadedFile::fake()->image('eleventh.jpg')]])
            ->assertCreated();

        $this->getJson("/api/uploads/{$token}")->assertOk();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function linkFor(Conversation $conversation, array $attributes = []): string
    {
        $token = UploadLink::newToken();

        $conversation->corporation->uploadLinks()->create([
            'conversation_id' => $conversation->id,
            'user_id' => null,
            'token_hash' => UploadLink::hash($token),
            'token' => $token,
            'expires_at' => now()->addDays(7),
            ...$attributes,
        ]);

        return $token;
    }

    private function conversationIn(Corporation $corporation): Conversation
    {
        $customer = Customer::factory()->for($corporation)->create();

        $identity = Identity::factory()->create([
            'corporation_id' => $corporation->id,
            'customer_id' => $customer->id,
            'address_type' => AddressType::Phone,
            'value' => '+4520'.fake()->numerify('######'),
        ]);

        // A thread has to exist for a picture to have somewhere to land —
        // the customer has written before.
        $thread = Thread::factory()->create([
            'corporation_id' => $corporation->id,
            'identity_id' => $identity->id,
        ]);

        Message::factory()->create([
            'corporation_id' => $corporation->id,
            'thread_id' => $thread->id,
            'direction' => MessageDirection::Inbound,
        ]);

        return Conversation::factory()->create([
            'corporation_id' => $corporation->id,
            'customer_id' => $customer->id,
        ]);
    }
}
