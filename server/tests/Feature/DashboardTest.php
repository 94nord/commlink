<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $this->actingAs($user = User::factory()->create());

        $this->get('/dashboard')->assertOk();
    }

    public function test_the_dashboard_ships_the_schedule()
    {
        $user = User::factory()->create();
        $workOrder = WorkOrder::factory()->for($user->corporation)->create();
        $staff = Staff::factory()->for($user->corporation)->create();
        Visit::factory()->for($user->corporation)
            ->scheduled($staff, now()->parse('2026-08-01 09:00'), now()->parse('2026-08-01 10:00'))
            ->create(['work_order_id' => $workOrder->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('corporationName')
                ->has('visits', 1)
                ->has('cases')
                ->has('staffOptions')
                ->where('visits.0.staffName', $staff->name)
                ->where('visits.0.caseAddress', $workOrder->address)
            );
    }

    public function test_the_dashboard_does_not_show_foreign_corporation_visits()
    {
        $user = User::factory()->create();
        Visit::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('visits', 0));
    }

    public function test_the_dashboard_excludes_cancelled_visits()
    {
        $user = User::factory()->create();
        Visit::factory()->for($user->corporation)->cancelled()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('visits', 0));
    }
}
