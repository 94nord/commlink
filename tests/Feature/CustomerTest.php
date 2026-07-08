<?php

namespace Tests\Feature;

use App\Enums\CustomerKind;
use App\Models\Corporation;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $customer = Customer::factory()->create(['email' => '  Anna.Larsen@Example.COM ']);

        $this->assertSame('anna.larsen@example.com', $customer->fresh()->email);
    }

    public function test_an_email_resolves_to_exactly_one_customer_within_a_corporation()
    {
        $corporation = Corporation::factory()->create();
        $customer = Customer::factory()->for($corporation)->create(['email' => 'anna@example.com']);

        $resolved = Customer::findByEmail($corporation, ' ANNA@Example.com ');

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($customer));
    }

    public function test_the_same_email_at_two_corporations_is_two_separate_customers()
    {
        $first = Corporation::factory()->create();
        $second = Corporation::factory()->create();
        $atFirst = Customer::factory()->for($first)->create(['email' => 'anna@example.com']);
        $atSecond = Customer::factory()->for($second)->create(['email' => 'anna@example.com']);

        $this->assertFalse($atFirst->is($atSecond));
        $this->assertTrue(Customer::findByEmail($first, 'anna@example.com')->is($atFirst));
        $this->assertTrue(Customer::findByEmail($second, 'anna@example.com')->is($atSecond));
    }

    public function test_a_duplicate_email_within_a_corporation_is_rejected()
    {
        $corporation = Corporation::factory()->create();
        Customer::factory()->for($corporation)->create(['email' => 'anna@example.com']);

        $this->expectException(QueryException::class);

        Customer::factory()->for($corporation)->create(['email' => 'Anna@Example.com']);
    }

    public function test_a_soft_deleted_customer_releases_its_email()
    {
        $corporation = Corporation::factory()->create();
        $erased = Customer::factory()->for($corporation)->create(['email' => 'anna@example.com']);
        $erased->delete();

        $replacement = Customer::factory()->for($corporation)->create(['email' => 'anna@example.com']);

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
