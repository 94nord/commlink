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

        $this->actingAs($user)->get('/staff')->assertForbidden();
        $this->actingAs($user)->get('/corporation')->assertForbidden();
        $this->actingAs($user)->post('/staff', ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->patch('/corporation', ['name' => 'X'])->assertForbidden();
    }

    public function test_an_admin_can_reach_staff_and_corporation()
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get('/staff')->assertOk();
        $this->actingAs($user)->get('/corporation')->assertOk();
    }

    public function test_the_is_admin_flag_is_shared_to_the_frontend()
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.user.is_admin', true));

        $member = $this->nonAdmin();
        $this->actingAs($member)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.user.is_admin', false));
    }
}
