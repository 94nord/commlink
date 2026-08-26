<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_own_corporation_work_orders()
    {
        $user = User::factory()->create();
        WorkOrder::factory()->for($user->corporation)->count(3)->create();
        WorkOrder::factory()->count(2)->create(); // other corp

        $response = $this->actingAs($user)->get('/cases');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('cases/index')
            ->has('cases', 3)
        );
    }

    public function test_store_creates_a_standalone_case_with_title_and_notes()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->create();

        $this->actingAs($user)->post('/work-orders', [
            'customer_id' => $customer->id,
            'title' => 'Apartment 3B',
            'address' => 'Main Street 1',
            'notes' => 'Gate code 1234',
        ])->assertRedirect();

        $workOrder = WorkOrder::first();
        $this->assertNotNull($workOrder);
        $this->assertSame('Apartment 3B', $workOrder->title);
        $this->assertSame('Main Street 1', $workOrder->address);
        $this->assertSame('Gate code 1234', $workOrder->notes);
        $this->assertTrue($workOrder->customer->is($customer));
        $this->assertNull($workOrder->project_id);

        $this->assertNotNull(Event::where('type', 'work_order.created')->first());
    }

    public function test_update_edits_title_address_notes_and_project()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create(['title' => null]);

        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}", [
            'title' => 'New Title',
            'address' => 'Moved Street 9',
            'notes' => 'Updated notes',
            'project_id' => $project->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $workOrder->refresh();
        $this->assertSame('New Title', $workOrder->title);
        $this->assertSame('Moved Street 9', $workOrder->address);
        $this->assertSame('Updated notes', $workOrder->notes);
        $this->assertSame($project->id, $workOrder->project_id);

        $this->assertNotNull(Event::where('type', 'work_order.updated')->first());
    }

    public function test_update_rejects_a_foreign_case_or_project()
    {
        $user = User::factory()->create();
        $foreignCase = WorkOrder::factory()->create();
        $ownCase = WorkOrder::factory()->for($user->corporation)->create();
        $foreignProject = Project::factory()->create();

        $this->actingAs($user)->patch("/work-orders/{$foreignCase->id}", [
            'address' => 'X',
        ])->assertNotFound();

        $this->actingAs($user)->patch("/work-orders/{$ownCase->id}", [
            'address' => 'X',
            'project_id' => $foreignProject->id,
        ])->assertNotFound();
    }

    public function test_show_exposes_title_and_customer_id()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create(['title' => 'Roof']);

        $this->actingAs($user)
            ->get("/cases/{$workOrder->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('cases/show')
                ->where('workOrder.title', 'Roof')
                ->where('workOrder.customerId', $workOrder->customer_id)
                ->has('projectOptions')
            );
    }

    public function test_store_creates_a_case_linked_to_a_project()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->create();
        $project = Project::factory()->for($user->corporation)->create();

        $this->actingAs($user)->post('/work-orders', [
            'customer_id' => $customer->id,
            'address' => 'Main Street 1',
            'project_id' => $project->id,
        ])->assertRedirect();

        $this->assertSame($project->id, WorkOrder::first()->project_id);
    }

    public function test_store_rejects_a_foreign_customer()
    {
        $user = User::factory()->create();
        $foreignCustomer = Customer::factory()->create();

        $this->actingAs($user)->post('/work-orders', [
            'customer_id' => $foreignCustomer->id,
            'address' => 'Main Street 1',
        ])->assertNotFound();

        $this->assertSame(0, WorkOrder::count());
    }

    public function test_store_rejects_a_foreign_project()
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->for($user->corporation)->create();
        $foreignProject = Project::factory()->create();

        $this->actingAs($user)->post('/work-orders', [
            'customer_id' => $customer->id,
            'address' => 'Main Street 1',
            'project_id' => $foreignProject->id,
        ])->assertNotFound();

        $this->assertSame(0, WorkOrder::count());
    }

    public function test_destroy_deletes_a_work_order_and_records_event()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->delete("/work-orders/{$workOrder->id}")
            ->assertRedirect();

        $this->assertSoftDeleted($workOrder);

        $event = Event::where('type', 'work_order.deleted')->first();
        $this->assertNotNull($event);
        $this->assertSame($workOrder->id, $event->subject_id);
        $this->assertTrue($event->actor->is($user));
    }

    public function test_destroy_returns_404_for_foreign_corporation_work_order()
    {
        $user = User::factory()->create();
        $foreign = WorkOrder::factory()->create();

        $this->actingAs($user)
            ->delete("/work-orders/{$foreign->id}")
            ->assertNotFound();

        $this->assertNotSoftDeleted($foreign);
    }

    public function test_guest_is_redirected()
    {
        $this->get('/cases')->assertRedirect('/login');
        $this->patch('/work-orders/1', [])->assertRedirect('/login');
    }
}
