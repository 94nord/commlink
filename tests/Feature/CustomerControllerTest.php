<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
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

        $response = $this->actingAs($user)->get('/customers');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('customers/index')
            ->has('customers', 3)
        );
    }

    public function test_store_creates_a_customer_and_records_event()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/customers', [
            'name' => 'Anna Larsen',
            'email' => 'anna@example.com',
        ])->assertRedirect(route('customers.index'));

        $customer = Customer::first();
        $this->assertNotNull($customer);
        $this->assertSame('Anna Larsen', $customer->name);
        $this->assertSame('anna@example.com', $customer->email);
        $this->assertTrue($customer->corporation->is($user->corporation));

        $event = Event::where('type', 'customer.created')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->subject->is($customer));
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
    }
}
