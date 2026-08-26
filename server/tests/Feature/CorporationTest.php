<?php

namespace Tests\Feature;

use App\Models\Corporation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
