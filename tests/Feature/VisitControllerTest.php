<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_corporation_visits_with_case_and_staff_data()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        Visit::factory()->for($user->corporation)
            ->scheduled($staff, now()->parse('2026-08-01 09:00'), now()->parse('2026-08-01 10:00'))
            ->create(['work_order_id' => $workOrder->id]);

        $this->actingAs($user)
            ->get('/visits')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('visits/index')
                ->has('visits', 1)
                ->has('cases')
                ->has('staffOptions')
                ->where('visits.0.staffName', $staff->name)
                ->where('visits.0.caseAddress', $workOrder->address)
            );
    }

    public function test_index_does_not_show_foreign_corporation_visits()
    {
        $user = User::factory()->create();
        Visit::factory()->create();

        $this->actingAs($user)
            ->get('/visits')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('visits', 0));
    }

    public function test_store_from_index_creates_a_scheduled_visit()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->post('/visits', [
                'work_order_id' => $workOrder->id,
                'type' => 'progress',
                'staff_id' => $staff->id,
                'starts_at' => '2026-08-01 09:00',
                'ends_at' => '2026-08-01 10:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $visit = Visit::first();
        $this->assertNotNull($visit);
        $this->assertTrue($visit->workOrder->is($workOrder));
        $this->assertTrue($visit->staff->is($staff));
        $this->assertSame('progress', $visit->type->value);
        $this->assertSame('2026-08-01 09:00', $visit->starts_at->format('Y-m-d H:i'));

        $this->assertNotNull(Event::where('type', 'visit.created')->first());
    }

    public function test_destroy_soft_deletes_a_visit_and_records_event()
    {
        $user = User::factory()->create();
        $visit = Visit::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->delete("/visits/{$visit->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($visit);
        $this->assertNotNull(Event::where('type', 'visit.deleted')->first());
    }

    public function test_destroy_foreign_visit_is_not_found()
    {
        $user = User::factory()->create();
        $foreignVisit = Visit::factory()->create();

        $this->actingAs($user)
            ->delete("/visits/{$foreignVisit->id}")
            ->assertNotFound();

        $this->assertNotSoftDeleted($foreignVisit);
    }

    public function test_store_from_index_rejects_foreign_case_and_staff()
    {
        $user = User::factory()->create();
        $foreignWorkOrder = WorkOrder::factory()->create();
        $foreignStaff = Staff::factory()->create();
        $ownWorkOrder = WorkOrder::factory()->for($user->corporation)->create();
        $ownStaff = Staff::factory()->for($user->corporation)->create();

        $this->actingAs($user)->post('/visits', [
            'work_order_id' => $foreignWorkOrder->id,
            'type' => 'survey',
            'staff_id' => $ownStaff->id,
            'starts_at' => '2026-08-01 09:00',
            'ends_at' => '2026-08-01 10:00',
        ])->assertNotFound();

        $this->actingAs($user)->post('/visits', [
            'work_order_id' => $ownWorkOrder->id,
            'type' => 'survey',
            'staff_id' => $foreignStaff->id,
            'starts_at' => '2026-08-01 09:00',
            'ends_at' => '2026-08-01 10:00',
        ])->assertNotFound();

        $this->assertSame(0, Visit::count());
    }

    public function test_a_visit_can_be_linked_to_a_round_the_case_belongs_to()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        $round = \App\Models\Round::factory()->for($user->corporation)->create();
        $round->workOrders()->attach($workOrder->id, ['token' => str_repeat('a', 64)]);

        $this->actingAs($user)->post("/cases/{$workOrder->id}/visits", [
            'type' => 'survey',
            'staff_id' => $staff->id,
            'starts_at' => '2026-08-01 09:00',
            'ends_at' => '2026-08-01 10:00',
            'round_id' => $round->id,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Visit::first()->round->is($round));
    }

    public function test_linking_requires_the_case_to_be_in_the_round()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        $round = \App\Models\Round::factory()->for($user->corporation)->create();

        $this->actingAs($user)->post("/cases/{$workOrder->id}/visits", [
            'type' => 'survey',
            'staff_id' => $staff->id,
            'starts_at' => '2026-08-01 09:00',
            'ends_at' => '2026-08-01 10:00',
            'round_id' => $round->id,
        ])->assertSessionHasErrors('round_id');

        $this->assertSame(0, Visit::count());
    }

    public function test_a_round_holds_at_most_one_active_visit_per_case()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        $round = \App\Models\Round::factory()->for($user->corporation)->create();
        $round->workOrders()->attach($workOrder->id, ['token' => str_repeat('a', 64)]);
        Visit::factory()->for($user->corporation)
            ->scheduled($staff, now()->parse('2026-08-01 09:00'), now()->parse('2026-08-01 10:00'))
            ->create(['work_order_id' => $workOrder->id, 'round_id' => $round->id]);

        $this->actingAs($user)->post("/cases/{$workOrder->id}/visits", [
            'type' => 'survey',
            'staff_id' => $staff->id,
            'starts_at' => '2026-08-02 09:00',
            'ends_at' => '2026-08-02 10:00',
            'round_id' => $round->id,
        ])->assertSessionHasErrors('round_id');

        $this->assertSame(1, Visit::count());
    }

    public function test_update_can_clear_the_round_link()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        $round = \App\Models\Round::factory()->for($user->corporation)->create();
        $round->workOrders()->attach($workOrder->id, ['token' => str_repeat('a', 64)]);
        $visit = Visit::factory()->for($user->corporation)
            ->scheduled($staff, now()->parse('2026-08-01 09:00'), now()->parse('2026-08-01 10:00'))
            ->create(['work_order_id' => $workOrder->id, 'round_id' => $round->id]);

        $this->actingAs($user)->patch("/visits/{$visit->id}", [
            'staff_id' => $staff->id,
            'starts_at' => '2026-08-01 09:00',
            'ends_at' => '2026-08-01 10:00',
        ])->assertSessionHasNoErrors();

        $this->assertNull($visit->fresh()->round_id);
    }

    public function test_a_closed_round_cannot_take_a_visit()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        $round = \App\Models\Round::factory()->for($user->corporation)->closed()->create();
        $round->workOrders()->attach($workOrder->id, ['token' => str_repeat('a', 64)]);

        $this->actingAs($user)->post("/cases/{$workOrder->id}/visits", [
            'type' => 'survey',
            'staff_id' => $staff->id,
            'starts_at' => '2026-08-01 09:00',
            'ends_at' => '2026-08-01 10:00',
            'round_id' => $round->id,
        ])->assertSessionHasErrors('round_id');
    }

    public function test_a_foreign_round_is_not_found()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        $foreignRound = \App\Models\Round::factory()->create();

        $this->actingAs($user)->post("/cases/{$workOrder->id}/visits", [
            'type' => 'survey',
            'staff_id' => $staff->id,
            'starts_at' => '2026-08-01 09:00',
            'ends_at' => '2026-08-01 10:00',
            'round_id' => $foreignRound->id,
        ])->assertNotFound();
    }

    public function test_store_creates_a_scheduled_visit_owned_by_the_cases_customer()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->post("/cases/{$workOrder->id}/visits", [
                'type' => 'survey',
                'staff_id' => $staff->id,
                'starts_at' => '2026-08-01 09:00',
                'ends_at' => '2026-08-01 10:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $visit = Visit::first();
        $this->assertNotNull($visit);
        $this->assertSame(Customer::class, $visit->owner_type);
        $this->assertSame($workOrder->customer_id, $visit->owner_id);
        $this->assertTrue($visit->workOrder->is($workOrder));
        $this->assertSame('survey', $visit->type->value);
        $this->assertTrue($visit->staff->is($staff));
        $this->assertSame('2026-08-01 09:00', $visit->starts_at->format('Y-m-d H:i'));

        $this->assertNotNull(Event::where('type', 'visit.created')->first());
        $this->assertNotNull(Event::where('type', 'visit.scheduled')->first());
    }

    public function test_a_scheduling_collision_rolls_back_the_whole_creation()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        Visit::factory()->for($user->corporation)
            ->scheduled($staff, now()->parse('2026-08-01 09:00'), now()->parse('2026-08-01 10:00'))
            ->create();

        $this->actingAs($user)->post("/cases/{$workOrder->id}/visits", [
            'type' => 'survey',
            'staff_id' => $staff->id,
            'starts_at' => '2026-08-01 09:30',
            'ends_at' => '2026-08-01 10:30',
        ])->assertSessionHasErrors('starts_at');

        $this->assertSame(1, Visit::count());
    }

    public function test_update_reschedules_a_visit()
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        $visit = Visit::factory()->for($user->corporation)->create();

        $this->actingAs($user)->patch("/visits/{$visit->id}", [
            'staff_id' => $staff->id,
            'starts_at' => '2026-08-01 09:00',
            'ends_at' => '2026-08-01 10:00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $visit->refresh();
        $this->assertTrue($visit->staff->is($staff));
        $this->assertSame('2026-08-01 09:00', $visit->starts_at->format('Y-m-d H:i'));
    }

    public function test_a_cancelled_visit_cannot_be_rescheduled()
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        $visit = Visit::factory()->for($user->corporation)->cancelled()->create();

        $this->actingAs($user)->patch("/visits/{$visit->id}", [
            'staff_id' => $staff->id,
            'starts_at' => '2026-08-01 09:00',
            'ends_at' => '2026-08-01 10:00',
        ])->assertSessionHasErrors('starts_at');
    }

    public function test_cancel_sets_cancelled_at_and_records_an_event()
    {
        $user = User::factory()->create();
        $visit = Visit::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->post("/visits/{$visit->id}/cancel")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($visit->fresh()->isCancelled());
        $this->assertNotNull(Event::where('type', 'visit.cancelled')->first());
    }

    public function test_cancelling_twice_errors()
    {
        $user = User::factory()->create();
        $visit = Visit::factory()->for($user->corporation)->cancelled()->create();

        $this->actingAs($user)
            ->post("/visits/{$visit->id}/cancel")
            ->assertSessionHasErrors('visit');

        $this->assertSame(0, Event::where('type', 'visit.cancelled')->count());
    }

    public function test_foreign_case_and_visit_are_not_found()
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        $foreignWorkOrder = WorkOrder::factory()->create();
        $foreignVisit = Visit::factory()->create();

        $this->actingAs($user)
            ->post("/cases/{$foreignWorkOrder->id}/visits", [
                'type' => 'survey',
                'staff_id' => $staff->id,
                'starts_at' => '2026-08-01 09:00',
                'ends_at' => '2026-08-01 10:00',
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->post("/visits/{$foreignVisit->id}/cancel")
            ->assertNotFound();

        $this->assertSame(1, Visit::count());
        $this->assertFalse($foreignVisit->fresh()->isCancelled());
    }

    public function test_a_foreign_staff_member_is_not_found()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $foreignStaff = Staff::factory()->create();

        $this->actingAs($user)->post("/cases/{$workOrder->id}/visits", [
            'type' => 'survey',
            'staff_id' => $foreignStaff->id,
            'starts_at' => '2026-08-01 09:00',
            'ends_at' => '2026-08-01 10:00',
        ])->assertNotFound();

        $this->assertSame(0, Visit::count());
    }

    public function test_validation_rules()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();

        // type required + must be a known type
        $this->actingAs($user)
            ->post("/cases/{$workOrder->id}/visits", [])
            ->assertSessionHasErrors('type');
        $this->actingAs($user)
            ->post("/cases/{$workOrder->id}/visits", ['type' => 'unknown'])
            ->assertSessionHasErrors('type');

        // staff and times are required
        $this->actingAs($user)
            ->post("/cases/{$workOrder->id}/visits", ['type' => 'survey'])
            ->assertSessionHasErrors(['staff_id', 'starts_at', 'ends_at']);

        // end must be after start
        $this->actingAs($user)->post("/cases/{$workOrder->id}/visits", [
            'type' => 'survey',
            'staff_id' => $staff->id,
            'starts_at' => '2026-08-01 10:00',
            'ends_at' => '2026-08-01 09:00',
        ])->assertSessionHasErrors('ends_at');

        $this->assertSame(0, Visit::count());
    }

    public function test_the_case_page_shows_its_visits()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        Visit::factory()->for($user->corporation)->count(2)->create(['work_order_id' => $workOrder->id]);

        $this->actingAs($user)
            ->get("/cases/{$workOrder->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('cases/show')
                ->has('visits', 2)
                ->where('visits.0.caseId', $workOrder->id)
                ->where('visits.0.caseAddress', $workOrder->address)
                ->has('staffOptions')
            );
    }

    public function test_show_displays_a_visit_with_its_linked_entities()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        $round = \App\Models\Round::factory()->for($user->corporation)->create();
        $round->workOrders()->attach($workOrder->id, ['token' => str_repeat('a', 64)]);
        $visit = Visit::factory()->for($user->corporation)
            ->scheduled($staff, now()->parse('2026-08-01 09:00'), now()->parse('2026-08-01 10:00'))
            ->create(['work_order_id' => $workOrder->id, 'round_id' => $round->id]);

        $this->actingAs($user)
            ->get("/visits/{$visit->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('visits/show')
                ->where('visit.id', $visit->id)
                ->where('visit.staffName', $staff->name)
                ->where('visit.caseId', $workOrder->id)
                ->where('visit.caseAddress', $workOrder->address)
                ->where('visit.customerId', $workOrder->customer->id)
                ->where('visit.customerName', $workOrder->customer->name)
                ->where('visit.roundId', $round->id)
                ->where('visit.roundLabel', fn ($label) => str_contains($label, 'round · closes'))
                ->has('cases', 1)
                ->has('staffOptions')
            );
    }

    public function test_show_renders_a_cancelled_visit()
    {
        $user = User::factory()->create();
        $visit = Visit::factory()->for($user->corporation)->cancelled()->create();

        $this->actingAs($user)
            ->get("/visits/{$visit->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('visits/show')
                ->whereNot('visit.cancelledAt', null)
            );
    }

    public function test_show_offers_only_open_rounds_of_the_visits_case()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $openRound = \App\Models\Round::factory()->for($user->corporation)->open()->create();
        $closedRound = \App\Models\Round::factory()->for($user->corporation)->closed()->create();
        $openRound->workOrders()->attach($workOrder->id, ['token' => str_repeat('a', 64)]);
        $closedRound->workOrders()->attach($workOrder->id, ['token' => str_repeat('b', 64)]);
        $visit = Visit::factory()->for($user->corporation)->create(['work_order_id' => $workOrder->id]);

        $this->actingAs($user)
            ->get("/visits/{$visit->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('cases.0.rounds', 1)
                ->where('cases.0.rounds.0.id', $openRound->id)
            );
    }

    public function test_show_handles_a_visit_without_a_case()
    {
        $user = User::factory()->create();
        $visit = Visit::factory()->for($user->corporation)->create(['work_order_id' => null]);

        $this->actingAs($user)
            ->get("/visits/{$visit->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('visit.caseId', null)
                ->where('visit.customerId', null)
                ->has('cases', 0)
            );
    }

    public function test_show_foreign_visit_is_not_found()
    {
        $user = User::factory()->create();
        $visit = Visit::factory()->create();

        $this->actingAs($user)
            ->get("/visits/{$visit->id}")
            ->assertNotFound();
    }

    public function test_guest_is_redirected()
    {
        $this->get('/visits')->assertRedirect('/login');
        $this->get('/visits/1')->assertRedirect('/login');
        $this->post('/visits', [])->assertRedirect('/login');
        $this->delete('/visits/1')->assertRedirect('/login');
        $this->get('/cases/1')->assertRedirect('/login');
        $this->post('/cases/1/visits', [])->assertRedirect('/login');
        $this->post('/visits/1/cancel')->assertRedirect('/login');
    }
}
