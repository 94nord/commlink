<?php

namespace Tests\Feature;

use App\Enums\AddressType;
use App\Enums\CustomerKind;
use App\Inbox\Actions\AddCustomerAddress;
use App\Models\Corporation;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_belongs_to_a_corporation()
    {
        $corporation = Corporation::factory()->create();
        $customer = Customer::factory()->for($corporation)->create();

        $this->assertTrue($customer->corporation->is($corporation));
        $this->assertTrue($corporation->customers->contains($customer));
    }

    public function test_a_customer_may_be_a_person_or_an_organization()
    {
        $person = Customer::factory()->create();
        $organization = Customer::factory()->organization()->create();

        $this->assertSame(CustomerKind::Person, $person->kind);
        $this->assertSame(CustomerKind::Organization, $organization->kind);
    }

    public function test_the_email_is_normalized_on_write()
    {
        $customer = Customer::factory()->create();

        // Through the real write path, which is what the customer form and
        // the future email adapter both go through.
        app(AddCustomerAddress::class)->handle($customer, AddressType::Email, '  Anna.Larsen@Example.COM ');

        $this->assertSame('anna.larsen@example.com', $customer->fresh()->email);
    }

    public function test_an_email_nobody_could_send_to_is_refused()
    {
        $customer = Customer::factory()->create();

        $this->expectException(ValidationException::class);

        app(AddCustomerAddress::class)->handle($customer, AddressType::Email, 'not-an-address');
    }

    public function test_an_email_resolves_to_exactly_one_customer_within_a_corporation()
    {
        $corporation = Corporation::factory()->create();
        $customer = Customer::factory()->for($corporation)->withEmail('anna@example.com')->create();

        $resolved = Customer::findByEmail($corporation, ' ANNA@Example.com ');

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($customer));
    }

    public function test_an_unparseable_email_resolves_to_nobody_rather_than_blowing_up()
    {
        $corporation = Corporation::factory()->create();

        $this->assertNull(Customer::findByEmail($corporation, 'not-an-address'));
    }

    public function test_the_same_email_at_two_corporations_is_two_separate_customers()
    {
        $first = Corporation::factory()->create();
        $second = Corporation::factory()->create();
        $atFirst = Customer::factory()->for($first)->withEmail('anna@example.com')->create();
        $atSecond = Customer::factory()->for($second)->withEmail('anna@example.com')->create();

        $this->assertFalse($atFirst->is($atSecond));
        $this->assertTrue(Customer::findByEmail($first, 'anna@example.com')->is($atFirst));
        $this->assertTrue(Customer::findByEmail($second, 'anna@example.com')->is($atSecond));
    }

    public function test_a_duplicate_email_within_a_corporation_is_rejected()
    {
        $corporation = Corporation::factory()->create();
        Customer::factory()->for($corporation)->withEmail('anna@example.com')->create();

        // Now the identities index rather than a column on customers — one
        // live owner per address, whatever kind of address it is.
        $this->expectException(QueryException::class);

        Customer::factory()->for($corporation)->withEmail('anna@example.com')->create();
    }

    public function test_a_soft_deleted_customer_releases_its_email()
    {
        $corporation = Corporation::factory()->create();
        $erased = Customer::factory()->for($corporation)->withEmail('anna@example.com')->create();

        // Erasure soft-deletes the addresses with the person; that is what
        // releases the mailbox now that it is not a column.
        $erased->identities()->delete();
        $erased->delete();

        $replacement = Customer::factory()->for($corporation)->withEmail('anna@example.com')->create();

        $this->assertTrue(Customer::findByEmail($corporation, 'anna@example.com')->is($replacement));
    }

    public function test_one_host_record_cannot_map_to_two_customers_in_a_corporation()
    {
        $corporation = Corporation::factory()->create();
        Customer::factory()->for($corporation)->create(['external_ref' => 'host-4711']);

        $this->expectException(QueryException::class);

        Customer::factory()->for($corporation)->create(['external_ref' => 'host-4711']);
    }

    public function test_standalone_customers_do_not_collide_on_the_missing_external_ref()
    {
        $corporation = Corporation::factory()->create();
        $first = Customer::factory()->for($corporation)->create();
        $second = Customer::factory()->for($corporation)->create();

        $this->assertNull($first->external_ref);
        $this->assertNull($second->external_ref);
        $this->assertFalse($first->isExternallyLinked());
    }

    public function test_the_same_host_ref_may_exist_at_two_corporations()
    {
        $linkedAtFirst = Customer::factory()->create(['external_ref' => 'host-4711']);
        $linkedAtSecond = Customer::factory()->create(['external_ref' => 'host-4711']);

        $this->assertTrue($linkedAtFirst->isExternallyLinked());
        $this->assertTrue($linkedAtSecond->isExternallyLinked());
    }
}
