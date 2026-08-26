<?php

namespace Tests\Feature;

use App\Models\Corporation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CorporationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_belongs_to_a_corporation()
    {
        $corporation = Corporation::factory()->create();
        $user = User::factory()->for($corporation)->create();

        $this->assertTrue($user->corporation->is($corporation));
        $this->assertTrue($corporation->users->contains($user));
    }

    public function test_the_dashboard_shows_the_users_corporation_name()
    {
        $corporation = Corporation::factory()->create(['name' => 'Acme Inspections']);
        $user = User::factory()->for($corporation)->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('corporationName', 'Acme Inspections')
            );
    }
}
