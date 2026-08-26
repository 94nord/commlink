<?php

namespace Tests\Feature\Inbox;

use App\Enums\AddressType;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The conversation header and the view selector: which addresses this person
 * is reachable at, where the unread traffic actually came in, and the
 * exchanges inside the conversation.
 */
class ConversationThreadsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Corporation $corporation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->corporation = Corporation::factory()->texting('+4533221100')->create();
        $this->user = User::factory()->for($this->corporation)->create();
    }

    public function test_unread_is_counted_per_address()
    {
        $customer = $this->customerWithTwoNumbers();
        [$first, $second] = $customer->identities->all();

        $this->inbound($first, 'One');
        $this->inbound($second, 'Two');
        $this->inbound($second, 'Three');

        $identities = $this->show($customer)->json('conversation.identities');
        $unread = array_column($identities, 'unreadCount', 'value');

        $this->assertSame(1, $unread['+4520123456']);
        $this->assertSame(2, $unread['+4529876543']);
    }

    public function test_opening_the_conversation_clears_every_address()
    {
        $customer = $this->customerWithTwoNumbers();
        [$first, $second] = $customer->identities->all();

        $this->inbound($first, 'One');
        $this->inbound($second, 'Two');

        // Opening marks the whole conversation read — there is no per-address
        // read state, deliberately.
        $this->show($customer);

        $unread = array_column($this->show($customer)->json('conversation.identities'), 'unreadCount');
        $this->assertSame([0, 0], $unread);
    }

    public function test_a_colleagues_reply_is_not_unread_on_any_address()
    {
        $customer = $this->customerWithTwoNumbers();
        [$first] = $customer->identities->all();

        $this->inbound($first, 'From them');
        $this->show($customer);
        $this->outbound($first, 'From us');

        $unread = array_column($this->show($customer)->json('conversation.identities'), 'unreadCount');
        $this->assertSame([0, 0], $unread);
    }

    public function test_the_threads_are_listed_newest_activity_first()
    {
        $customer = $this->customerWithTwoNumbers();
        [$first, $second] = $customer->identities->all();

        $this->inbound($first, 'Older', at: now()->subHour());
        $this->inbound($second, 'Newer', at: now());

        $threads = $this->show($customer)->json('conversation.threads');

        $this->assertCount(2, $threads);
        $this->assertSame($second->threads()->value('id'), $threads[0]['id']);
        $this->assertSame(1, $threads[0]['messageCount']);
    }

    public function test_the_timeline_can_be_narrowed_to_one_thread()
    {
        $customer = $this->customerWithTwoNumbers();
        [$first, $second] = $customer->identities->all();

        $this->inbound($first, 'On the first number');
        $this->inbound($second, 'On the second number');

        $all = $this->show($customer)->json('conversation.messages');
        $this->assertCount(2, $all);

        $one = $this->show($customer, thread: $second->threads()->value('id'))->json('conversation.messages');
        $this->assertSame(['On the second number'], array_column($one, 'body'));
    }

    public function test_the_timeline_can_be_narrowed_to_one_address()
    {
        $customer = $this->customerWithTwoNumbers();
        [$first, $second] = $customer->identities->all();

        $this->inbound($first, 'On the first number');
        $this->inbound($second, 'On the second number');

        $page = $this->show($customer, identity: $second->id)->json('conversation.messages');

        $this->assertSame(['On the second number'], array_column($page, 'body'));
    }

    public function test_a_thread_wins_over_an_address_when_both_are_given()
    {
        $customer = $this->customerWithTwoNumbers();
        [$first, $second] = $customer->identities->all();

        $this->inbound($first, 'On the first number');
        $this->inbound($second, 'On the second number');

        // The thread already implies its address, so a contradicting address
        // is ignored rather than intersected into an empty page.
        $page = $this->show($customer, thread: $first->threads()->value('id'), identity: $second->id)
            ->json('conversation.messages');

        $this->assertSame(['On the first number'], array_column($page, 'body'));
    }

    public function test_an_address_from_another_conversation_selects_nothing()
    {
        $mine = $this->customerWithTwoNumbers();
        $this->inbound($mine->identities->first(), 'Mine');

        $stranger = $this->customerWithTwoNumbers('+4531000001', '+4531000002');
        $this->inbound($stranger->identities->first(), 'Theirs');

        $this->show($mine, identity: $stranger->identities->first()->id)->assertNotFound();
    }

    public function test_a_thread_from_another_conversation_selects_nothing()
    {
        $mine = $this->customerWithTwoNumbers();
        $this->inbound($mine->identities->first(), 'Mine');

        $stranger = $this->customerWithTwoNumbers('+4531000001', '+4531000002');
        $this->inbound($stranger->identities->first(), 'Theirs');

        // Scoped through the conversation's own threads, never the id alone.
        $this->show($mine, thread: $stranger->identities->first()->threads()->value('id'))
            ->assertNotFound();
    }

    private function show(Customer $customer, ?int $thread = null, ?int $identity = null)
    {
        $query = array_filter(['thread' => $thread, 'identity' => $identity]);
        $url = "/api/conversations/{$customer->conversation->id}";

        return $this->actingAs($this->user)->getJson($query === [] ? $url : $url.'?'.http_build_query($query));
    }

    private function customerWithTwoNumbers(string $a = '+4520123456', string $b = '+4529876543'): Customer
    {
        $customer = Customer::factory()->for($this->corporation)->unnamed()->create();

        foreach ([$a, $b] as $value) {
            Identity::factory()->create([
                'corporation_id' => $this->corporation->id,
                'customer_id' => $customer->id,
                'address_type' => AddressType::Phone,
                'value' => $value,
            ]);
        }

        Conversation::factory()->create([
            'corporation_id' => $this->corporation->id,
            'customer_id' => $customer->id,
        ]);

        return $customer->fresh('identities');
    }

    private function inbound(Identity $identity, string $body, $at = null): void
    {
        $this->message($identity, $body, MessageDirection::Inbound, $at);
    }

    private function outbound(Identity $identity, string $body): void
    {
        $this->message($identity, $body, MessageDirection::Outbound, null);
    }

    private function message(Identity $identity, string $body, MessageDirection $direction, $at): void
    {
        $thread = $identity->threads()->firstOrCreate(
            ['external_key' => null],
            ['corporation_id' => $this->corporation->id],
        );

        $message = $thread->messages()->create([
            'corporation_id' => $this->corporation->id,
            'channel' => 'sms',
            'direction' => $direction,
            'body' => $body,
            'sent_at' => $at ?? now(),
        ]);

        $identity->customer->conversation->touchLastMessage($message);
    }
}
