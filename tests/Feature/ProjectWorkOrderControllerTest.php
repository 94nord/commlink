<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectWorkOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attaches_existing_standalone_cases_to_a_project()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();
        $cases = WorkOrder::factory()->for($user->corporation)->count(2)->create(['project_id' => null]);

        $this->actingAs($user)->post("/projects/{$project->id}/work-orders", [
            'work_order_ids' => $cases->pluck('id')->all(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        foreach ($cases as $case) {
            $this->assertSame($project->id, $case->fresh()->project_id);
        }

        $event = Event::where('type', 'project.cases_added')->first();
        $this->assertNotNull($event);
        $this->assertEqualsCanonicalizing($cases->pluck('id')->all(), $event->payload['work_order_ids']);
    }

    public function test_a_case_already_in_a_project_cannot_be_attached()
    {
        $user = User::factory()->create();
        $otherProject = Project::factory()->for($user->corporation)->create();
        $project = Project::factory()->for($user->corporation)->create();
        $case = WorkOrder::factory()->for($user->corporation)->create(['project_id' => $otherProject->id]);

        $this->actingAs($user)->post("/projects/{$project->id}/work-orders", [
            'work_order_ids' => [$case->id],
        ])->assertNotFound();

        $this->assertSame($otherProject->id, $case->fresh()->project_id);
    }

    public function test_a_foreign_case_cannot_be_attached()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();
        $foreignCase = WorkOrder::factory()->create(['project_id' => null]);

        $this->actingAs($user)->post("/projects/{$project->id}/work-orders", [
            'work_order_ids' => [$foreignCase->id],
        ])->assertNotFound();

        $this->assertNull($foreignCase->fresh()->project_id);
    }

    public function test_it_detaches_a_case_from_a_project()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();
        $case = WorkOrder::factory()->for($user->corporation)->create(['project_id' => $project->id]);

        $this->actingAs($user)
            ->delete("/projects/{$project->id}/work-orders/{$case->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull($case->fresh()->project_id);
        $this->assertNotSoftDeleted($case);
        $this->assertNotNull(Event::where('type', 'project.case_removed')->first());
    }

    public function test_detaching_a_case_that_is_not_in_the_project_is_not_found()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();
        $standalone = WorkOrder::factory()->for($user->corporation)->create(['project_id' => null]);

        $this->actingAs($user)
            ->delete("/projects/{$project->id}/work-orders/{$standalone->id}")
            ->assertNotFound();
    }

    public function test_guest_is_redirected()
    {
        $this->post('/projects/1/work-orders', [])->assertRedirect('/login');
        $this->delete('/projects/1/work-orders/1')->assertRedirect('/login');
    }
}
