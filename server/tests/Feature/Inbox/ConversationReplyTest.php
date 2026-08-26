<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Inbox\ChannelRegistry;
use App\Inbox\Sms\Transport\SmsTransport;
use App\Models\Conversation;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Identity;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationReplyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Corporation $corporation;

    /** @var object{sent: list<array{0: string, 1: string, 2: string}>} */
    private object $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->corporation = Corporation::factory()->texting('+4533221100')->create();
        $this->user = User::factory()->for($this->corporation)->create();

        $this->transport = new class implements SmsTransport
        {
            /** @var list<array{0: string, 1: string, 2: string}> */
            public array $sent = [];

            public function send(string $from, string $to, string $body): ?string
            {
                $this->sent[] = [$from, $to, $body];

                return 'SMfake';
            }
        };

        $this->app->instance(SmsTransport::class, $this->transport);
        $this->app->forgetInstance(ChannelRegistry::class);
    }

    public function test_a_reply_goes_out_and_joins_the_timeline()
    {
        $conversation = $this->conversation();
        $this->inbound($conversation->addresses()->first(), 'Kan I komme?');

        $this->actingAs($this->user)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Ja, i morgen kl 9'])
            ->assertCreated()
            ->assertJsonPath('message.direction', 'outbound')
            ->assertJsonPath('message.body', 'Ja, i morgen kl 9')
            ->assertJsonPath('message.author.name', $this->user->name);

        $this->assertSame(['+4533221100', '+4520000001', 'Ja, i morgen kl 9'], $this->transport->sent[0]);
        $this->assertNotNull(Event::where('type', 'message.sent')->first());
        $this->assertSame(2, $conversation->timeline()->count());
    }

    public function test_a_reply_goes_to_the_address_the_customer_last_wrote_from()
    {
        $conversation = $this->conversation();
        $mobile = $conversation->addresses()->first();
        $landline = Identity::factory()->create([
            'corporation_id' => $this->corporation->id,
            'customer_id' => $conversation->customer_id,
            'value' => '+4520000002',
        ]);

        $this->inbound($mobile, 'From the mobile', hoursAgo: 3);
        $this->inbound($landline, 'From the other phone', hoursAgo: 1);

        $this->actingAs($this->user)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Svar'])
            ->assertCreated();

        // Answer where they last spoke from, not wherever the conversation
        // happened to start.
        $this->assertSame('+4520000002', $this->transport->sent[0][1]);
    }

    public function test_an_outbound_message_does_not_move_the_reply_target()
    {
        $conversation = $this->conversation();
        $mobile = $conversation->addresses()->first();
        $other = Identity::factory()->create([
            'corporation_id' => $this->corporation->id,
            'customer_id' => $conversation->customer_id,
            'value' => '+4520000002',
        ]);

        $this->inbound($mobile, 'Customer wrote here', hoursAgo: 3);
        // Something the firm sent on the other address, more recently.
        Message::factory()->outbound($this->user)->create([
            'corporation_id' => $this->corporation->id,
            'thread_id' => $this->threadFor($other)->id,
            'sent_at' => now()->subHour(),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Svar'])
            ->assertCreated();

        $this->assertSame('+4520000001', $this->transport->sent[0][1]);
    }

    public function test_with_nothing_inbound_the_reply_uses_the_only_thread_there_is()
    {
        $conversation = $this->conversation();

        $this->actingAs($this->user)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Vi prover igen'])
            ->assertCreated();

        $this->assertSame('+4520000001', $this->transport->sent[0][1]);
    }

    public function test_a_conversation_with_no_address_left_cannot_be_replied_to()
    {
        $conversation = Conversation::factory()->create([
            'corporation_id' => $this->corporation->id,
            'customer_id' => Customer::factory()->for($this->corporation)->create()->id,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Hej?'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_a_reply_needs_a_body()
    {
        $conversation = $this->conversation();

        $this->actingAs($this->user)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_a_reply_too_long_for_the_channel_is_refused()
    {
        $conversation = $this->conversation();

        $this->actingAs($this->user)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'body' => str_repeat('a', 1601),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertSame([], $this->transport->sent);
    }

    private function conversation(): Conversation
    {
        $customer = Customer::factory()->for($this->corporation)->unnamed()->create();

        $identity = Identity::factory()->create([
            'corporation_id' => $this->corporation->id,
            'customer_id' => $customer->id,
            'value' => '+4520000001',
        ]);

        // Every address the app itself creates gets its thread at the same
        // moment, so a fixture without one is not a state that can occur.
        $this->threadFor($identity);

        return Conversation::factory()->create([
            'corporation_id' => $this->corporation->id,
            'customer_id' => $customer->id,
        ]);
    }

    private function inbound(Identity $identity, string $body, int $hoursAgo = 1): Message
    {
        $message = Message::factory()->create([
            'corporation_id' => $this->corporation->id,
            'thread_id' => $this->threadFor($identity)->id,
            'direction' => MessageDirection::Inbound,
            'body' => $body,
            'sent_at' => now()->subHours($hoursAgo),
        ]);

        $identity->customer->conversation?->touchLastMessage($message);

        return $message;
    }

    private function threadFor(Identity $identity): Thread
    {
        return $identity->threads()->first() ?? Thread::factory()->create([
            'corporation_id' => $identity->corporation_id,
            'identity_id' => $identity->id,
        ]);
    }
}
