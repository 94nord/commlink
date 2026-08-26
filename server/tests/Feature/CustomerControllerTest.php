<?php

namespace Tests\Feature;

use App\Enums\AddressType;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Identity;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_own_corporation_customers()
    {
        $user = User::factory()->create();
        Customer::factory()->for($user->corporation)->count(3)->create();
        Customer::factory()->count(2)->create(); // other corp

        $this->actingAs($user)
            ->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(3, 'customers');
    }

    public function test_store_creates_a_customer_with_address_and_records_event()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/customers', [
            'name' => 'Anna Larsen',
            'email' => 'anna@example.com',
            'address' => 'Harbour Street 1',
        ])->assertCreated();

        $customer = Customer::first();
        $this->assertNotNull($customer);
        $this->assertSame('Anna Larsen', $customer->name);
        $this->assertSame('anna@example.com', $customer->email);
        $this->assertSame('Harbour Street 1', $customer->address);
        $this->assertTrue($customer->corporation->is($user->corporation));

        $event = Event::where('type', 'customer.created')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->subject->is($customer));
    }

    public function test_show_returns_the_customer()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id);
    }

    public function test_a_foreign_customer_is_not_found()
    {
        $user = User::factory()->create();
        $foreign = Customer::factory()->create();

        $this->actingAs($user)->getJson("/api/customers/{$foreign->id}")->assertNotFound();
        $this->actingAs($user)->patchJson("/api/customers/{$foreign->id}", [
            'name' => 'X', 'email' => 'x@example.com',
        ])->assertNotFound();
    }

    public function test_update_edits_a_customer_and_records_event()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->create(['name' => 'Old']);

        $this->actingAs($user)->patchJson("/api/customers/{$customer->id}", [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'address' => 'New Address 5',
        ])->assertOk();

        $customer->refresh();
        $this->assertSame('New Name', $customer->name);
        $this->assertSame('new@example.com', $customer->email);
        $this->assertSame('New Address 5', $customer->address);
        $this->assertNotNull(Event::where('type', 'customer.updated')->first());
    }

    public function test_update_keeps_the_customers_own_email()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->withEmail('keep@example.com')->create();

        $this->actingAs($user)->patchJson("/api/customers/{$customer->id}", [
            'name' => 'Renamed',
            'email' => 'keep@example.com',
        ])->assertOk();

        $this->assertSame('Renamed', $customer->fresh()->name);
    }

    public function test_update_rejects_another_customers_email()
    {
        $user = User::factory()->create();
        // A *named* holder: the address is theirs and stays theirs. An
        // unnamed holder would be folded in instead, which is the point of
        // moving uniqueness off a validation rule and onto the action.
        Customer::factory()->for($user->corporation)->withEmail('taken@example.com')->create(['name' => 'Anna Larsen']);
        $customer = Customer::factory()->for($user->corporation)->withEmail('mine@example.com')->create();

        $this->actingAs($user)->patchJson("/api/customers/{$customer->id}", [
            'name' => 'Whoever',
            'email' => 'taken@example.com',
        ])->assertJsonValidationErrors('email');

        $this->assertSame('mine@example.com', $customer->fresh()->email);
    }

    public function test_store_rejects_duplicate_email_within_corporation()
    {
        $user = User::factory()->create();
        Customer::factory()->for($user->corporation)->withEmail('anna@example.com')->create(['name' => 'Anna Larsen']);

        $this->actingAs($user)->postJson('/api/customers', [
            'name' => 'Anna Larsen',
            'email' => 'anna@example.com',
        ])->assertJsonValidationErrors('email');

        // The refused customer must not be left behind: the row is created
        // before the address is attached, so this is a real risk.
        $this->assertSame(1, Customer::count());
    }

    public function test_same_email_at_different_corporation_is_allowed()
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Customer::factory()->for($userA->corporation)->withEmail('shared@example.com')->create();

        $this->actingAs($userB)->postJson('/api/customers', [
            'name' => 'Shared Person',
            'email' => 'shared@example.com',
        ])->assertCreated();

        $this->assertSame(2, Customer::count());
    }

    public function test_only_the_name_is_required()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/customers', [])
            ->assertJsonValidationErrors(['name'])
            ->assertJsonMissingValidationErrors(['email', 'phone']);
    }

    public function test_a_customer_can_be_just_a_name_and_a_number()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/customers', ['name' => 'Hanne Jensen', 'phone' => '20 12 34 56'])
            ->assertCreated()
            ->assertJsonPath('customer.email', null)
            // The number is an inbox address, canonical from the start.
            ->assertJsonPath('customer.phones.0.value', '+4520123456');
    }

    public function test_two_customers_can_both_have_no_email()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/customers', ['name' => 'One'])->assertCreated();
        $this->actingAs($user)->postJson('/api/customers', ['name' => 'Two'])->assertCreated();

        $this->assertSame(2, $user->corporation->customers()->count());
    }

    public function test_a_number_we_could_not_text_is_rejected()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/customers', ['name' => 'Hanne', 'phone' => '12345678'])
            ->assertJsonValidationErrors('phone');
    }

    public function test_guest_is_unauthorized()
    {
        $this->getJson('/api/customers')->assertUnauthorized();
        $this->postJson('/api/customers', [])->assertUnauthorized();
        $this->getJson('/api/customers/1')->assertUnauthorized();
        $this->patchJson('/api/customers/1', [])->assertUnauthorized();
    }

    public function test_a_mailbox_does_not_turn_up_in_the_list_of_phone_numbers()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->withEmail('anna@example.com')->create();
        $customer->identities()->create([
            'corporation_id' => $customer->corporation_id,
            'address_type' => AddressType::Phone,
            'value' => '+4520123456',
        ]);

        $response = $this->actingAs($user)->getJson("/api/customers/{$customer->id}");

        $response->assertOk();
        $this->assertSame(['+4520123456'], array_column($response->json('customer.phones'), 'value'));
        $this->assertSame('anna@example.com', $response->json('customer.email'));
    }

    public function test_a_number_says_whether_it_has_carried_messages()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->create();

        $quiet = Identity::factory()->create([
            'corporation_id' => $user->corporation_id,
            'customer_id' => $customer->id,
            'value' => '+4520123456',
        ]);
        $used = Identity::factory()->create([
            'corporation_id' => $user->corporation_id,
            'customer_id' => $customer->id,
            'value' => '+4531234567',
        ]);
        Message::factory()->create([
            'corporation_id' => $user->corporation_id,
            'thread_id' => Thread::factory()->create([
                'corporation_id' => $user->corporation_id,
                'identity_id' => $used->id,
            ])->id,
        ]);

        $response = $this->actingAs($user)->getJson("/api/customers/{$customer->id}")->assertOk();

        // Removing one of these deletes it; removing the other moves history.
        $phones = collect($response->json('customer.phones'))->keyBy('id');
        $this->assertFalse($phones[$quiet->id]['hasMessages']);
        $this->assertTrue($phones[$used->id]['hasMessages']);
    }
}
