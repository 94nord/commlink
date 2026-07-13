<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StaffControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_own_corporation_staff()
    {
        $user = User::factory()->admin()->create(); // + the founder staff
        Staff::factory()->for($user->corporation)->count(2)->create();
        Staff::factory()->count(3)->create(); // other corps

        $this->actingAs($user)
            ->get('/staff')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('staff/index')
                ->has('staff', 3) // founder + 2
            );
    }

    public function test_store_creates_a_staff_member_with_email_and_role()
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post('/staff', [
            'name' => 'Bo Jensen',
            'email' => 'bo@example.com',
            'is_admin' => true,
        ])->assertRedirect(route('staff.index'));

        $staff = Staff::where('name', 'Bo Jensen')->first();
        $this->assertNotNull($staff);
        $this->assertSame('bo@example.com', $staff->email);
        $this->assertTrue($staff->is_admin);
        $this->assertNull($staff->user_id); // login only comes via invite
        $this->assertTrue($staff->corporation->is($user->corporation));

        $this->assertNotNull(Event::where('type', 'staff.created')->first());
    }

    public function test_update_edits_name_email_and_role()
    {
        $user = User::factory()->admin()->create();
        $staff = Staff::factory()->for($user->corporation)->create(['name' => 'Old Name']);

        $this->actingAs($user)->patch("/staff/{$staff->id}", [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'is_admin' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $staff->refresh();
        $this->assertSame('New Name', $staff->name);
        $this->assertSame('new@example.com', $staff->email);
        $this->assertTrue($staff->is_admin);
        $this->assertNotNull(Event::where('type', 'staff.updated')->first());
    }

    public function test_the_last_admin_with_a_login_cannot_be_demoted()
    {
        $user = User::factory()->admin()->create();
        Staff::factory()->for($user->corporation)->create(); // a second, non-admin staff

        $this->actingAs($user)->patch("/staff/{$user->staff->id}", [
            'name' => $user->name,
            'is_admin' => false,
        ])->assertSessionHasErrors('is_admin');

        $this->assertTrue($user->staff->fresh()->is_admin);
    }

    public function test_updating_a_foreign_staff_member_returns_404()
    {
        $user = User::factory()->admin()->create();
        $foreign = Staff::factory()->create(['name' => 'Foreign']);

        $this->actingAs($user)
            ->patch("/staff/{$foreign->id}", ['name' => 'Hacked'])
            ->assertNotFound();

        $this->assertSame('Foreign', $foreign->fresh()->name);
    }

    public function test_destroy_deletes_a_staff_member_when_another_remains()
    {
        $user = User::factory()->admin()->create();
        $second = Staff::factory()->for($user->corporation)->create();

        $this->actingAs($user)
            ->delete("/staff/{$second->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($second);
        $this->assertNotSoftDeleted($user->staff); // founder remains

        $this->assertNotNull(Event::where('type', 'staff.deleted')->first());
    }

    public function test_deleting_a_staff_member_removes_its_linked_login()
    {
        $user = User::factory()->admin()->create();
        // A second admin so the founder isn't the last admin.
        $second = Staff::factory()->for($user->corporation)->create(['is_admin' => true]);
        $secondUser = User::factory()->create(['corporation_id' => $user->corporation_id]);
        $second->update(['user_id' => $secondUser->id]);

        $this->actingAs($user)
            ->delete("/staff/{$second->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($second);
        $this->assertModelMissing($secondUser);
    }

    public function test_the_last_staff_member_cannot_be_deleted()
    {
        $user = User::factory()->admin()->create(); // founder is the only staff

        $this->actingAs($user)
            ->delete("/staff/{$user->staff->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('staff');

        $this->assertNotSoftDeleted($user->staff);
    }

    public function test_the_last_admin_with_a_login_cannot_be_deleted()
    {
        $user = User::factory()->admin()->create();
        Staff::factory()->for($user->corporation)->create(); // non-admin second staff

        $this->actingAs($user)
            ->delete("/staff/{$user->staff->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('staff');

        $this->assertNotSoftDeleted($user->staff);
    }

    public function test_invite_creates_a_login_and_sends_a_set_password_email()
    {
        Notification::fake();

        $user = User::factory()->admin()->create();
        $staff = Staff::factory()->for($user->corporation)->create(['email' => 'invitee@example.com']);

        $this->actingAs($user)
            ->post("/staff/{$staff->id}/invite")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $staff->refresh();
        $this->assertNotNull($staff->user_id);
        $this->assertTrue($staff->user->corporation->is($user->corporation));

        Notification::assertSentTo($staff->user, ResetPassword::class);
        $this->assertNotNull(Event::where('type', 'staff.login_invited')->first());
    }

    public function test_invite_requires_an_email()
    {
        $user = User::factory()->admin()->create();
        $staff = Staff::factory()->for($user->corporation)->create(['email' => null]);

        $this->actingAs($user)
            ->post("/staff/{$staff->id}/invite")
            ->assertSessionHasErrors('email');

        $this->assertNull($staff->fresh()->user_id);
    }

    public function test_resending_an_invite_reuses_the_existing_login()
    {
        Notification::fake();

        $user = User::factory()->admin()->create();
        $staff = Staff::factory()->for($user->corporation)->create(['email' => 'again@example.com']);

        $this->actingAs($user)->post("/staff/{$staff->id}/invite")->assertSessionHasNoErrors();
        $firstUserId = $staff->fresh()->user_id;

        $this->actingAs($user)->post("/staff/{$staff->id}/invite")->assertSessionHasNoErrors();

        $this->assertSame($firstUserId, $staff->fresh()->user_id);
    }

    public function test_name_is_required()
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post('/staff', [])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Staff::count()); // only the founder
    }

    public function test_guest_is_redirected()
    {
        $this->get('/staff')->assertRedirect('/login');
        $this->post('/staff', [])->assertRedirect('/login');
    }
}
