<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerEraseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function makeCustomer(array $attributes = [], ?string $email = null): Customer
    {
        $factory = Customer::factory()->for($this->user->corporation);

        return ($email === null ? $factory : $factory->withEmail($email))->create($attributes);
    }

    public function test_erasing_scrubs_pii_and_soft_deletes()
    {
        $customer = $this->makeCustomer([
            'name' => 'Jane Private',
            'address' => 'Hidden Street 1',
        ], email: 'jane@example.com');

        $this->deleteJson("/api/customers/{$customer->id}")->assertNoContent();

        $erased = Customer::withTrashed()->find($customer->id);
        $this->assertTrue($erased->trashed());
        $this->assertSame('Erased customer', $erased->name);
        $this->assertNull($erased->address);

        // The mailbox needs no scrubbing of its own: it is an address like
        // their number, and the addresses went with the person.
        $this->assertNull($erased->fresh()->email);
        $this->assertSame(0, $erased->identities()->count());
    }

    public function test_erasing_records_a_customer_erased_event_with_no_pii()
    {
        $customer = $this->makeCustomer();

        $this->deleteJson("/api/customers/{$customer->id}")->assertNoContent();

        $event = Event::where('type', 'customer.erased')->first();
        $this->assertNotNull($event);
        $this->assertSame($customer->id, (int) $event->subject_id);
        $this->assertNull($event->payload);
    }

    public function test_an_erased_customer_is_hidden_from_the_index_and_show_404s()
    {
        $customer = $this->makeCustomer(['name' => 'Jane Private']);

        $this->deleteJson("/api/customers/{$customer->id}");

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(0, 'customers');
        $this->getJson("/api/customers/{$customer->id}")->assertNotFound();
    }

    public function test_the_email_is_reusable_after_erasure()
    {
        $customer = $this->makeCustomer(email: 'jane@example.com');

        $this->deleteJson("/api/customers/{$customer->id}");

        $replacement = $this->makeCustomer(email: 'jane@example.com');
        $this->assertSame('jane@example.com', $replacement->email);
    }

    public function test_erasure_is_tenant_scoped()
    {
        $foreign = Customer::factory()->create();

        $this->deleteJson("/api/customers/{$foreign->id}")->assertNotFound();
        $this->assertFalse($foreign->fresh()->trashed());
    }

    public function test_guest_is_unauthorized()
    {
        auth()->logout();

        $this->deleteJson('/api/customers/1')->assertUnauthorized();
    }

    public function test_erasing_releases_their_numbers()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->create();
        $identity = Identity::factory()->create([
            'corporation_id' => $user->corporation_id,
            'customer_id' => $customer->id,
            'value' => '+4520123456',
        ]);

        $this->actingAs($user)->deleteJson("/api/customers/{$customer->id}")->assertNoContent();

        // The number is personal data, and it must not be left pointing at
        // somebody who no longer exists — a later text from it arrives as a
        // stranger, which is what it now is.
        $this->assertSoftDeleted($identity);
        $this->assertSame(0, Identity::where('value', '+4520123456')->count());
    }
}
