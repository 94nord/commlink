<?php

namespace Tests\Feature\Auth;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileTokenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TestCase sets an Origin matching SANCTUM_STATEFUL_DOMAINS on every
     * request, which makes Sanctum treat it as a first-party SPA call and
     * prefer the session guard. The phone sends no Origin, so drop it here or
     * these tests would never touch the bearer-token path they exist to cover.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutHeader('Origin');
    }

    public function test_a_device_can_exchange_credentials_for_a_token()
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/mobile/token', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => "Sebastian's iPhone",
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'is_admin']]);

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => "Sebastian's iPhone",
        ]);
    }

    public function test_the_token_authenticates_subsequent_requests()
    {
        $user = User::factory()->create();

        $token = $this->postJson('/api/mobile/token', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'iPhone',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_the_api_stays_closed_without_a_token()
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_an_invalid_password_issues_no_token()
    {
        $user = User::factory()->create();

        $this->postJson('/api/mobile/token', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'iPhone',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_device_name_is_required()
    {
        $user = User::factory()->create();

        $this->postJson('/api/mobile/token', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('device_name');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logging_out_revokes_only_the_calling_device()
    {
        $user = User::factory()->create();

        $phone = $user->createToken('iPhone')->plainTextToken;
        $tablet = $user->createToken('iPad')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$phone}")
            ->postJson('/api/mobile/logout')
            ->assertNoContent();

        // Each real request resolves the guard fresh; in-process it is cached
        // on the AuthManager, so drop it or the revoked token still "works".
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$phone}")
            ->getJson('/api/user')
            ->assertUnauthorized();

        $this->withHeader('Authorization', "Bearer {$tablet}")
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_a_mobile_login_records_an_event()
    {
        $user = User::factory()->create();

        $this->postJson('/api/mobile/token', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'iPhone',
        ]);

        $event = Event::where('type', 'user.logged_in')->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->corporation->is($user->corporation));
        $this->assertTrue($event->actor->is($user));
        $this->assertTrue($event->subject->is($user));
    }
}
