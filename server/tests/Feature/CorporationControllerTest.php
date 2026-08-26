<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CorporationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_renders_the_settings_page()
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->get('/corporation')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('corporation/edit')
                ->where('corporation.name', $user->corporation->name)
                ->where('corporation.hasLogo', false)
            );
    }

    public function test_update_changes_the_name_and_records_an_event()
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->patch('/corporation', [
            'name' => 'Renamed Co',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Renamed Co', $user->corporation->fresh()->name);
        $this->assertNotNull(Event::where('type', 'corporation.updated')->first());
    }

    public function test_a_logo_can_be_uploaded_and_streamed()
    {
        Storage::fake();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post('/corporation/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $corporation = $user->corporation->fresh();
        $this->assertNotNull($corporation->logo_path);
        Storage::assertExists($corporation->logo_path);

        $this->actingAs($user)->get('/corporation/logo')->assertOk();
    }

    public function test_a_non_image_upload_is_rejected()
    {
        Storage::fake();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post('/corporation/logo', [
            'logo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('logo');

        $this->assertNull($user->corporation->fresh()->logo_path);
    }

    public function test_a_logo_can_be_removed()
    {
        Storage::fake();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post('/corporation/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]);
        $path = $user->corporation->fresh()->logo_path;

        $this->actingAs($user)->delete('/corporation/logo')->assertRedirect();

        $this->assertNull($user->corporation->fresh()->logo_path);
        Storage::assertMissing($path);
    }

    public function test_guest_is_redirected()
    {
        $this->get('/corporation')->assertRedirect('/login');
    }
}
