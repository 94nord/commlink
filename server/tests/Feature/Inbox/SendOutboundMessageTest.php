<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\Module;
use App\Inbox\Actions\SendOutboundMessage;
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
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class SendOutboundMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_the_text_and_records_the_message()
    {
        $transport = $this->fakeTransport();
        $corporation = Corporation::factory()->texting('+4533221100')->create();
        $thread = $this->thread($corporation, '+4520123456');
        $author = User::factory()->for($corporation)->create();

        $message = app(SendOutboundMessage::class)->handle($thread, 'On my way.', $author);

        $this->assertSame(['+4533221100', '+4520123456', 'On my way.'], $transport->sent[0]);

        $this->assertSame(MessageDirection::Outbound, $message->direction);
        $this->assertSame($author->id, $message->user_id);
        $this->assertSame('On my way.', $message->body);
        $this->assertSame('SMfake', $message->fresh()->channel_data['message_sid']);
        $this->assertSame('+4533221100', $message->fresh()->channel_data['from']);

        $this->assertNotNull($thread->identity->customer->conversation->fresh()->last_message_at);

        $event = Event::where('type', 'message.sent')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->actor->is($author));
    }

    public function test_a_refused_send_leaves_no_trace()
    {
        $this->bindTransport(new class implements SmsTransport
        {
            public function send(string $from, string $to, string $body): ?string
            {
                throw new RuntimeException('Twilio is having a bad day.');
            }
        });

        $corporation = Corporation::factory()->texting('+4533221100')->create();
        $thread = $this->thread($corporation, '+4520123456');

        try {
            app(SendOutboundMessage::class)->handle($thread, 'Never arrives.');
            $this->fail('Expected the send to blow up.');
        } catch (RuntimeException) {
            // Expected.
        }

        // The customer never got it, so the timeline must not claim they did.
        $this->assertSame(0, Message::count());
        $this->assertSame(0, Event::where('type', 'message.sent')->count());
    }

    public function test_a_firm_without_a_number_cannot_send()
    {
        $this->fakeTransport();
        // Switched on, but with nothing to send from — the module gate must
        // not be what stops this, or the test proves nothing about numbers.
        $corporation = Corporation::factory()->hasModule(Module::Sms)->create();
        $thread = $this->thread($corporation, '+4520123456');

        $this->expectException(ValidationException::class);

        app(SendOutboundMessage::class)->handle($thread, 'Hello?');
    }

    public function test_a_firm_switched_off_for_sms_cannot_send()
    {
        $transport = $this->fakeTransport();
        // A number, but not switched on: the history is there, the sending
        // is not.
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();
        $thread = $this->thread($corporation, '+4520123456');

        try {
            app(SendOutboundMessage::class)->handle($thread, 'Hello?');
            $this->fail('Expected the send to be refused.');
        } catch (ValidationException) {
            // Expected.
        }

        // Nothing reached the wire and nothing was written down.
        $this->assertSame([], $transport->sent);
        $this->assertSame(0, Message::count());
    }

    private function thread(Corporation $corporation, string $address): Thread
    {
        $customer = Customer::factory()->for($corporation)->unnamed()->create();

        $identity = Identity::factory()->create([
            'corporation_id' => $corporation->id,
            'customer_id' => $customer->id,
            'value' => $address,
        ]);

        Conversation::factory()->create([
            'corporation_id' => $corporation->id,
            'customer_id' => $customer->id,
        ]);

        return Thread::factory()->create([
            'corporation_id' => $corporation->id,
            'identity_id' => $identity->id,
        ]);
    }

    private function fakeTransport(): SmsTransport
    {
        $transport = new class implements SmsTransport
        {
            /** @var list<array{0: string, 1: string, 2: string}> */
            public array $sent = [];

            public function send(string $from, string $to, string $body): ?string
            {
                $this->sent[] = [$from, $to, $body];

                return 'SMfake';
            }
        };

        return $this->bindTransport($transport);
    }

    private function bindTransport(SmsTransport $transport): SmsTransport
    {
        $this->app->instance(SmsTransport::class, $transport);
        $this->app->forgetInstance(ChannelRegistry::class);

        return $transport;
    }
}
