<?php

namespace Tests\Feature\Inbox;

use App\Enums\AddressType;
use App\Inbox\ChannelRegistry;
use App\Inbox\Sms\Transport\SmsTransport;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Starting a conversation takes an address of either kind, and says so when
 * what was typed is neither — or is the right shape for a channel this firm
 * cannot send on.
 */
class ComposeAddressTest extends TestCase
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

    public function test_a_number_is_recognized_as_a_number()
    {
        $this->assertSame(AddressType::Phone, AddressType::sniff('+4520123456'));
        $this->assertSame(AddressType::Phone, AddressType::sniff('20 12 34 56'));
    }

    public function test_a_mailbox_is_recognized_as_a_mailbox()
    {
        $this->assertSame(AddressType::Email, AddressType::sniff('anna@example.com'));
        $this->assertSame(AddressType::Email, AddressType::sniff('Anna@Example.DK'));
    }

    public function test_something_that_is_neither_is_neither()
    {
        $this->assertNull(AddressType::sniff('not-an-address'));
        $this->assertNull(AddressType::sniff('@'));
        $this->assertNull(AddressType::sniff(''));
        // Has an @, so it is read as a mailbox — and is not a usable one.
        $this->assertNull(AddressType::sniff('anna@'));
    }

    public function test_resolving_a_number_answers_with_the_kind_it_settled_on()
    {
        $this->actingAs($this->user)
            ->getJson('/api/conversations/resolve?address='.urlencode('20 12 34 56'))
            ->assertOk()
            ->assertJson(['address' => '+4520123456', 'addressType' => 'phone']);
    }

    public function test_resolving_something_that_is_neither_is_a_field_error()
    {
        $this->actingAs($this->user)
            ->getJson('/api/conversations/resolve?address=not-an-address')
            ->assertStatus(422)
            ->assertJsonValidationErrors('address');
    }

    public function test_resolving_a_mailbox_at_a_firm_without_email_says_so_by_name()
    {
        // The right shape, but this firm has no email channel — refused
        // here, while the person can still change what they typed, rather
        // than after a whole message has been written.
        $this->actingAs($this->user)
            ->getJson('/api/conversations/resolve?address='.urlencode('anna@example.com'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('address');
    }

    public function test_composing_to_a_mailbox_is_refused_and_invents_nobody()
    {
        $this->actingAs($this->user)
            ->postJson('/api/conversations', ['address' => 'anna@example.com', 'body' => 'Hej'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address');

        $this->assertSame(0, $this->corporation->customers()->count());
        $this->assertSame(0, $this->corporation->identities()->count());
        $this->assertSame(0, Message::count());
    }

    public function test_composing_to_something_that_is_neither_is_refused()
    {
        $this->actingAs($this->user)
            ->postJson('/api/conversations', ['address' => 'not-an-address', 'body' => 'Hej'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address');

        $this->assertSame(0, Message::count());
    }

    public function test_composing_to_a_customer_uses_a_channel_the_firm_can_actually_send_on()
    {
        $customer = Customer::factory()->for($this->corporation)->withEmail('anna@example.com')->create();
        $customer->identities()->create([
            'corporation_id' => $this->corporation->id,
            'address_type' => AddressType::Phone,
            'value' => '+4520123456',
        ]);

        // They hold both; only one of them can carry a message today, and
        // picking the other would refuse a send that did not have to fail.
        $this->actingAs($this->user)
            ->postJson('/api/conversations', ['customerId' => $customer->id, 'body' => 'Hej'])
            ->assertStatus(201);

        $this->assertSame(AddressType::Phone, Message::sole()->thread->identity->address_type);
    }
}
