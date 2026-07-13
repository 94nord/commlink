<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\Round;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Staff $staff;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->staff = Staff::factory()->for($this->user->corporation)->create();
        $this->workOrder = WorkOrder::factory()->for($this->user->corporation)->create();
    }

    private function makeRound(array $attributes = []): Round
    {
        return Round::factory()->for($this->user->corporation)->open()->create(array_merge([
            'window_starts_on' => now()->addDay()->toDateString(),
            'window_ends_on' => now()->addDays(2)->toDateString(),
            'close_off_on' => now()->addDays(10)->toDateString(),
        ], $attributes));
    }

    private function link(Round $round, ?WorkOrder $workOrder = null): string
    {
        $token = Str::random(64);
        $round->workOrders()->attach(($workOrder ?? $this->workOrder)->id, ['token' => $token]);

        return "/book/{$token}";
    }

    private function slot(int $daysAhead = 1, string $time = '09:00'): string
    {
        return now()->addDays($daysAhead)->toDateString()." $time";
    }

    public function test_an_unknown_token_is_not_found()
    {
        $this->get('/book/does-not-exist')->assertNotFound();
    }

    public function test_a_draft_rounds_link_is_not_live_yet()
    {
        $round = Round::factory()->for($this->user->corporation)->create();
        $link = $this->link($round);

        $this->get($link)->assertNotFound();
        $this->post($link, ['starts_at' => $this->slot()])->assertNotFound();
    }

    public function test_the_booking_page_shows_slots_without_any_login()
    {
        $link = $this->link($this->makeRound());

        $this->get($link)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('booking/show')
                ->where('bookable', true)
                ->where('address', $this->workOrder->address)
                ->has('days', 2)
                ->has('days.0.slots', 8)
                ->where('booking', null)
            );
    }

    public function test_booking_a_slot_creates_the_visit()
    {
        $round = $this->makeRound();
        $link = $this->link($round);

        $this->post($link, ['starts_at' => $this->slot()])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $visit = Visit::first();
        $this->assertNotNull($visit);
        $this->assertTrue($visit->round->is($round));
        $this->assertTrue($visit->workOrder->is($this->workOrder));
        $this->assertSame(Customer::class, $visit->owner_type);
        $this->assertSame($this->workOrder->customer_id, $visit->owner_id);
        $this->assertSame($round->type, $visit->type);
        $this->assertTrue($visit->staff->is($this->staff));
        $this->assertSame('09:00', $visit->starts_at->format('H:i'));
        $this->assertSame('10:00', $visit->ends_at->format('H:i'));

        // The customer is the actor: recorded with no authenticated user.
        $created = Event::where('type', 'visit.created')->first();
        $this->assertNotNull($created);
        $this->assertNull($created->actor_id);
        $this->assertNotNull(Event::where('type', 'visit.scheduled')->first());
    }

    public function test_an_off_grid_time_is_rejected()
    {
        $link = $this->link($this->makeRound());

        $this->post($link, ['starts_at' => $this->slot(time: '09:17')])
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(0, Visit::count());
    }

    public function test_a_taken_slot_cannot_be_booked_twice_with_one_staff_member()
    {
        $round = $this->makeRound();
        $firstLink = $this->link($round);
        $otherCase = WorkOrder::factory()->for($this->user->corporation)->create();
        $secondLink = $this->link($round, $otherCase);

        $this->post($firstLink, ['starts_at' => $this->slot()])->assertSessionHasNoErrors();

        $this->post($secondLink, ['starts_at' => $this->slot()])
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(1, Visit::count());
    }

    public function test_a_second_staff_member_doubles_the_parallel_capacity()
    {
        Staff::factory()->for($this->user->corporation)->create();

        $round = $this->makeRound();
        $firstLink = $this->link($round);
        $otherCase = WorkOrder::factory()->for($this->user->corporation)->create();
        $secondLink = $this->link($round, $otherCase);

        $this->post($firstLink, ['starts_at' => $this->slot()])->assertSessionHasNoErrors();
        $this->post($secondLink, ['starts_at' => $this->slot()])->assertSessionHasNoErrors();

        $this->assertSame(2, Visit::count());
        $this->assertSame(2, Visit::query()->distinct('staff_id')->count('staff_id'));
    }

    public function test_rebooking_moves_the_existing_visit()
    {
        $link = $this->link($this->makeRound());

        $this->post($link, ['starts_at' => $this->slot()])->assertSessionHasNoErrors();
        $this->post($link, ['starts_at' => $this->slot(2, '13:00')])->assertSessionHasNoErrors();

        $this->assertSame(1, Visit::count());
        $this->assertSame('13:00', Visit::first()->starts_at->format('H:i'));
    }

    public function test_the_page_shows_the_existing_booking()
    {
        $link = $this->link($this->makeRound());
        $this->post($link, ['starts_at' => $this->slot()]);

        $this->get($link)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('bookable', true)
                ->whereNot('booking', null)
            );
    }

    public function test_booking_is_locked_after_close_off()
    {
        $round = $this->makeRound(['close_off_on' => now()->subDay()->toDateString()]);
        $link = $this->link($round);

        $this->get($link)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('bookable', false)->has('days', 0));

        $this->post($link, ['starts_at' => $this->slot()])
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(0, Visit::count());
    }

    public function test_booking_is_locked_on_a_closed_round()
    {
        $round = Round::factory()->for($this->user->corporation)->closed()->create([
            'window_starts_on' => now()->addDay()->toDateString(),
            'window_ends_on' => now()->addDays(2)->toDateString(),
        ]);
        $link = $this->link($round);

        $this->get($link)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('bookable', false));

        $this->post($link, ['starts_at' => $this->slot()])
            ->assertSessionHasErrors('starts_at');
    }

    public function test_a_cancelled_visit_frees_the_link_to_book_again()
    {
        $link = $this->link($this->makeRound());

        $this->post($link, ['starts_at' => $this->slot()]);
        Visit::first()->update(['cancelled_at' => now()]);

        $this->post($link, ['starts_at' => $this->slot(2, '10:00')])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Visit::count());
        $this->assertSame(1, Visit::whereNull('cancelled_at')->count());
    }
}
