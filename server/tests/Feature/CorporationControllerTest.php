<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CorporationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_returns_the_corporation()
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->getJson('/api/corporation')
            ->assertOk()
            ->assertJson([
                'corporation' => [
                    'name' => $user->corporation->name,
                    'hasLogo' => false,
                ],
            ]);
    }

    public function test_there_is_no_route_to_change_the_name()
    {
        $user = User::factory()->admin()->create();
        $originalName = $user->corporation->name;

        $this->actingAs($user)->patchJson('/api/corporation', [
            'name' => 'Renamed Co',
        ])->assertStatus(405);

        $this->assertSame($originalName, $user->corporation->fresh()->name);
    }

    public function test_a_logo_can_be_uploaded_and_streamed()
    {
        Storage::fake();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->postJson('/api/corporation/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])->assertOk();

        $corporation = $user->corporation->fresh();
        $this->assertNotNull($corporation->logo_path);
        Storage::assertExists($corporation->logo_path);

        $this->actingAs($user)->get('/api/corporation/logo')->assertOk();
    }

    public function test_a_non_image_upload_is_rejected()
    {
        Storage::fake();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->postJson('/api/corporation/logo', [
            'logo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertJsonValidationErrors('logo');

        $this->assertNull($user->corporation->fresh()->logo_path);
    }

    public function test_a_logo_can_be_removed()
    {
        Storage::fake();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->postJson('/api/corporation/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]);
        $path = $user->corporation->fresh()->logo_path;

        $this->actingAs($user)->deleteJson('/api/corporation/logo')->assertNoContent();

        $this->assertNull($user->corporation->fresh()->logo_path);
        Storage::assertMissing($path);
    }

    public function test_guest_is_unauthorized()
    {
        $this->getJson('/api/corporation')->assertUnauthorized();
    }
}
