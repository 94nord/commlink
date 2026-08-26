<?php

namespace Tests\Feature;

use App\Actions\ScheduleVisit;
use App\Models\Event;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VisitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->staff = Staff::factory()->for($this->user->corporation)->create();
        $this->actingAs($this->user);
    }

    private function schedule(Visit $visit, Staff $staff, string $from, string $to): Visit
    {
        return (new ScheduleVisit)->handle(
            $visit,
            $staff,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
        );
    }

    private function makeVisit(): Visit
    {
        return Visit::factory()->for($this->user->corporation)->create();
    }

    public function test_scheduling_sets_staff_and_times_and_records_an_event()
    {
        $visit = $this->makeVisit();

        $this->schedule($visit, $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');

        $visit->refresh();
        $this->assertTrue($visit->staff->is($this->staff));
        $this->assertSame('2026-08-01 09:00', $visit->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-08-01 10:00', $visit->ends_at->format('Y-m-d H:i'));

        $event = Event::where('type', 'visit.scheduled')->first();
        $this->assertNotNull($event);
        $this->assertSame($visit->id, $event->subject_id);
    }

    public function test_an_exact_overlap_on_the_same_staff_is_rejected()
    {
        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');

        $this->expectException(ValidationException::class);
        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');
    }

    public function test_a_partial_front_overlap_is_rejected()
    {
        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');

        $this->expectException(ValidationException::class);
        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 08:30', '2026-08-01 09:30');
    }

    public function test_a_partial_back_overlap_is_rejected()
    {
        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');

        $this->expectException(ValidationException::class);
        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 09:30', '2026-08-01 10:30');
    }

    public function test_a_containing_overlap_is_rejected()
    {
        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');

        $this->expectException(ValidationException::class);
        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 08:00', '2026-08-01 11:00');
    }

    public function test_adjacent_slots_do_not_collide()
    {
        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');

        $second = $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 10:00', '2026-08-01 11:00');

        $this->assertNotNull($second->fresh()->starts_at);
    }

    public function test_a_cancelled_visit_releases_its_time()
    {
        $first = $this->makeVisit();
        $this->schedule($first, $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');
        $first->update(['cancelled_at' => now()]);

        $second = $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');

        $this->assertSame('2026-08-01 09:00', $second->fresh()->starts_at->format('Y-m-d H:i'));
    }

    public function test_the_same_time_on_a_different_staff_member_is_allowed()
    {
        $otherStaff = Staff::factory()->for($this->user->corporation)->create();

        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');
        $second = $this->schedule($this->makeVisit(), $otherStaff, '2026-08-01 09:00', '2026-08-01 10:00');

        $this->assertNotNull($second->fresh()->starts_at);
    }

    public function test_a_visit_can_be_moved_to_a_free_time()
    {
        $visit = $this->makeVisit();
        $this->schedule($visit, $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');

        $this->schedule($visit, $this->staff, '2026-08-02 13:00', '2026-08-02 14:00');

        $visit->refresh();
        $this->assertSame('2026-08-02 13:00', $visit->starts_at->format('Y-m-d H:i'));
    }

    public function test_a_visit_does_not_collide_with_itself_when_resized()
    {
        $visit = $this->makeVisit();
        $this->schedule($visit, $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');

        // Resize: extend the end within its own original window.
        $this->schedule($visit, $this->staff, '2026-08-01 09:00', '2026-08-01 11:00');

        $this->assertSame('2026-08-01 11:00', $visit->fresh()->ends_at->format('Y-m-d H:i'));
    }

    public function test_moving_onto_another_visits_time_is_rejected()
    {
        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');

        $moving = $this->makeVisit();
        $this->schedule($moving, $this->staff, '2026-08-01 11:00', '2026-08-01 12:00');

        $this->expectException(ValidationException::class);
        $this->schedule($moving, $this->staff, '2026-08-01 09:30', '2026-08-01 10:30');
    }

    public function test_the_end_must_be_after_the_start()
    {
        $this->expectException(ValidationException::class);
        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 10:00', '2026-08-01 10:00');
    }

    public function test_cross_corporation_staff_is_rejected()
    {
        $foreignStaff = Staff::factory()->create();

        $this->expectException(ValidationException::class);
        $this->schedule($this->makeVisit(), $foreignStaff, '2026-08-01 09:00', '2026-08-01 10:00');
    }

    public function test_an_exclusion_violation_is_recognized_as_an_overlap()
    {
        $pdo = new \PDOException('conflicting key value violates exclusion constraint "visits_no_staff_overlap"');
        $pdo->errorInfo = ['23P01', 7, 'conflicting key value violates exclusion constraint'];
        $exclusion = new \Illuminate\Database\QueryException('pgsql', 'insert into "visits" …', [], $pdo);

        $unique = new \PDOException('duplicate key value violates unique constraint');
        $unique->errorInfo = ['23505', 7, 'duplicate key value violates unique constraint'];
        $other = new \Illuminate\Database\QueryException('pgsql', 'insert into "visits" …', [], $unique);

        $this->assertTrue(ScheduleVisit::isOverlapViolation($exclusion));
        $this->assertFalse(ScheduleVisit::isOverlapViolation($other));
    }

    public function test_a_sequential_double_booking_still_leaves_exactly_one_visit()
    {
        $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');

        try {
            $this->schedule($this->makeVisit(), $this->staff, '2026-08-01 09:00', '2026-08-01 10:00');
            $this->fail('The overlapping booking should have been rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, Visit::query()->occupying()->whereNotNull('starts_at')->count());
    }
}
