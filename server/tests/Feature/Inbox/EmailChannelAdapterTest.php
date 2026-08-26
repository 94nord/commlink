<?php

namespace Tests\Feature\Inbox;

use App\Enums\AddressType;
use App\Enums\Channel;
use App\Enums\Module;
use App\Inbox\Actions\IngestInboundMessage;
use App\Inbox\ChannelRegistry;
use App\Inbox\Email\EmailChannelAdapter;
use App\Inbox\Email\OutboundEmail;
use App\Inbox\Email\Transport\EmailTransport;
use App\Models\Conversation;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\EmailMessageId;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Email threading: one mailbox carries many unrelated exchanges, and which
 * one a reply belongs to is the whole difference between a readable inbox
 * and a scattered one.
 */
class EmailChannelAdapterTest extends TestCase
{
    use RefreshDatabase;

    private Corporation $corporation;

    /** @var object{sent: list<OutboundEmail>} */
    private object $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->corporation = Corporation::factory()->create();
        $this->corporation->modules()->create(['module' => Module::Email]);
        $this->corporation->addresses()->create([
            'address_type' => AddressType::Email,
            'value' => 'kontakt@firma.dk',
            'is_primary' => true,
            'verified_at' => now(),
        ]);

        $this->transport = new class implements EmailTransport
        {
            /** @var list<OutboundEmail> */
            public array $sent = [];

            public function send(OutboundEmail $email): string
            {
                $this->sent[] = $email;

                return $email->messageId;
            }
        };

