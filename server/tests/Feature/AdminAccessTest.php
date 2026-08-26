<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function nonAdmin(): User
    {
        $user = User::factory()->create();
        Staff::factory()->for($user->corporation)->create([
            'user_id' => $user->id,
            'is_admin' => false,
        ]);

        return $user;
    }

    public function test_a_non_admin_cannot_reach_staff_or_corporation()
    {
        $user = $this->nonAdmin();

        $this->actingAs($user)->getJson('/api/staff')->assertForbidden();
        $this->actingAs($user)->getJson('/api/corporation')->assertForbidden();
        $this->actingAs($user)->postJson('/api/staff', ['name' => 'X'])->assertForbidden();
    }

    public function test_an_admin_can_reach_staff_and_corporation()
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->getJson('/api/staff')->assertOk();
        $this->actingAs($user)->getJson('/api/corporation')->assertOk();
    }

    public function test_the_is_admin_flag_is_shared_via_the_user_endpoint()
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->getJson('/api/user')
            ->assertJsonPath('is_admin', true);

        $member = $this->nonAdmin();
        $this->actingAs($member)
            ->getJson('/api/user')
            ->assertJsonPath('is_admin', false);
    }
}
