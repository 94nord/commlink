<?php

namespace Tests\Feature\Auth;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_authenticate_using_the_login_endpoint()
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_logging_in_records_an_event()
    {
        $user = User::factory()->create();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $event = Event::where('type', 'user.logged_in')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->corporation->is($user->corporation));
        $this->assertTrue($event->actor->is($user));
        $this->assertTrue($event->subject->is($user));
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/logout');

        $this->assertGuest('web');
        $response->assertNoContent();
    }
}
