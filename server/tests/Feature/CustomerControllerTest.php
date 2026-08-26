<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\User;
use App\Models\WorkOrder;
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

        $response = $this->actingAs($user)->get('/customers');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('customers/index')
            ->has('customers', 3)
        );
    }

    public function test_store_creates_a_customer_with_address_and_records_event()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/customers', [
            'name' => 'Anna Larsen',
            'email' => 'anna@example.com',
            'address' => 'Harbour Street 1',
        ])->assertRedirect(route('customers.index'));

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

    public function test_show_lists_the_customers_cases()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->create();
        WorkOrder::factory()->for($user->corporation)->count(2)->create(['customer_id' => $customer->id]);
        WorkOrder::factory()->for($user->corporation)->create(); // another customer

        $this->actingAs($user)
            ->get("/customers/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('customers/show')
                ->where('customer.id', $customer->id)
                ->has('cases', 2)
            );
    }

    public function test_a_foreign_customer_is_not_found()
    {
        $user = User::factory()->create();
        $foreign = Customer::factory()->create();

        $this->actingAs($user)->get("/customers/{$foreign->id}")->assertNotFound();
        $this->actingAs($user)->patch("/customers/{$foreign->id}", [
            'name' => 'X', 'email' => 'x@example.com',
        ])->assertNotFound();
    }

    public function test_update_edits_a_customer_and_records_event()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->create(['name' => 'Old']);

        $this->actingAs($user)->patch("/customers/{$customer->id}", [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'address' => 'New Address 5',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('New Name', $customer->name);
        $this->assertSame('new@example.com', $customer->email);
        $this->assertSame('New Address 5', $customer->address);
        $this->assertNotNull(Event::where('type', 'customer.updated')->first());
    }

    public function test_update_keeps_the_customers_own_email()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->create(['email' => 'keep@example.com']);

        $this->actingAs($user)->patch("/customers/{$customer->id}", [
            'name' => 'Renamed',
            'email' => 'keep@example.com',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $customer->fresh()->name);
    }

    public function test_update_rejects_another_customers_email()
    {
        $user = User::factory()->create();
        Customer::factory()->for($user->corporation)->create(['email' => 'taken@example.com']);
        $customer = Customer::factory()->for($user->corporation)->create(['email' => 'mine@example.com']);

        $this->actingAs($user)->patch("/customers/{$customer->id}", [
            'name' => 'Whoever',
            'email' => 'taken@example.com',
        ])->assertSessionHasErrors('email');
    }

    public function test_store_rejects_duplicate_email_within_corporation()
    {
        $user = User::factory()->create();
        Customer::factory()->for($user->corporation)->create(['email' => 'anna@example.com']);

        $this->actingAs($user)->post('/customers', [
            'name' => 'Anna Larsen',
            'email' => 'anna@example.com',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, Customer::count());
    }

    public function test_same_email_at_different_corporation_is_allowed()
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        Customer::factory()->for($userA->corporation)->create(['email' => 'shared@example.com']);

        $this->actingAs($userB)->post('/customers', [
            'name' => 'Shared Person',
            'email' => 'shared@example.com',
        ])->assertRedirect(route('customers.index'));

        $this->assertSame(2, Customer::count());
    }

    public function test_name_and_email_are_required()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/customers', [])
            ->assertSessionHasErrors(['name', 'email']);
    }

    public function test_guest_is_redirected()
    {
        $this->get('/customers')->assertRedirect('/login');
        $this->post('/customers', [])->assertRedirect('/login');
        $this->get('/customers/1')->assertRedirect('/login');
        $this->patch('/customers/1', [])->assertRedirect('/login');
    }
}
