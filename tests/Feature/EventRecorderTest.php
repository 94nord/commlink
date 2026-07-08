<?php

namespace Tests\Feature;

use App\Models\Corporation;
use App\Models\User;
use App\Support\EventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_an_event_scoped_to_a_corporation()
    {
        $corporation = Corporation::factory()->create();

        $event = EventRecorder::record($corporation, 'something.happened');

        $this->assertTrue($event->corporation->is($corporation));
        $this->assertSame('something.happened', $event->type);
        $this->assertNull($event->actor);
        $this->assertNull($event->subject);
        $this->assertNull($event->payload);
        $this->assertNotNull($event->created_at);
    }

    public function test_the_payload_round_trips_as_an_array()
    {
        $corporation = Corporation::factory()->create();

        $event = EventRecorder::record($corporation, 'something.happened', payload: ['slot' => '2026-07-06 09:00']);

        $this->assertSame(['slot' => '2026-07-06 09:00'], $event->fresh()->payload);
    }

    public function test_it_stores_the_subject_polymorphically()
    {
        $corporation = Corporation::factory()->create();
        $user = User::factory()->for($corporation)->create();

        $event = EventRecorder::record($corporation, 'user.created', subject: $user);

        $this->assertTrue($event->fresh()->subject->is($user));
    }

    public function test_the_actor_defaults_to_the_authenticated_user()
    {
        $corporation = Corporation::factory()->create();
        $user = User::factory()->for($corporation)->create();

        $this->actingAs($user);

        $event = EventRecorder::record($corporation, 'something.happened');

        $this->assertTrue($event->fresh()->actor->is($user));
    }
}
