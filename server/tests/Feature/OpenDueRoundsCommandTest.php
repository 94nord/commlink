<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Round;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenDueRoundsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function makeDueRound(array $attributes = []): Round
    {
        return Round::factory()->for($this->user->corporation)->create(array_merge([
            'scheduled_open_at' => now()->subMinute(),
        ], $attributes));
    }

    private function attachCase(Round $round): void
    {
        $workOrder = WorkOrder::factory()->for($this->user->corporation)->create();
        $round->workOrders()->attach($workOrder->id);
    }

    public function test_a_due_round_with_cases_opens()
    {
        $round = $this->makeDueRound();
        $this->attachCase($round);

        $this->artisan('app:open-due-rounds')->assertSuccessful();

        $this->assertTrue($round->fresh()->isOpen());

        $event = Event::where('type', 'round.opened')->first();
        $this->assertNotNull($event);
        $this->assertSame('scheduled', $event->payload['trigger']);
        $this->assertNull($event->actor_id);
    }

    public function test_a_future_schedule_does_not_fire()
    {
        $round = $this->makeDueRound(['scheduled_open_at' => now()->addHour()]);
        $this->attachCase($round);

        $this->artisan('app:open-due-rounds')->assertSuccessful();

        $this->assertTrue($round->fresh()->isDraft());
    }

    public function test_an_unscheduled_draft_is_untouched()
    {
        $round = $this->makeDueRound(['scheduled_open_at' => null]);
        $this->attachCase($round);

        $this->artisan('app:open-due-rounds')->assertSuccessful();

        $this->assertTrue($round->fresh()->isDraft());
    }

    public function test_a_due_round_without_cases_is_skipped_and_keeps_its_schedule()
    {
        $round = $this->makeDueRound();

        $this->artisan('app:open-due-rounds')->assertSuccessful();

        $round->refresh();
        $this->assertTrue($round->isDraft());
        $this->assertNotNull($round->scheduled_open_at);

        // As soon as it becomes valid, the next run opens it.
        $this->attachCase($round);
        $this->artisan('app:open-due-rounds')->assertSuccessful();

        $this->assertTrue($round->fresh()->isOpen());
    }

    public function test_a_due_round_without_a_window_is_skipped()
    {
        $round = $this->makeDueRound(['window_starts_on' => null, 'window_ends_on' => null]);
        $this->attachCase($round);

        $this->artisan('app:open-due-rounds')->assertSuccessful();

        $this->assertTrue($round->fresh()->isDraft());
    }

    public function test_open_and_closed_rounds_are_untouched()
    {
        $open = Round::factory()->for($this->user->corporation)->open()->create([
            'scheduled_open_at' => now()->subMinute(),
        ]);
        $closed = Round::factory()->for($this->user->corporation)->closed()->create([
            'scheduled_open_at' => now()->subMinute(),
        ]);

        $this->artisan('app:open-due-rounds')->assertSuccessful();

        $this->assertTrue($open->fresh()->isOpen());
        $this->assertTrue($closed->fresh()->isClosed());
        $this->assertSame(0, Event::where('type', 'round.opened')->count());
    }
}
