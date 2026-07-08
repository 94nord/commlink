<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddCustomerToProjectTest extends TestCase
{
    use RefreshDatabase;

    private function addApartment(User $user, Project $project, Customer $customer, array $overrides = [])
    {
        return $this->actingAs($user)->post("/projects/{$project->id}/work-orders", $overrides + [
            'customer_id' => $customer->id,
            'address' => 'Nyhavnsgade 12, 3. tv',
        ]);
    }

    public function test_adding_a_customer_to_a_project_creates_a_work_order()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();
        $customer = Customer::factory()->for($user->corporation)->create([
            'name' => 'Anna Larsen',
            'email' => 'anna@example.com',
        ]);

        $this->addApartment($user, $project, $customer)->assertRedirect();

        $workOrder = WorkOrder::first();
        $this->assertNotNull($workOrder);
        $this->assertTrue($workOrder->customer->is($customer));
        $this->assertTrue($workOrder->project->is($project));
        $this->assertTrue($workOrder->corporation->is($user->corporation));
        $this->assertSame('Nyhavnsgade 12, 3. tv', $workOrder->address);
    }

    public function test_it_records_a_work_order_created_event()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();
        $customer = Customer::factory()->for($user->corporation)->create();

        $this->addApartment($user, $project, $customer);

        $event = Event::where('type', 'work_order.created')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->subject->is(WorkOrder::first()));
        $this->assertTrue($event->actor->is($user));
    }

    public function test_the_same_customer_can_be_added_to_a_project_twice_with_different_addresses()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();
        $customer = Customer::factory()->for($user->corporation)->create();

        $this->addApartment($user, $project, $customer, ['address' => 'Nyhavnsgade 12, 1. th']);
        $this->addApartment($user, $project, $customer, ['address' => 'Nyhavnsgade 12, 2. mf']);

        $this->assertSame(2, WorkOrder::count());
        $this->assertSame(2, Event::where('type', 'work_order.created')->count());
    }

    public function test_a_customer_from_another_corporation_cannot_be_used()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();
        $foreignCustomer = Customer::factory()->create();

        $this->addApartment($user, $project, $foreignCustomer)->assertNotFound();

        $this->assertSame(0, WorkOrder::count());
    }

    public function test_a_customer_cannot_be_added_to_a_foreign_corporations_project()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->create();
        $foreignProject = Project::factory()->create();

        $this->addApartment($user, $foreignProject, $customer)->assertNotFound();

        $this->assertSame(0, WorkOrder::count());
    }

    public function test_customer_id_and_address_are_required()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->post("/projects/{$project->id}/work-orders", [])
            ->assertSessionHasErrors(['customer_id', 'address']);

        $this->assertSame(0, WorkOrder::count());
    }
}
