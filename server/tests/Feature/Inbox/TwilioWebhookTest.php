<?php

namespace Tests\Feature\Inbox;

use App\Enums\AddressType;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Corporation;
use App\Models\Event;
use App\Models\Identity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Thread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

class TwilioWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://localhost/api/webhooks/twilio/sms';

    private const TOKEN = 'twilio-test-auth-token';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.twilio.token' => self::TOKEN]);
    }

    public function test_a_text_from_an_unknown_number_opens_an_anonymous_conversation()
    {
        $corporation = $this->corporationWithNumber('+4533221100');

        $this->receive($corporation, from: '+4520123456', body: 'My radiator is leaking.')
            ->assertOk();

        $identity = Identity::sole();
        $this->assertSame('+4520123456', $identity->value);
        $this->assertSame(AddressType::Phone, $identity->address_type);
        $this->assertSame($corporation->id, $identity->corporation_id);

        $conversation = Conversation::sole();
        $this->assertTrue($conversation->isAnonymous());
        $this->assertSame('+4520123456', $conversation->display_name);

        $message = Message::sole();
        $this->assertSame('My radiator is leaking.', $message->body);
        $this->assertSame(MessageDirection::Inbound, $message->direction);
        $this->assertNull($message->user_id);
        $this->assertSame('SM1234567890', $message->channel_data['message_sid']);

        $this->assertNotNull($conversation->last_message_at);
        $this->assertNotNull(Event::where('type', 'message.received')->first());
        $this->assertNotNull(Event::where('type', 'conversation.started')->first());
    }

    public function test_a_second_text_lands_in_the_same_thread()
    {
        $corporation = $this->corporationWithNumber('+4533221100');

        $this->receive($corporation, from: '+4520123456', body: 'First')->assertOk();
        $this->receive($corporation, from: '+4520123456', body: 'Second')->assertOk();

        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, Identity::count());
        $this->assertSame(1, Thread::count());
        $this->assertSame(2, Message::count());
    }

    public function test_a_number_written_in_local_form_matches_the_address_we_already_hold()
    {
        $corporation = $this->corporationWithNumber('+4533221100');

        $this->receive($corporation, from: '+4520123456', body: 'First')->assertOk();
        // Same person, written the way a human would.
        $this->receive($corporation, from: '20123456', body: 'Second')->assertOk();

        $this->assertSame(1, Identity::count());
        $this->assertSame(2, Message::count());
    }

    public function test_the_same_number_texting_two_firms_stays_two_conversations()
    {
        $first = $this->corporationWithNumber('+4533221100');
        $second = $this->corporationWithNumber('+4533221101');

        $this->receive($first, from: '+4520123456', body: 'To the first firm')->assertOk();
        $this->receive($second, from: '+4520123456', body: 'To the second firm')->assertOk();

        $this->assertSame(2, Conversation::count());
        $this->assertSame(1, $first->conversations()->count());
        $this->assertSame(1, $second->conversations()->count());
    }

    public function test_a_text_to_a_number_we_do_not_own_is_not_ours_to_accept()
    {
        $this->corporationWithNumber('+4533221100');

        $this->post(self::URL, $this->payload('+4599999999', '+4520123456', 'Hello?'), [
            'X-Twilio-Signature' => $this->signature($this->payload('+4599999999', '+4520123456', 'Hello?')),
        ])->assertNotFound();

        $this->assertSame(0, Message::count());
    }

    public function test_an_unsigned_request_is_rejected()
    {
        $corporation = $this->corporationWithNumber('+4533221100');

        $this->post(self::URL, $this->payload($this->numberOf($corporation), '+4520123456', 'Let me in'))
            ->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_a_forged_signature_is_rejected()
    {
        $corporation = $this->corporationWithNumber('+4533221100');
        $payload = $this->payload($this->numberOf($corporation), '+4520123456', 'Let me in');

        $this->post(self::URL, $payload, [
            'X-Twilio-Signature' => (new RequestValidator('the-wrong-token'))->computeSignature(self::URL, $payload),
        ])->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_a_signature_covering_different_content_is_rejected()
    {
        $corporation = $this->corporationWithNumber('+4533221100');
        $signed = $this->payload($this->numberOf($corporation), '+4520123456', 'The message that was signed');
        $tampered = $this->payload($this->numberOf($corporation), '+4520123456', 'The message that was sent');

        $this->post(self::URL, $tampered, ['X-Twilio-Signature' => $this->signature($signed)])
            ->assertForbidden();
    }

    public function test_it_refuses_everything_when_no_auth_token_is_configured()
    {
        config(['services.twilio.token' => null]);
        $corporation = $this->corporationWithNumber('+4533221100');
        $payload = $this->payload($this->numberOf($corporation), '+4520123456', 'Hello');

        $this->post(self::URL, $payload, ['X-Twilio-Signature' => $this->signature($payload)])
            ->assertForbidden();
    }

    public function test_a_sender_that_is_not_a_usable_number_is_dropped()
    {
        $corporation = $this->corporationWithNumber('+4533221100');

        $this->receive($corporation, from: 'not-a-number', body: 'Hello')
            ->assertStatus(422);

        $this->assertSame(0, Message::count());
    }

    public function test_the_signature_is_checked_against_the_configured_public_url()
    {
        // What the app sees is not what Twilio dialled, which is the normal
        // situation behind a TLS-terminating proxy.
        $publicUrl = 'https://api.example.test/api/webhooks/twilio/sms';
        config(['inbox.sms.webhook_url' => $publicUrl]);

        $corporation = $this->corporationWithNumber('+4533221100');
        $payload = $this->payload($this->numberOf($corporation), '+4520123456', 'Hello');

        $this->post(self::URL, $payload, ['X-Twilio-Signature' => $this->signature($payload)])
            ->assertForbidden();

        $this->post(self::URL, $payload, [
            'X-Twilio-Signature' => (new RequestValidator(self::TOKEN))->computeSignature($publicUrl, $payload),
        ])->assertOk();
    }

    public function test_a_picture_texted_in_is_fetched_and_kept()
    {
        Storage::fake();
        Http::fake(['*' => Http::response('the-image-bytes', 200)]);
        $corporation = $this->corporationWithNumber('+4533221100');

        $this->receive($corporation, from: '+4520123456', body: 'Looks like this', media: [
            ['url' => 'https://api.twilio.com/media/ME1', 'contentType' => 'image/jpeg'],
        ])->assertOk();

        $attachment = MessageAttachment::sole();
        $this->assertSame('image/jpeg', $attachment->content_type);
        $this->assertSame(strlen('the-image-bytes'), $attachment->byte_size);
        $this->assertSame($corporation->id, $attachment->corporation_id);
        $this->assertSame(Message::sole()->id, $attachment->message_id);
        Storage::assertExists($attachment->path);
        $this->assertSame('the-image-bytes', Storage::get($attachment->path));

        // The picture is fetched with the account credentials, since Twilio's
        // media URL is not public.
        Http::assertSent(fn ($request) => $request->url() === 'https://api.twilio.com/media/ME1'
            && $request->hasHeader('Authorization'));
    }

    public function test_several_pictures_in_one_text_are_all_kept()
    {
        Storage::fake();
        Http::fake(['*' => Http::response('bytes', 200)]);
        $corporation = $this->corporationWithNumber('+4533221100');

        $this->receive($corporation, from: '+4520123456', body: '', media: [
            ['url' => 'https://api.twilio.com/media/ME1', 'contentType' => 'image/jpeg'],
            ['url' => 'https://api.twilio.com/media/ME2', 'contentType' => 'image/png'],
        ])->assertOk();

        $this->assertSame(2, MessageAttachment::count());
        $this->assertSame(1, Message::count());
    }

    public function test_media_that_is_not_a_picture_is_ignored()
    {
        Storage::fake();
        Http::fake();
        $corporation = $this->corporationWithNumber('+4533221100');

        $this->receive($corporation, from: '+4520123456', body: 'My card', media: [
            ['url' => 'https://api.twilio.com/media/ME1', 'contentType' => 'text/x-vcard'],
        ])->assertOk();

        // The text still arrives; only the attachment is dropped.
        $this->assertSame('My card', Message::sole()->body);
        $this->assertSame(0, MessageAttachment::count());
        Http::assertNothingSent();
    }

    public function test_a_picture_that_cannot_be_fetched_does_not_lose_the_message()
    {
        Storage::fake();
        Http::fake(['*' => Http::response('nope', 500)]);
        $corporation = $this->corporationWithNumber('+4533221100');

        $this->receive($corporation, from: '+4520123456', body: 'Looks like this', media: [
            ['url' => 'https://api.twilio.com/media/ME1', 'contentType' => 'image/jpeg'],
        ])->assertOk();

        $this->assertSame('Looks like this', Message::sole()->body);
        $this->assertSame(0, MessageAttachment::count());
    }

    public function test_a_picture_that_cannot_be_written_leaves_no_row_behind()
    {
        Http::fake(['*' => Http::response('the-image-bytes', 200)]);
        $corporation = $this->corporationWithNumber('+4533221100');

        // Storage that accepts the call and quietly does nothing — what a
        // misconfigured bucket actually looks like.
        Storage::shouldReceive('put')->andReturn(false);

        $this->receive($corporation, from: '+4520123456', body: 'Looks like this', media: [
            ['url' => 'https://api.twilio.com/media/ME1', 'contentType' => 'image/jpeg'],
        ])->assertOk();

        $this->assertSame('Looks like this', Message::sole()->body);
        $this->assertSame(0, MessageAttachment::count());
    }

    private function numberOf(Corporation $corporation): string
    {
        return $corporation->primaryAddress(AddressType::Phone)->value;
    }

    private function corporationWithNumber(string $number): Corporation
    {
        return Corporation::factory()->texting($number)->create();
    }

    /**
     * @param  list<array{url: string, contentType: string}>  $media
     * @return array<string, string>
     */
    private function payload(string $to, string $from, string $body, array $media = []): array
    {
        $payload = [
            'MessageSid' => 'SM1234567890',
            'AccountSid' => 'AC1234567890',
            'From' => $from,
            'To' => $to,
            'Body' => $body,
        ];

        if ($media !== []) {
            $payload['NumMedia'] = (string) count($media);

            foreach (array_values($media) as $i => $item) {
                $payload["MediaUrl{$i}"] = $item['url'];
                $payload["MediaContentType{$i}"] = $item['contentType'];
            }
        }

        return $payload;
    }

    private function signature(array $payload): string
    {
        return (new RequestValidator(self::TOKEN))->computeSignature(self::URL, $payload);
    }

    /**
     * @param  list<array{url: string, contentType: string}>  $media
     */
    private function receive(Corporation $corporation, string $from, string $body, array $media = []): TestResponse
    {
        $payload = $this->payload($this->numberOf($corporation), $from, $body, $media);

        return $this->post(self::URL, $payload, [
            'X-Twilio-Signature' => $this->signature($payload),
        ]);
    }
}
