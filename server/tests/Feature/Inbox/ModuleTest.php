<?php

namespace Tests\Feature\Inbox;

use App\Enums\AddressType;
use App\Enums\ChannelStatus;
use App\Enums\MessageDirection;
use App\Enums\Module;
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
use Twilio\Security\RequestValidator;

/**
 * Being switched on for a module, and what happens at every edge when a firm
 * is not: sending, composing, receiving, and what the inbox is told to show.
 */
class ModuleTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_URL = 'http://localhost/api/webhooks/twilio/sms';

    private const TOKEN = 'twilio-test-auth-token';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.twilio.token' => self::TOKEN]);

        $this->app->instance(SmsTransport::class, new class implements SmsTransport
        {
            public function send(string $from, string $to, string $body): ?string
            {
                return 'SMfake';
            }
        });
        $this->app->forgetInstance(ChannelRegistry::class);
    }

    public function test_a_fresh_corporation_is_switched_on_for_nothing()
    {
        $corporation = Corporation::factory()->create();

        $this->assertFalse($corporation->hasModule(Module::Sms));
    }

    public function test_enabling_and_disabling_are_recorded()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();

        $this->artisan('app:enable-module', [
            '--corporation' => (string) $corporation->id,
            '--module' => 'sms',
        ])->assertSuccessful();

        $this->assertTrue($corporation->fresh()->hasModule(Module::Sms));
        $this->assertNotNull(Event::where('type', 'corporation.module_enabled')->first());

        $this->artisan('app:disable-module', [
            '--corporation' => (string) $corporation->id,
            '--module' => 'sms',
        ])->assertSuccessful();

        $this->assertFalse($corporation->fresh()->hasModule(Module::Sms));
        $this->assertNotNull(Event::where('type', 'corporation.module_disabled')->first());
    }

    public function test_enabling_twice_is_not_an_error_and_does_not_duplicate()
    {
        $corporation = Corporation::factory()->texting('+4533221100')->create();

        $this->artisan('app:enable-module', [
            '--corporation' => (string) $corporation->id,
            '--module' => 'sms',
        ])->assertSuccessful();

        $this->assertSame(1, $corporation->modules()->count());
    }

    public function test_it_refuses_a_module_that_does_not_exist()
    {
        $corporation = Corporation::factory()->create();

        $this->artisan('app:enable-module', [
            '--corporation' => (string) $corporation->id,
            '--module' => 'telepathy',
        ])->assertFailed();

        $this->assertSame(0, $corporation->modules()->count());
    }

    public function test_disabling_keeps_the_history()
    {
        $corporation = Corporation::factory()->texting('+4533221100')->create();
        $thread = $this->threadWithInbound($corporation, '+4520123456', 'Radiatoren drypper');

        $this->artisan('app:disable-module', [
            '--corporation' => (string) $corporation->id,
            '--module' => 'sms',
        ])->assertSuccessful();

        // Freeze, not delete: the message and the address it arrived on are
        // both still there.
        $this->assertSame(1, $thread->messages()->count());
        $this->assertSame(1, $corporation->identities()->count());
    }

    public function test_composing_is_refused_and_invents_nobody()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();
        $user = User::factory()->for($corporation)->create();

        $this->actingAs($user)
            ->postJson('/api/conversations', ['address' => '+4520123456', 'body' => 'Hej'])
            ->assertStatus(422);

        // The refusal must not leave the person or the address it would have
        // invented on the way there.
        $this->assertSame(0, $corporation->customers()->count());
        $this->assertSame(0, $corporation->identities()->count());
        $this->assertSame(0, Message::count());
    }

    public function test_replying_is_refused()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();
        $user = User::factory()->for($corporation)->create();
        $thread = $this->threadWithInbound($corporation, '+4520123456', 'Er I der?');
        $conversation = $thread->identity->customer->conversation;

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Vi kommer'])
            ->assertStatus(422);

        $this->assertSame(0, Message::where('direction', MessageDirection::Outbound)->count());
    }

    public function test_an_inbound_text_to_a_switched_off_firm_is_swallowed()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();

        $this->postToWebhook($corporation, '+4520123456', 'Hallo?')
            // The same empty TwiML a delivered text gets: anything else makes
            // Twilio retry, and the customer must not learn anything is wrong.
            ->assertOk()
            ->assertSee('<Response/>', false);

        $this->assertSame(0, Message::count());
        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, $corporation->identities()->count());
    }

    public function test_switching_back_on_resumes_sending_with_history_intact()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();
        $user = User::factory()->for($corporation)->create();
        $thread = $this->threadWithInbound($corporation, '+4520123456', 'Er I der?');
        $conversation = $thread->identity->customer->conversation;

        $this->artisan('app:enable-module', [
            '--corporation' => (string) $corporation->id,
            '--module' => 'sms',
        ])->assertSuccessful();

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Vi kommer'])
            ->assertStatus(201);

        $this->assertSame(2, $thread->messages()->count());
    }

    public function test_an_address_with_history_goes_read_only_when_switched_off()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();
        $user = User::factory()->for($corporation)->create();
        $thread = $this->threadWithInbound($corporation, '+4520123456', 'Er I der?');
        $conversation = $thread->identity->customer->conversation;

        $response = $this->actingAs($user)->getJson("/api/conversations/{$conversation->id}");

        $response->assertOk();
        $identities = $response->json('conversation.identities');

        $this->assertCount(1, $identities);
        $this->assertSame(ChannelStatus::ReadOnly->value, $identities[0]['status']);
    }

    public function test_an_address_with_no_history_is_not_shown_at_all_when_switched_off()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();
        $user = User::factory()->for($corporation)->create();
        $thread = $this->threadWithInbound($corporation, '+4520123456', 'Er I der?');
        $customer = $thread->identity->customer;

        // A second number nobody has ever used. With the module off it is
        // neither reachable nor evidence of anything, so it is noise.
        $customer->identities()->create([
            'corporation_id' => $corporation->id,
            'address_type' => AddressType::Phone,
            'value' => '+4529876543',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/conversations/{$customer->conversation->id}");

        $this->assertSame(['+4520123456'], array_column($response->json('conversation.identities'), 'value'));
    }

    public function test_a_switched_on_firm_reports_its_addresses_as_active()
    {
        $corporation = Corporation::factory()->texting('+4533221100')->create();
        $user = User::factory()->for($corporation)->create();
        $thread = $this->threadWithInbound($corporation, '+4520123456', 'Er I der?');
        $conversation = $thread->identity->customer->conversation;

        $response = $this->actingAs($user)->getJson("/api/conversations/{$conversation->id}");

        $this->assertSame(ChannelStatus::Active->value, $response->json('conversation.identities.0.status'));
        $this->assertSame(['phone'], $this->actingAs($user)->getJson('/api/conversations')->json('availableAddressTypes'));
    }

    public function test_a_switched_off_firm_offers_no_channels_to_compose_on()
    {
        $corporation = Corporation::factory()->withPhone('+4533221100')->create();
        $user = User::factory()->for($corporation)->create();

        $this->assertSame([], $this->actingAs($user)->getJson('/api/conversations')->json('availableAddressTypes'));
    }

    public function test_a_firm_switched_on_but_with_no_number_offers_no_channels()
    {
        $corporation = Corporation::factory()->hasModule(Module::Sms)->create();
        $user = User::factory()->for($corporation)->create();

        // Switched on is not the same as reachable — saying "active" here
        // would promise a send that fails at the adapter.
        $this->assertSame([], $this->actingAs($user)->getJson('/api/conversations')->json('availableAddressTypes'));
    }

    public function test_an_unverified_address_is_not_offered()
    {
        $corporation = Corporation::factory()->texting('+4533221100')->create();
        $corporation->modules()->create(['module' => Module::Email]);
        $corporation->addresses()->create([
            'address_type' => AddressType::Email,
            'value' => 'kontakt@firma.dk',
            'is_primary' => true,
            // Attached, but not proved to be the firm's own. Mail from an
            // unauthenticated domain is spam-filed, which looks to the firm
            // like silence — so it is not offered at all.
            'verified_at' => null,
        ]);
        $user = User::factory()->for($corporation)->create();

        $this->assertSame(
            ['phone'],
            $this->actingAs($user)->getJson('/api/conversations')->json('availableAddressTypes'),
        );
    }

    public function test_a_verified_address_on_an_enabled_module_is_offered()
    {
        $corporation = Corporation::factory()->texting('+4533221100')->create();
        $corporation->modules()->create(['module' => Module::Email]);
        $corporation->addresses()->create([
            'address_type' => AddressType::Email,
            'value' => 'kontakt@firma.dk',
            'is_primary' => true,
            'verified_at' => now(),
        ]);
        $user = User::factory()->for($corporation)->create();

        $this->assertSame(
            ['phone', 'email'],
            $this->actingAs($user)->getJson('/api/conversations')->json('availableAddressTypes'),
        );
    }

    public function test_attaching_a_mailbox_leaves_it_unproved_unless_told_otherwise()
    {
        $corporation = Corporation::factory()->create();

        $this->artisan('app:attach-address', [
            '--corporation' => (string) $corporation->id,
            '--type' => 'email',
            '--address' => 'Kontakt@Firma.DK',
        ])->assertSuccessful();

        $address = $corporation->addresses()->sole();
        $this->assertSame('kontakt@firma.dk', $address->value);
        $this->assertNull($address->verified_at);

        $this->artisan('app:attach-address', [
            '--corporation' => (string) $corporation->id,
            '--type' => 'email',
            '--address' => 'ny@firma.dk',
            '--verified' => true,
        ])->assertSuccessful();

        $this->assertNotNull($corporation->addresses()->where('value', 'ny@firma.dk')->sole()->verified_at);
    }

    private function threadWithInbound(Corporation $corporation, string $address, string $body): Thread
    {
        $customer = Customer::factory()->for($corporation)->unnamed()->create();

        $identity = Identity::factory()->create([
            'corporation_id' => $corporation->id,
            'customer_id' => $customer->id,
            'value' => $address,
        ]);

        $conversation = Conversation::factory()->create([
            'corporation_id' => $corporation->id,
            'customer_id' => $customer->id,
        ]);

        $thread = Thread::factory()->create([
            'corporation_id' => $corporation->id,
            'identity_id' => $identity->id,
        ]);

        $message = $thread->messages()->create([
            'corporation_id' => $corporation->id,
            'channel' => 'sms',
            'direction' => MessageDirection::Inbound,
            'body' => $body,
            'sent_at' => now(),
        ]);

        $conversation->touchLastMessage($message);

        return $thread;
    }

    private function postToWebhook(Corporation $corporation, string $from, string $body)
    {
        $payload = [
            'MessageSid' => 'SM1234567890',
            'AccountSid' => 'AC1234567890',
            'From' => $from,
            'To' => $corporation->primaryAddress(AddressType::Phone)->value,
            'Body' => $body,
        ];

        // A genuinely signed request: the swallow has to happen after the
        // signature passes, not because it failed.
        return $this->post(self::WEBHOOK_URL, $payload, [
            'X-Twilio-Signature' => (new RequestValidator(self::TOKEN))->computeSignature(self::WEBHOOK_URL, $payload),
        ]);
    }
}