        $this->app->instance(EmailTransport::class, $this->transport);
        $this->app->forgetInstance(ChannelRegistry::class);
    }

    public function test_a_first_email_starts_an_exchange_named_by_its_subject()
    {
        $this->receive(subject: 'Utæt radiator', messageId: 'a@customer.dk');

        $thread = Thread::sole();
        $this->assertSame('Utæt radiator', $thread->subject);
        $this->assertSame('kontakt@firma.dk', $thread->corporationAddress->value);
        $this->assertSame(Channel::Email, Message::sole()->channel);
    }

    public function test_a_reply_naming_the_message_it_answers_lands_in_that_exchange()
    {
        $this->receive(subject: 'Utæt radiator', messageId: 'a@customer.dk');
        $this->receive(subject: 'SV: Utæt radiator', messageId: 'b@customer.dk', inReplyTo: 'a@customer.dk');

        $this->assertSame(1, Thread::count());
        $this->assertSame(2, Message::count());
    }

    public function test_a_reply_naming_a_message_in_the_middle_of_the_chain_still_lands()
    {
        $this->receive(subject: 'Utæt radiator', messageId: 'a@customer.dk');
        $this->receive(subject: 'SV: Utæt radiator', messageId: 'b@customer.dk', inReplyTo: 'a@customer.dk');

        // Replying to the second message, not the first — which is what a
        // mail client does whenever somebody scrolls up.
        $this->receive(
            subject: 'SV: Utæt radiator',
            messageId: 'c@customer.dk',
            inReplyTo: 'b@customer.dk',
            references: 'a@customer.dk b@customer.dk',
        );

        $this->assertSame(1, Thread::count());
        $this->assertSame(3, Message::count());
    }

    public function test_a_broken_chain_falls_back_to_the_subject()
    {
        $this->receive(subject: 'Utæt radiator', messageId: 'a@customer.dk');

        // No In-Reply-To at all — somebody wrote a fresh message with the
        // same subject, which a human would plainly call the same exchange.
        $this->receive(subject: 'SV: Utæt radiator', messageId: 'b@customer.dk');

        $this->assertSame(1, Thread::count());
    }

    public function test_danish_and_english_reply_prefixes_are_both_stripped()
    {
        foreach (['Re: Utæt radiator', 'SV: Utæt radiator', 'VS: Utæt radiator', 'Fwd: Utæt radiator', 'SV: Re: Utæt radiator'] as $subject) {
            $this->assertSame('utæt radiator', EmailChannelAdapter::normalizeSubject($subject));
        }
    }

    public function test_an_unrelated_email_starts_its_own_exchange()
    {
        $this->receive(subject: 'Utæt radiator', messageId: 'a@customer.dk');
        $this->receive(subject: 'Tilbud på nyt badeværelse', messageId: 'b@customer.dk');

        $this->assertSame(2, Thread::count());
        $this->assertSame(1, Customer::count());
    }

    public function test_one_persons_chain_never_reaches_another_persons_exchange()
    {
        $this->receive(subject: 'Utæt radiator', messageId: 'a@customer.dk', from: 'anna@customer.dk');

        // A different sender quoting an id we hold under somebody else. It is
        // not their exchange, whatever their client claims.
        $this->receive(
            subject: 'SV: Utæt radiator',
            messageId: 'b@other.dk',
            inReplyTo: 'a@customer.dk',
            from: 'bo@other.dk',
        );

        $this->assertSame(2, Thread::count());
        $this->assertSame(2, Customer::count());
    }

    public function test_a_rich_body_is_sanitized_on_the_way_in_and_a_plain_one_derived()
    {
        $this->receive(
            subject: 'Utæt radiator',
            messageId: 'a@customer.dk',
            html: '<style>p{color:red}</style><div>Hej</div><script>alert(1)</script><p>Kommer I?</p>',
        );

        $message = Message::sole();
        $this->assertSame('Hej<p>Kommer I?</p>', $message->body_html);
        $this->assertStringNotContainsString('alert', (string) $message->body_html);
        $this->assertSame("Hej\nKommer I?", $message->body);
    }

    public function test_a_reply_goes_out_on_the_exchanges_own_chain()
    {
        $this->receive(subject: 'Utæt radiator', messageId: 'a@customer.dk');

        $user = User::factory()->for($this->corporation)->create();
        $conversation = Conversation::sole();

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Vi kommer tirsdag'])
            ->assertStatus(201);

        $sent = $this->transport->sent[0];
        $this->assertSame('kontakt@firma.dk', $sent->from);
        $this->assertSame('anna@customer.dk', $sent->to);
        $this->assertSame('Utæt radiator', $sent->subject);
        $this->assertSame('a@customer.dk', $sent->inReplyTo);
        $this->assertSame(['a@customer.dk'], $sent->references);

        // Ours is remembered too, so their reply to it threads back here.
        $this->assertTrue(EmailMessageId::where('message_id', $sent->messageId)->exists());
    }

    public function test_a_reply_to_our_own_message_lands_in_the_same_exchange()
    {
        $this->receive(subject: 'Utæt radiator', messageId: 'a@customer.dk');

        $user = User::factory()->for($this->corporation)->create();
        $this->actingAs($user)->postJson(
            '/api/conversations/'.Conversation::sole()->id.'/messages',
            ['body' => 'Vi kommer tirsdag'],
        );

        $ours = $this->transport->sent[0]->messageId;

        $this->receive(subject: 'SV: Utæt radiator', messageId: 'c@customer.dk', inReplyTo: $ours);

        $this->assertSame(1, Thread::count());
        $this->assertSame(3, Message::count());
    }

    public function test_a_display_name_around_the_address_is_still_the_same_person()
    {
        $this->receive(subject: 'One', messageId: 'a@customer.dk', from: 'Anna Larsen <Anna@Customer.DK>');
        $this->receive(subject: 'Two', messageId: 'b@customer.dk', from: 'anna@customer.dk');

        $this->assertSame(1, Customer::count());
        $this->assertSame('anna@customer.dk', Customer::sole()->identities->sole()->value);
    }

    private function receive(
        string $subject,
        string $messageId,
        string $from = 'anna@customer.dk',
        ?string $inReplyTo = null,
        ?string $references = null,
        ?string $html = null,
    ): void {
        $adapter = app(ChannelRegistry::class)->for(Channel::Email);

        app(IngestInboundMessage::class)->handle($this->corporation, $adapter, $adapter->receive(array_filter([
            'from' => $from,
            'to' => 'kontakt@firma.dk',
            'subject' => $subject,
            'text' => $html === null ? 'Some words' : null,
            'html' => $html,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
        ], fn ($value) => $value !== null)));
    }
}
