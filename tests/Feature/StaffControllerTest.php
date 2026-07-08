<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_own_corporation_staff()
    {
        $user = User::factory()->create();
        Staff::factory()->for($user->corporation)->count(2)->create();
        Staff::factory()->count(3)->create(); // other corps

        $response = $this->actingAs($user)->get('/staff');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('staff/index')
            ->has('staff', 2)
        );
    }

    public function test_store_creates_a_staff_member_and_records_event()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/staff', ['name' => 'Bo Jensen'])
            ->assertRedirect(route('staff.index'));

        $staff = Staff::where('name', 'Bo Jensen')->first();
        $this->assertNotNull($staff);
        $this->assertTrue($staff->corporation->is($user->corporation));

        $event = Event::where('type', 'staff.created')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->subject->is($staff));
        $this->assertTrue($event->actor->is($user));
    }

    public function test_update_renames_a_staff_member_and_records_event()
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->for($user->corporation)->create(['name' => 'Old Name']);

        $this->actingAs($user)
            ->patch("/staff/{$staff->id}", ['name' => 'New Name'])
            ->assertRedirect();

        $this->assertSame('New Name', $staff->fresh()->name);
        $this->assertNotNull(Event::where('type', 'staff.updated')->first());
    }

    public function test_updating_a_foreign_staff_member_returns_404()
    {
        $user = User::factory()->create();
        $foreign = Staff::factory()->create(['name' => 'Foreign']);

        $this->actingAs($user)
            ->patch("/staff/{$foreign->id}", ['name' => 'Hacked'])
            ->assertNotFound();

        $this->assertSame('Foreign', $foreign->fresh()->name);
    }

    public function test_destroy_deletes_a_staff_member_when_another_remains()
    {
        $user = User::factory()->create();
        [$first, $second] = Staff::factory()->for($user->corporation)->count(2)->create();

        $this->actingAs($user)
            ->delete("/staff/{$second->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($second);
        $this->assertNotSoftDeleted($first);

        $event = Event::where('type', 'staff.deleted')->first();
        $this->assertNotNull($event);
        $this->assertSame($second->id, $event->subject_id);
    }

    public function test_the_last_staff_member_cannot_be_deleted()
    {
        $user = User::factory()->create();
        $only = Staff::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->delete("/staff/{$only->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('staff');

        $this->assertNotSoftDeleted($only);
        $this->assertNull(Event::where('type', 'staff.deleted')->first());
    }

    public function test_destroying_a_foreign_staff_member_returns_404()
    {
        $user = User::factory()->create();
        $foreign = Staff::factory()->create();

        $this->actingAs($user)
            ->delete("/staff/{$foreign->id}")
            ->assertNotFound();

        $this->assertNotSoftDeleted($foreign);
    }

    public function test_name_is_required()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/staff', [])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Staff::count());
    }

    public function test_guest_is_redirected()
    {
        $this->get('/staff')->assertRedirect('/login');
        $this->post('/staff', [])->assertRedirect('/login');
    }
}
