<?php

namespace Tests\Feature\Inbox;

use App\Enums\AddressType;
use App\Enums\ConversationStatus;
use App\Inbox\ChannelRegistry;
use App\Inbox\Sms\Transport\SmsTransport;
use App\Models\Conversation;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\Identity;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Corporation $corporation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->corporation = Corporation::factory()->texting('+4533221100')->create();
        $this->user = User::factory()->for($this->corporation)->create();

        $this->app->instance(SmsTransport::class, new class implements SmsTransport
        {
            public function send(string $from, string $to, string $body): ?string
            {
                return 'SMfake';
            }
        });
        $this->app->forgetInstance(ChannelRegistry::class);
    }

    public function test_the_queue_is_newest_first_with_the_last_thing_said()
    {
        $quiet = $this->conversationWith('+4520000001', 'Older message', hoursAgo: 5);
        $recent = $this->conversationWith('+4520000002', 'Newer message', hoursAgo: 1);

        $response = $this->actingAs($this->user)->getJson('/api/conversations')->assertOk();

        $response->assertJsonPath('conversations.0.id', $recent->id);
        $response->assertJsonPath('conversations.1.id', $quiet->id);
        $response->assertJsonPath('conversations.0.lastMessage.body', 'Newer message');
        $response->assertJsonPath('conversations.0.lastMessage.direction', 'inbound');
        $response->assertJsonPath('conversations.0.displayName', '+4520000002');
        $response->assertJsonPath('conversations.0.isAnonymous', true);
    }

    public function test_a_conversation_with_nothing_said_in_it_is_not_in_the_queue()
    {
        // A number written on a customer's record, never used. Real, but not
        // an inbox item.
        Conversation::factory()->create(['corporation_id' => $this->corporation->id]);
        $active = $this->conversationWith('+4520000002', 'Hello', hoursAgo: 3);

        $this->actingAs($this->user)->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.id', $active->id);
    }

    public function test_the_queue_shows_only_this_firms_conversations()
    {
        $this->conversationWith('+4520000001', 'Ours');

        $other = Corporation::factory()->create();
        Conversation::factory()->create(['corporation_id' => $other->id]);

        $this->actingAs($this->user)->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'conversations');
    }

    public function test_a_named_conversation_shows_the_customer_name()
    {
        $conversation = $this->conversationWith('+4520000001', 'Hi');
        // Somebody says who the number belongs to.
        $conversation->customer->update(['name' => 'Hanne Jensen']);

        $this->actingAs($this->user)->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('conversations.0.displayName', 'Hanne Jensen')
            ->assertJsonPath('conversations.0.isAnonymous', false);
    }

    public function test_the_detail_view_gathers_the_timeline_across_addresses()
    {
        $conversation = $this->conversationWith('+4520000001', 'First', hoursAgo: 3);
        $second = Identity::factory()->create([
            'corporation_id' => $this->corporation->id,
            'customer_id' => $conversation->customer_id,
            'value' => '+4520000002',
        ]);
        $this->messageOn($second, 'Second', hoursAgo: 2);

        $response = $this->actingAs($this->user)
            ->getJson("/api/conversations/{$conversation->id}")
            ->assertOk();

        $this->assertSame(['First', 'Second'], $response->json('conversation.messages.*.body'));
        $this->assertCount(2, $response->json('conversation.identities'));
        $response->assertJsonPath('conversation.hasMore', false);
    }

    public function test_the_timeline_pages_backwards_from_a_cursor()
    {
        $conversation = $this->conversationWith('+4520000001', 'message 0', hoursAgo: 100);
        $identity = $conversation->addresses()->first();

        for ($i = 1; $i <= 60; $i++) {
            $this->messageOn($identity, "message {$i}", hoursAgo: 100 - $i);
        }

        $first = $this->actingAs($this->user)
            ->getJson("/api/conversations/{$conversation->id}")
            ->assertOk();

        // Newest 50, oldest-first within the page.
        $this->assertCount(50, $first->json('conversation.messages'));
        $this->assertSame('message 11', $first->json('conversation.messages.0.body'));
        $this->assertSame('message 60', $first->json('conversation.messages.49.body'));
        $this->assertTrue($first->json('conversation.hasMore'));

        $oldestSeen = $first->json('conversation.messages.0.id');

        $older = $this->actingAs($this->user)
            ->getJson("/api/conversations/{$conversation->id}?before={$oldestSeen}")
            ->assertOk();

        $this->assertCount(11, $older->json('conversation.messages'));
        $this->assertSame('message 0', $older->json('conversation.messages.0.body'));
        $this->assertSame('message 10', $older->json('conversation.messages.10.body'));
        $this->assertFalse($older->json('conversation.hasMore'));
    }

    public function test_another_firms_conversation_is_not_found()
    {
        $other = Corporation::factory()->create();
        $theirs = Conversation::factory()->create(['corporation_id' => $other->id]);

        // A customer of *our* corporation, so the request validates and the
        // 404 can only be coming from the conversation lookup.
        $ours = Customer::factory()->for($this->corporation)->create();

        $this->actingAs($this->user)->getJson("/api/conversations/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->user)->postJson("/api/conversations/{$theirs->id}/messages", ['body' => 'Hi'])->assertNotFound();
        $this->actingAs($this->user)->postJson("/api/conversations/{$theirs->id}/link", ['customerId' => $ours->id])->assertNotFound();
    }

    public function test_a_guest_gets_nothing()
    {
        $this->getJson('/api/conversations')->assertUnauthorized();
    }

    public function test_composing_to_a_new_number_starts_an_anonymous_conversation()
    {
        $response = $this->actingAs($this->user)->postJson('/api/conversations', [
            'address' => '20123456',
            'body' => 'Vi kommer i morgen',
        ])->assertCreated();

        $response->assertJsonPath('conversation.isAnonymous', true);
        $response->assertJsonPath('conversation.displayName', '+4520123456');
        $response->assertJsonPath('conversation.lastMessage.direction', 'outbound');
        $this->assertSame('+4520123456', Identity::sole()->value);
    }

    public function test_composing_to_a_number_we_already_hold_continues_that_conversation()
    {
        $existing = $this->conversationWith('+4520123456', 'Kan I komme?');

        $this->actingAs($this->user)->postJson('/api/conversations', [
            // Written differently by a human — same person.
            'address' => '20 12 34 56',
            'body' => 'Ja, i morgen',
        ])->assertCreated()->assertJsonPath('conversation.id', $existing->id);

        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, Message::count());
    }

    public function test_composing_to_a_customer_and_a_number_links_the_two()
    {
        $customer = Customer::factory()->for($this->corporation)->create(['name' => 'Hanne Jensen']);

        $this->actingAs($this->user)->postJson('/api/conversations', [
            'customerId' => $customer->id,
            'address' => '+4520123456',
            'body' => 'Hej Hanne',
        ])->assertCreated()
            ->assertJsonPath('conversation.customerId', $customer->id)
            ->assertJsonPath('conversation.displayName', 'Hanne Jensen');

        $this->assertSame($customer->id, Identity::sole()->customer_id);
    }

    public function test_composing_to_a_customer_we_hold_no_number_for_is_refused()
    {
        $customer = Customer::factory()->for($this->corporation)->create();

        $this->actingAs($this->user)->postJson('/api/conversations', [
            'customerId' => $customer->id,
            'body' => 'Hej',
        ])->assertStatus(422)->assertJsonValidationErrors('address');

        $this->assertSame(0, Conversation::count());
    }

    public function test_composing_to_a_customer_uses_the_number_on_their_record()
    {
        // Never texted, but staff wrote a number down when creating them.
        // Never texted, but staff wrote a number on their record.
        $customer = Customer::factory()->for($this->corporation)->create();
        Identity::factory()->create([
            'corporation_id' => $this->corporation->id,
            'customer_id' => $customer->id,
            'value' => '+4520123456',
        ]);

        $this->actingAs($this->user)->postJson('/api/conversations', [
            'customerId' => $customer->id,
            'body' => 'Vi kommer i morgen',
        ])->assertCreated()->assertJsonPath('conversation.customerId', $customer->id);

        $identity = Identity::sole();
        $this->assertSame('+4520123456', $identity->value);
        $this->assertSame($customer->id, $identity->customer_id);
    }

    public function test_composing_to_a_customer_we_do_hold_a_number_for_needs_no_number()
    {
        $conversation = $this->conversationWith('+4520123456', 'Hi');
        $conversation->customer->update(['name' => 'Hanne Jensen']);
        $customer = $conversation->customer;

        $this->actingAs($this->user)->postJson('/api/conversations', [
            'customerId' => $customer->id,
            'body' => 'Vi kommer kl 9',
        ])->assertCreated()->assertJsonPath('conversation.id', $conversation->id);
    }

    public function test_composing_needs_a_recipient_and_a_body()
    {
        $this->actingAs($this->user)->postJson('/api/conversations', ['body' => 'Hej'])
            ->assertStatus(422)->assertJsonValidationErrors(['address', 'customerId']);

        $this->actingAs($this->user)->postJson('/api/conversations', ['address' => '+4520123456'])
            ->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_composing_to_something_that_is_not_a_number_is_refused()
    {
        $this->actingAs($this->user)->postJson('/api/conversations', [
            'address' => 'not-a-number',
            'body' => 'Hej',
        ])->assertStatus(422)->assertJsonValidationErrors('address');

        $this->assertSame(0, Conversation::count());
    }

    public function test_composing_to_another_firms_customer_is_refused()
    {
        $stranger = Customer::factory()->create();

        $this->actingAs($this->user)->postJson('/api/conversations', [
            'customerId' => $stranger->id,
            'address' => '+4520123456',
            'body' => 'Hej',
        ])->assertStatus(422)->assertJsonValidationErrors('customerId');
    }

    public function test_linking_names_a_conversation()
    {
        $conversation = $this->conversationWith('+4520123456', 'Hi');
        $customer = Customer::factory()->for($this->corporation)->create(['name' => 'Hanne Jensen']);

        $this->actingAs($this->user)
            ->postJson("/api/conversations/{$conversation->id}/link", ['customerId' => $customer->id])
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('conversation.displayName', 'Hanne Jensen');
    }

    public function test_linking_into_an_existing_conversation_returns_the_survivor()
    {
        $customer = Customer::factory()->for($this->corporation)->create(['name' => 'Hanne Jensen']);
        $existing = $this->conversationWith('+4520000001', 'From her mobile', hoursAgo: 5);
        $existing->update(['customer_id' => $customer->id]);

        $anonymous = $this->conversationWith('+4520000002', 'From her other phone', hoursAgo: 1);

        $response = $this->actingAs($this->user)
            ->postJson("/api/conversations/{$anonymous->id}/link", ['customerId' => $customer->id])
            ->assertOk();

        // The caller has to be told where the conversation went.
        $response->assertJsonPath('conversation.id', $existing->id);
        $response->assertJsonPath('conversation.lastMessage.body', 'From her other phone');
        $this->assertSoftDeleted($anonymous);
    }

    public function test_unlinking_moves_an_address_out()
    {
        $customer = Customer::factory()->for($this->corporation)->create();
        $conversation = $this->conversationWith('+4520000001', 'Right number');
        $conversation->update(['customer_id' => $customer->id]);

        $wrong = Identity::factory()->create([
            'corporation_id' => $this->corporation->id,
            'customer_id' => $conversation->customer_id,
            'value' => '+4520000002',
        ]);
        $this->messageOn($wrong, 'Wrong number');

        $response = $this->actingAs($this->user)
            ->postJson("/api/identities/{$wrong->id}/unlink")
            ->assertOk();

        $this->assertNotSame($conversation->id, $response->json('conversation.id'));
        $response->assertJsonPath('conversation.isAnonymous', true);
        $response->assertJsonPath('conversation.displayName', '+4520000002');
    }

    public function test_another_firms_address_cannot_be_unlinked()
    {
        $other = Corporation::factory()->create();
        $theirs = Identity::factory()->create(['corporation_id' => $other->id]);

        $this->actingAs($this->user)->postJson("/api/identities/{$theirs->id}/unlink")->assertNotFound();
    }

    public function test_the_queue_counts_what_this_person_has_not_read()
    {
        $conversation = $this->conversationWith('+4520000001', 'First', hoursAgo: 3);
        $this->messageOn($conversation->addresses()->first(), 'Second', hoursAgo: 2);

        $this->actingAs($this->user)->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('conversations.0.unreadCount', 2);

        // Opening it is reading it.
        $this->actingAs($this->user)->getJson("/api/conversations/{$conversation->id}")->assertOk();

        $this->actingAs($this->user)->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('conversations.0.unreadCount', 0);

        // Something new afterwards counts again.
        $this->messageOn($conversation->addresses()->first(), 'Third', hoursAgo: 0);

        $this->actingAs($this->user)->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('conversations.0.unreadCount', 1);
    }

    public function test_reading_is_per_person_not_per_firm()
    {
        $colleague = User::factory()->for($this->corporation)->create();
        $conversation = $this->conversationWith('+4520000001', 'Hello');

        $this->actingAs($this->user)->getJson("/api/conversations/{$conversation->id}")->assertOk();

        // One colleague glancing at it must not mark it handled for the rest.
        $this->actingAs($this->user)->getJson('/api/conversations')
            ->assertJsonPath('conversations.0.unreadCount', 0);
        $this->actingAs($colleague)->getJson('/api/conversations')
            ->assertJsonPath('conversations.0.unreadCount', 1);
    }

    public function test_a_colleagues_reply_is_not_something_to_catch_up_on()
    {
        $conversation = $this->conversationWith('+4520000001', 'Kan I komme?');
        $this->actingAs($this->user)->getJson("/api/conversations/{$conversation->id}")->assertOk();

        $colleague = User::factory()->for($this->corporation)->create();
        $this->actingAs($colleague)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Ja, i morgen'])
            ->assertCreated();

        $this->actingAs($this->user)->getJson('/api/conversations')
            ->assertJsonPath('conversations.0.unreadCount', 0);
    }

    /**
     * A conversation reachable at one number, with one inbound message —
     * what a customer texting in leaves behind.
     */
    private function conversationWith(string $address, string $body, int $hoursAgo = 1): Conversation
    {
        $customer = Customer::factory()->for($this->corporation)->unnamed()->create();

        $identity = Identity::factory()->create([
            'corporation_id' => $this->corporation->id,
            'customer_id' => $customer->id,
            'address_type' => AddressType::Phone,
            'value' => $address,
        ]);

        $conversation = Conversation::factory()->create([
            'corporation_id' => $this->corporation->id,
            'customer_id' => $customer->id,
            'status' => ConversationStatus::Open,
        ]);

        $this->messageOn($identity, $body, $hoursAgo);

        return $conversation->fresh();
    }

    private function messageOn(Identity $identity, string $body, int $hoursAgo = 1): Message
    {
        $thread = $identity->threads()->first() ?? Thread::factory()->create([
            'corporation_id' => $identity->corporation_id,
            'identity_id' => $identity->id,
        ]);

        $message = Message::factory()->create([
            'corporation_id' => $identity->corporation_id,
            'thread_id' => $thread->id,
            'body' => $body,
            'sent_at' => now()->subHours($hoursAgo),
        ]);

        $identity->customer->conversation?->touchLastMessage($message);

        return $message;
    }
}
