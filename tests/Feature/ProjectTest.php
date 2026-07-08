<?php

namespace Tests\Feature;

use App\Models\Corporation;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_create_a_project()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/projects', ['name' => 'Nyhavnsgade 12']);

        $project = Project::where('name', 'Nyhavnsgade 12')->first();
        $this->assertNotNull($project);
        $this->assertTrue($project->corporation->is($user->corporation));
        $response->assertRedirect(route('projects.show', $project, absolute: false));
    }

    public function test_creating_a_project_records_an_event()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', ['name' => 'Nyhavnsgade 12']);

        $event = Event::where('type', 'project.created')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->corporation->is($user->corporation));
        $this->assertTrue($event->subject->is(Project::first()));
        $this->assertTrue($event->actor->is($user));
    }

    public function test_the_index_lists_only_the_own_corporations_projects()
    {
        $user = User::factory()->create();
        $own = Project::factory()->for($user->corporation)->create();
        Project::factory()->create(); // other corporation

        $this->actingAs($user)
            ->get('/projects')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->has('projects', 1)
                ->where('projects.0.id', $own->id)
            );
    }

    public function test_a_foreign_corporations_project_is_not_found()
    {
        $user = User::factory()->create();
        $foreign = Project::factory()->create();

        $this->actingAs($user)
            ->get("/projects/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_the_name_is_required()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/projects', ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Project::count());
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/projects')->assertRedirect('/login');
    }

    public function test_an_admin_can_update_a_project_name()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create(['name' => 'Old name']);

        $this->actingAs($user)
            ->patch("/projects/{$project->id}", ['name' => 'New name'])
            ->assertRedirect();

        $this->assertSame('New name', $project->fresh()->name);
    }

    public function test_updating_a_project_records_an_event()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();

        $this->actingAs($user)->patch("/projects/{$project->id}", ['name' => 'Updated']);

        $event = Event::where('type', 'project.updated')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->subject->is($project));
        $this->assertTrue($event->actor->is($user));
    }

    public function test_updating_a_foreign_project_returns_404()
    {
        $user = User::factory()->create();
        $foreign = Project::factory()->create();

        $this->actingAs($user)
            ->patch("/projects/{$foreign->id}", ['name' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_project_name_is_required_to_update()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create(['name' => 'Original']);

        $this->actingAs($user)
            ->patch("/projects/{$project->id}", ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame('Original', $project->fresh()->name);
    }

    public function test_an_admin_can_delete_a_project()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->delete("/projects/{$project->id}")
            ->assertRedirect(route('projects.index'));

        $this->assertSoftDeleted($project);
    }

    public function test_deleting_a_project_records_an_event()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();

        $this->actingAs($user)->delete("/projects/{$project->id}");

        $event = Event::where('type', 'project.deleted')->first();
        $this->assertNotNull($event);
        $this->assertSame($project->id, $event->subject_id);
    }

    public function test_deleting_a_project_detaches_its_work_orders()
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user->corporation)->create();
        $workOrder = \App\Models\WorkOrder::factory()->for($user->corporation)->for($project)->create();

        $this->actingAs($user)->delete("/projects/{$project->id}");

        $this->assertNull($workOrder->fresh()->project_id);
    }

    public function test_deleting_a_foreign_project_returns_404()
    {
        $user = User::factory()->create();
        $foreign = Project::factory()->create();

        $this->actingAs($user)
            ->delete("/projects/{$foreign->id}")
            ->assertNotFound();

        $this->assertNotSoftDeleted($foreign);
    }
}
