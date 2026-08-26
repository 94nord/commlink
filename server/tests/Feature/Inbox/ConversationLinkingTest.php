<?php

namespace Tests\Feature\Inbox;

use App\Enums\ConversationStatus;
use App\Inbox\Actions\MergeCustomers;
use App\Inbox\Actions\OpenConversation;
use App\Inbox\Actions\UnlinkIdentity;
use App\Models\Conversation;
use App\Models\Corporation;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Identity;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Saying who somebody is, and taking it back.
 *
 * An unknown number belongs to a customer nobody has named. Naming them is a
 * merge into somebody known; getting it wrong is repaired by pulling the
 * address back out. Neither ever moves a message.
 */
class ConversationLinkingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_number_belongs_to_a_person_nobody_has_named()
    {
        $corporation = Corporation::factory()->create();
        $customer = $this->personAt($corporation, '+4520123456');

        $this->assertFalse($customer->isNamed());
        $this->assertSame('+4520123456', $customer->display_name);
        $this->assertFalse($customer->fresh()->exists && $customer->isNamed());
    }

    public function test_a_named_person_answers_to_their_name()
    {
        $corporation = Corporation::factory()->create();
        $customer = $this->personAt($corporation, '+4520123456');

        $customer->update(['name' => 'Hanne Jensen']);

        $this->assertSame('Hanne Jensen', $customer->fresh()->display_name);
    }

    public function test_unnamed_people_are_kept_out_of_the_address_book()
    {
        $corporation = Corporation::factory()->create();
        $this->personAt($corporation, '+4520123456');
        Customer::factory()->for($corporation)->create(['name' => 'Hanne Jensen']);

        $this->assertSame(1, $corporation->customers()->named()->count());
        $this->assertSame(2, $corporation->customers()->count());
    }

    public function test_naming_a_number_hands_it_to_the_person_it_belongs_to()
    {
        $corporation = Corporation::factory()->create();
        $stranger = $this->personAt($corporation, '+4520123456');
        $hanne = Customer::factory()->for($corporation)->create(['name' => 'Hanne Jensen']);

        app(MergeCustomers::class)->handle($stranger, $hanne);

        $this->assertSame($hanne->id, Identity::sole()->customer_id);
        $this->assertSoftDeleted($stranger);
        $this->assertNotNull(Event::where('type', 'customer.merged')->first());
    }

    public function test_a_merge_keeps_the_conversation_the_caller_was_looking_at()
    {
        $corporation = Corporation::factory()->create();
        $stranger = $this->personAt($corporation, '+4520123456');
        $conversation = app(OpenConversation::class)->handle($stranger);
        $hanne = Customer::factory()->for($corporation)->create(['name' => 'Hanne Jensen']);

        app(MergeCustomers::class)->handle($stranger, $hanne);

        // Hanne had no exchange of her own, so this one simply becomes hers.
        $this->assertSame($hanne->id, $conversation->fresh()->customer_id);
        $this->assertSame(1, Conversation::count());
    }

    public function test_two_exchanges_with_one_person_become_one()
    {
        $corporation = Corporation::factory()->create();

        $hanne = Customer::factory()->for($corporation)->create(['name' => 'Hanne Jensen']);
        $mobile = $this->addressFor($hanne, '+4520123456');
        $this->message($mobile, 'From her mobile', now()->subDays(3));
        $hers = app(OpenConversation::class)->handle($hanne);
        $hers->recomputeLastMessage();

        $stranger = $this->personAt($corporation, '+4531234567');
        $newest = $this->message($stranger->identities->first(), 'From her other phone', now()->subHour());
        $theirs = app(OpenConversation::class)->handle($stranger);
        $theirs->recomputeLastMessage();

        app(MergeCustomers::class)->handle($stranger, $hanne);

        $this->assertSoftDeleted($theirs);
        $this->assertEqualsCanonicalizing(
            ['+4520123456', '+4531234567'],
            $hanne->fresh()->identities->pluck('value')->all(),
        );

        // The message never moved; the address it hangs off changed owner.
        $this->assertSame($newest->thread_id, $newest->fresh()->thread_id);
        $this->assertTrue($hers->fresh()->last_message_at->equalTo($newest->sent_at));
        $this->assertSame($newest->id, $hers->fresh()->last_message_id);
    }

    public function test_a_merge_pulls_an_archived_conversation_back_into_the_queue()
    {
        $corporation = Corporation::factory()->create();
        $hanne = Customer::factory()->for($corporation)->create(['name' => 'Hanne Jensen']);
        $hers = app(OpenConversation::class)->handle($hanne);
        $hers->update(['status' => ConversationStatus::Archived]);

        $stranger = $this->personAt($corporation, '+4531234567');
        app(OpenConversation::class)->handle($stranger);

        app(MergeCustomers::class)->handle($stranger, $hanne);

        $this->assertSame(ConversationStatus::Open, $hers->fresh()->status);
    }

    public function test_a_person_who_is_already_named_cannot_be_merged_away()
    {
        $corporation = Corporation::factory()->create();
        $one = Customer::factory()->for($corporation)->create(['name' => 'Hanne']);
        $two = Customer::factory()->for($corporation)->create(['name' => 'Lars']);

        $this->expectException(ValidationException::class);

        app(MergeCustomers::class)->handle($one, $two);
    }

    public function test_merging_across_corporations_is_refused()
    {
        $stranger = $this->personAt(Corporation::factory()->create(), '+4520123456');

        $this->expectException(ValidationException::class);

        app(MergeCustomers::class)->handle($stranger, Customer::factory()->create());
    }

    public function test_one_address_cannot_belong_to_two_people()
    {
        $corporation = Corporation::factory()->create();
        $this->personAt($corporation, '+4520123456');

        $this->expectException(QueryException::class);

        $this->personAt($corporation, '+4520123456');
    }

    public function test_the_same_address_at_two_corporations_is_two_people()
    {
        $this->personAt(Corporation::factory()->create(), '+4520123456');
        $this->personAt(Corporation::factory()->create(), '+4520123456');

        $this->assertSame(2, Identity::where('value', '+4520123456')->count());
    }

    public function test_unlinking_takes_an_address_and_its_history_to_somebody_new()
    {
        $corporation = Corporation::factory()->create();
        $hanne = Customer::factory()->for($corporation)->create(['name' => 'Hanne Jensen']);
        $right = $this->addressFor($hanne, '+4520123456');
        $wrong = $this->addressFor($hanne, '+4531234567');
        $message = $this->message($wrong, 'Wrong number, mate.');
        app(OpenConversation::class)->handle($hanne)->recomputeLastMessage();

        $stranger = app(UnlinkIdentity::class)->handle($wrong);

        $this->assertFalse($stranger->isNamed());
        $this->assertSame($stranger->id, $wrong->fresh()->customer_id);
        $this->assertSame($message->thread_id, $message->fresh()->thread_id);

        // Hanne keeps her own number, and loses the history that left.
        $this->assertSame(['+4520123456'], $hanne->fresh()->identities->pluck('value')->all());
        $this->assertFalse($hanne->fresh()->timeline()->pluck('id')->contains($message->id));
        $this->assertTrue($stranger->timeline()->pluck('id')->contains($message->id));

        $this->assertNotNull(Event::where('type', 'identity.unlinked')->first());
        $this->assertNotNull($right->fresh());
    }

    public function test_unlinking_the_last_address_leaves_the_person_standing()
    {
        $corporation = Corporation::factory()->create();
        $hanne = Customer::factory()->for($corporation)->create(['name' => 'Hanne Jensen']);
        $only = $this->addressFor($hanne, '+4520123456');

        app(UnlinkIdentity::class)->handle($only);

        $this->assertModelExists($hanne->fresh());
        $this->assertSame(0, $hanne->fresh()->identities()->count());
    }

    public function test_an_unnamed_person_who_is_only_one_address_cannot_be_split()
    {
        $corporation = Corporation::factory()->create();
        $stranger = $this->personAt($corporation, '+4520123456');

        $this->expectException(ValidationException::class);

        app(UnlinkIdentity::class)->handle($stranger->identities->first());
    }

    public function test_the_timeline_gathers_every_address_in_time_order()
    {
        $corporation = Corporation::factory()->create();
        $hanne = Customer::factory()->for($corporation)->create(['name' => 'Hanne Jensen']);
        $first = $this->addressFor($hanne, '+4520123456');
        $second = $this->addressFor($hanne, '+4531234567');

        $this->message($first, 'first', now()->subHours(2));
        $this->message($second, 'second', now()->subHour());
        $this->message($first, 'third', now());

        $this->assertSame(['first', 'second', 'third'], $hanne->timeline()->pluck('body')->all());
    }

    /** Somebody known only by the number they wrote from. */
    private function personAt(Corporation $corporation, string $address): Customer
    {
        $customer = Customer::factory()->for($corporation)->unnamed()->create();

        Identity::factory()->create([
            'corporation_id' => $corporation->id,
            'customer_id' => $customer->id,
            'value' => $address,
        ]);

        return $customer->load('identities');
    }

    private function addressFor(Customer $customer, string $address): Identity
    {
        return Identity::factory()->create([
            'corporation_id' => $customer->corporation_id,
            'customer_id' => $customer->id,
            'value' => $address,
        ]);
    }

    private function message(Identity $identity, string $body, ?\DateTimeInterface $sentAt = null): Message
    {
        $thread = $identity->threads()->first() ?? Thread::factory()->create([
            'corporation_id' => $identity->corporation_id,
            'identity_id' => $identity->id,
        ]);

        return Message::factory()->create([
            'corporation_id' => $identity->corporation_id,
            'thread_id' => $thread->id,
            'body' => $body,
            'sent_at' => $sentAt ?? now(),
        ]);
    }
}
