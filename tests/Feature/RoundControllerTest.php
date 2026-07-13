<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Project;
use App\Models\Round;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoundControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_a_draft_round_and_records_event()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/rounds', [
            'type' => 'handover',
            'appointment_minutes' => 45,
            'buffer_minutes' => 15,
            'window_starts_on' => '2026-08-01',
            'window_ends_on' => '2026-08-14',
            'close_off_on' => '2026-07-25',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $round = Round::first();
        $this->assertNotNull($round);
        $this->assertTrue($round->isDraft());
        $this->assertSame('handover', $round->type->value);
        $this->assertSame(45, $round->appointment_minutes);
        $this->assertSame(15, $round->buffer_minutes);
        $this->assertSame('2026-08-01', $round->window_starts_on->toDateString());
        $this->assertSame('2026-07-25', $round->close_off_on->toDateString());
        $this->assertNull($round->project_id);

        $this->assertNotNull(Event::where('type', 'round.created')->first());
    }

    public function test_store_can_file_the_round_under_a_project()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();

        $this->actingAs($user)->post('/rounds', [
            'type' => 'survey',
            'appointment_minutes' => 30,
            'close_off_on' => '2026-07-25',
            'project_id' => $project->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(Round::first()->project->is($project));
    }

    public function test_store_rejects_a_foreign_project()
    {
        $user = User::factory()->create();
        $foreignProject = Project::factory()->create();

        $this->actingAs($user)->post('/rounds', [
            'type' => 'survey',
            'appointment_minutes' => 30,
            'close_off_on' => '2026-07-25',
            'project_id' => $foreignProject->id,
        ])->assertNotFound();

        $this->assertSame(0, Round::count());
    }

    public function test_validation_rules()
    {
        $user = User::factory()->create();

        // required fields
        $this->actingAs($user)
            ->post('/rounds', [])
            ->assertSessionHasErrors(['type', 'appointment_minutes', 'close_off_on']);

        // unknown type, zero-length appointment
        $this->actingAs($user)->post('/rounds', [
            'type' => 'unknown',
            'appointment_minutes' => 0,
            'close_off_on' => '2026-07-25',
        ])->assertSessionHasErrors(['type', 'appointment_minutes']);

        // window fields are required together, end not before start
        $this->actingAs($user)->post('/rounds', [
            'type' => 'survey',
            'appointment_minutes' => 30,
            'close_off_on' => '2026-07-25',
            'window_starts_on' => '2026-08-01',
        ])->assertSessionHasErrors('window_ends_on');

        $this->actingAs($user)->post('/rounds', [
            'type' => 'survey',
            'appointment_minutes' => 30,
            'close_off_on' => '2026-07-25',
            'window_starts_on' => '2026-08-14',
            'window_ends_on' => '2026-08-01',
        ])->assertSessionHasErrors('window_ends_on');

        $this->assertSame(0, Round::count());
    }

    public function test_update_edits_a_draft_round()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create();

        $this->actingAs($user)->patch("/rounds/{$round->id}", [
            'type' => 'warranty',
            'appointment_minutes' => 90,
            'buffer_minutes' => 10,
            'close_off_on' => '2026-09-01',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $round->refresh();
        $this->assertSame('warranty', $round->type->value);
        $this->assertSame(90, $round->appointment_minutes);

        $this->assertNotNull(Event::where('type', 'round.updated')->first());
    }

    public function test_a_non_draft_round_cannot_be_edited()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->open()->create();

        $this->actingAs($user)->patch("/rounds/{$round->id}", [
            'type' => 'warranty',
            'appointment_minutes' => 90,
            'close_off_on' => '2026-09-01',
        ])->assertSessionHasErrors('status');

        $this->assertSame('survey', $round->fresh()->type->value);
    }

    public function test_open_requires_at_least_one_case()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->post("/rounds/{$round->id}/open")
            ->assertSessionHasErrors('status');

        $this->assertTrue($round->fresh()->isDraft());
    }

    public function test_store_accepts_a_scheduled_start()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/rounds', [
            'type' => 'survey',
            'appointment_minutes' => 30,
            'close_off_on' => now()->addMonth()->toDateString(),
            'scheduled_open_at' => now()->addDay()->toIso8601String(),
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(Round::first()->scheduled_open_at);
    }

    public function test_a_scheduled_start_in_the_past_is_rejected()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/rounds', [
            'type' => 'survey',
            'appointment_minutes' => 30,
            'close_off_on' => now()->addMonth()->toDateString(),
            'scheduled_open_at' => now()->subHour()->toIso8601String(),
        ])->assertSessionHasErrors('scheduled_open_at');

        $this->assertSame(0, Round::count());
    }

    public function test_update_can_clear_the_scheduled_start()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create([
            'scheduled_open_at' => now()->addDay(),
        ]);

        $this->actingAs($user)->patch("/rounds/{$round->id}", [
            'type' => 'survey',
            'appointment_minutes' => 60,
            'close_off_on' => now()->addMonth()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertNull($round->fresh()->scheduled_open_at);
    }

    public function test_a_manual_open_overrides_the_scheduled_start()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create([
            'scheduled_open_at' => now()->addDay(),
        ]);
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $round->workOrders()->attach($workOrder->id);

        $this->actingAs($user)
            ->post("/rounds/{$round->id}/open")
            ->assertSessionHasNoErrors();

        $round->refresh();
        $this->assertTrue($round->isOpen());
        $this->assertNull($round->scheduled_open_at);

        $this->assertSame('manual', Event::where('type', 'round.opened')->first()->payload['trigger']);
    }

    public function test_open_requires_a_window()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create([
            'window_starts_on' => null,
            'window_ends_on' => null,
        ]);
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $round->workOrders()->attach($workOrder->id);

        $this->actingAs($user)
            ->post("/rounds/{$round->id}/open")
            ->assertSessionHasErrors('status');

        $this->assertTrue($round->fresh()->isDraft());
    }

    public function test_store_defaults_the_bookable_hours()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/rounds', [
            'type' => 'survey',
            'appointment_minutes' => 30,
            'close_off_on' => '2026-07-25',
        ])->assertSessionHasNoErrors();

        $round = Round::first();
        $this->assertStringStartsWith('08:00', $round->day_starts_at);
        $this->assertStringStartsWith('16:00', $round->day_ends_at);
    }

    public function test_the_bookable_day_must_end_after_it_starts()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/rounds', [
            'type' => 'survey',
            'appointment_minutes' => 30,
            'close_off_on' => '2026-07-25',
            'day_starts_at' => '16:00',
            'day_ends_at' => '08:00',
        ])->assertSessionHasErrors('day_ends_at');

        $this->assertSame(0, Round::count());
    }

    public function test_adding_a_case_generates_its_booking_token()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();

        $this->actingAs($user)->post("/rounds/{$round->id}/cases", [
            'work_order_ids' => [$workOrder->id],
        ])->assertSessionHasNoErrors();

        $token = $round->workOrders()->first()->pivot->token;
        $this->assertNotNull($token);
        $this->assertSame(64, strlen($token));
    }

    public function test_re_adding_does_not_rotate_an_existing_token()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();

        $this->actingAs($user)->post("/rounds/{$round->id}/cases", ['work_order_ids' => [$workOrder->id]]);
        $original = $round->workOrders()->first()->pivot->token;

        $this->actingAs($user)->post("/rounds/{$round->id}/cases", ['work_order_ids' => [$workOrder->id]]);

        $this->assertSame($original, $round->workOrders()->first()->pivot->token);
    }

    public function test_show_exposes_booking_url_and_booked_state()
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        $round = Round::factory()->for($user->corporation)->open()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $round->workOrders()->attach($workOrder->id, ['token' => str_repeat('a', 64)]);

        Visit::factory()->for($user->corporation)
            ->scheduled($staff, now()->addDay(), now()->addDay()->addHour())
            ->create(['work_order_id' => $workOrder->id, 'round_id' => $round->id]);

        $this->actingAs($user)
            ->get("/rounds/{$round->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cases.0.bookingUrl', route('booking.show', str_repeat('a', 64)))
                ->whereNot('cases.0.bookedAt', null)
            );
    }

    public function test_open_transitions_a_draft_round_with_cases()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $round->workOrders()->attach($workOrder->id);

        $this->actingAs($user)
            ->post("/rounds/{$round->id}/open")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($round->fresh()->isOpen());
        $this->assertNotNull(Event::where('type', 'round.opened')->first());
    }

    public function test_close_transitions_an_open_round()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->open()->create();

        $this->actingAs($user)
            ->post("/rounds/{$round->id}/close")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($round->fresh()->isClosed());
        $this->assertNotNull(Event::where('type', 'round.closed')->first());
    }

    public function test_close_rejects_a_draft_round()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->post("/rounds/{$round->id}/close")
            ->assertSessionHasErrors('status');

        $this->assertTrue($round->fresh()->isDraft());
    }

    public function test_destroy_soft_deletes_a_draft_round()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->delete("/rounds/{$round->id}")
            ->assertRedirect(route('rounds.index'))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($round);
        $this->assertNotNull(Event::where('type', 'round.deleted')->first());
    }

    public function test_a_non_draft_round_cannot_be_deleted()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->open()->create();

        $this->actingAs($user)
            ->delete("/rounds/{$round->id}")
            ->assertSessionHasErrors('status');

        $this->assertNotSoftDeleted($round);
    }

    public function test_cases_can_be_added_to_a_draft_round()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create();
        $workOrders = WorkOrder::factory()->for($user->corporation)->count(2)->create();

        $this->actingAs($user)->post("/rounds/{$round->id}/cases", [
            'work_order_ids' => $workOrders->pluck('id')->all(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, $round->workOrders()->count());

        $event = Event::where('type', 'round.cases_added')->first();
        $this->assertNotNull($event);
        $this->assertEqualsCanonicalizing($workOrders->pluck('id')->all(), $event->payload['work_order_ids']);
    }

    public function test_late_adds_are_allowed_while_open()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->open()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();

        $this->actingAs($user)->post("/rounds/{$round->id}/cases", [
            'work_order_ids' => [$workOrder->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $round->workOrders()->count());
    }

    public function test_cases_cannot_be_added_to_a_closed_round()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->closed()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();

        $this->actingAs($user)->post("/rounds/{$round->id}/cases", [
            'work_order_ids' => [$workOrder->id],
        ])->assertSessionHasErrors('work_order_ids');

        $this->assertSame(0, $round->workOrders()->count());
    }

    public function test_adding_an_existing_case_is_skipped_not_duplicated()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create();
        $existing = WorkOrder::factory()->for($user->corporation)->create();
        $new = WorkOrder::factory()->for($user->corporation)->create();
        $round->workOrders()->attach($existing->id);

        $this->actingAs($user)->post("/rounds/{$round->id}/cases", [
            'work_order_ids' => [$existing->id, $new->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, $round->workOrders()->count());

        // Only the actually-added case lands in the event payload.
        $event = Event::where('type', 'round.cases_added')->first();
        $this->assertSame([$new->id], $event->payload['work_order_ids']);
    }

    public function test_a_foreign_case_cannot_be_added()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create();
        $foreignWorkOrder = WorkOrder::factory()->create();

        $this->actingAs($user)->post("/rounds/{$round->id}/cases", [
            'work_order_ids' => [$foreignWorkOrder->id],
        ])->assertNotFound();

        $this->assertSame(0, $round->workOrders()->count());
    }

    public function test_a_case_can_be_removed_from_a_draft_round()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $round->workOrders()->attach($workOrder->id);

        $this->actingAs($user)
            ->delete("/rounds/{$round->id}/cases/{$workOrder->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $round->workOrders()->count());
        $this->assertNotNull(Event::where('type', 'round.case_removed')->first());
    }

    public function test_a_case_cannot_be_removed_once_open()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->open()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $round->workOrders()->attach($workOrder->id);

        $this->actingAs($user)
            ->delete("/rounds/{$round->id}/cases/{$workOrder->id}")
            ->assertSessionHasErrors('work_order_ids');

        $this->assertSame(1, $round->workOrders()->count());
    }

    public function test_index_lists_own_corporation_rounds()
    {
        $user = User::factory()->create();
        Round::factory()->for($user->corporation)->count(2)->create();
        Round::factory()->create(); // foreign

        $this->actingAs($user)
            ->get('/rounds')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('rounds/index')
                ->has('rounds', 2)
                ->has('projects')
            );
    }

    public function test_show_returns_round_cases_and_addable_cases()
    {
        $user = User::factory()->create();
        $round = Round::factory()->for($user->corporation)->create();
        $member = WorkOrder::factory()->for($user->corporation)->create();
        $addable = WorkOrder::factory()->for($user->corporation)->create();
        $round->workOrders()->attach($member->id);

        $this->actingAs($user)
            ->get("/rounds/{$round->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('rounds/show')
                ->where('round.id', $round->id)
                ->has('cases', 1)
                ->where('cases.0.id', $member->id)
                ->has('addableCases', 1)
                ->where('addableCases.0.id', $addable->id)
            );
    }

    public function test_foreign_round_is_not_found()
    {
        $user = User::factory()->create();
        $foreignRound = Round::factory()->create();

        $this->actingAs($user)->get("/rounds/{$foreignRound->id}")->assertNotFound();
        $this->actingAs($user)->post("/rounds/{$foreignRound->id}/open")->assertNotFound();
        $this->actingAs($user)->delete("/rounds/{$foreignRound->id}")->assertNotFound();
    }

    public function test_the_project_page_lists_rounds_filed_under_it()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();
        Round::factory()->for($user->corporation)->create(['project_id' => $project->id]);
        Round::factory()->for($user->corporation)->create(); // not filed under it

        $this->actingAs($user)
            ->get("/projects/{$project->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rounds', 1));
    }

    public function test_guest_is_redirected()
    {
        $this->get('/rounds')->assertRedirect('/login');
        $this->post('/rounds', [])->assertRedirect('/login');
        $this->get('/rounds/1')->assertRedirect('/login');
        $this->post('/rounds/1/open')->assertRedirect('/login');
        $this->post('/rounds/1/cases', [])->assertRedirect('/login');
    }
}
