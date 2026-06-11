<?php

namespace Tests\Feature;

use App\Models\Corporation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateCorporationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_corporation_with_its_first_admin_user()
    {
        $this->artisan('app:create-corporation', [
            '--corporation' => 'Acme Inspections',
            '--name' => 'Jane Admin',
            '--email' => 'jane@example.com',
            '--password' => 'secret-password',
        ])->assertSuccessful();

        $corporation = Corporation::where('name', 'Acme Inspections')->first();
        $this->assertNotNull($corporation);

        $user = $corporation->users()->where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }

    public function test_the_created_user_can_log_in()
    {
        $this->artisan('app:create-corporation', [
            '--corporation' => 'Acme Inspections',
            '--name' => 'Jane Admin',
            '--email' => 'jane@example.com',
            '--password' => 'secret-password',
        ])->assertSuccessful();

        $response = $this->post('/login', [
            'email' => 'jane@example.com',
            'password' => 'secret-password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_it_rejects_a_duplicate_email()
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->artisan('app:create-corporation', [
            '--corporation' => 'Acme Inspections',
            '--name' => 'Jane Admin',
            '--email' => 'jane@example.com',
            '--password' => 'secret-password',
        ])->assertFailed();

        $this->assertDatabaseMissing('corporations', ['name' => 'Acme Inspections']);
    }
}
