<?php

namespace Tests\Feature;

use App\Enums\OutboxStatus;
use App\Mail\VisitBookedMail;
use App\Mail\VisitCancelledMail;
use App\Mail\VisitChangedMail;
use App\Mail\VisitInviteMail;
use App\Models\OutboxMessage;
use App\Models\Round;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->staff = Staff::factory()->for($this->user->corporation)->create();
    }

    private function makeOpenRound(): Round
    {
        return Round::factory()->for($this->user->corporation)->open()->create([
            'window_starts_on' => now()->addDay()->toDateString(),
            'window_ends_on' => now()->addDays(2)->toDateString(),
            'close_off_on' => now()->addDays(10)->toDateString(),
        ]);
    }

    private function attach(Round $round, WorkOrder $workOrder, array $pivot = []): string
    {
        $token = Str::random(64);
        $round->workOrders()->attach($workOrder->id, ['token' => $token] + $pivot);

        return $token;
    }

    public function test_opening_a_round_queues_invites_for_unsent_recipients_only()
    {
        $round = Round::factory()->for($this->user->corporation)->create();
        $unsent = WorkOrder::factory()->for($this->user->corporation)->create();
        $alreadySent = WorkOrder::factory()->for($this->user->corporation)->create();
        $this->attach($round, $unsent);
        $this->attach($round, $alreadySent, ['invite_sent_at' => now()]);

        $this->actingAs($this->user)->post("/rounds/{$round->id}/open")->assertSessionHasNoErrors();

        $invites = OutboxMessage::where('type', 'invite')->get();
        $this->assertCount(1, $invites);
        $this->assertSame($unsent->customer->email, $invites->first()->recipient_email);
        $this->assertSame(OutboxStatus::Pending, $invites->first()->status);
    }

    public function test_a_late_add_is_not_auto_emailed_until_an_individual_send()
    {
        $round = $this->makeOpenRound();
        $workOrder = WorkOrder::factory()->for($this->user->corporation)->create();

        $this->actingAs($this->user)->post("/rounds/{$round->id}/cases", [
            'work_order_ids' => [$workOrder->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, OutboxMessage::where('type', 'invite')->count());

        $this->actingAs($this->user)
            ->post("/rounds/{$round->id}/cases/{$workOrder->id}/send-invite")
            ->assertSessionHasNoErrors();

        $this->assertSame(1, OutboxMessage::where('type', 'invite')->count());
    }

    public function test_a_customer_booking_queues_a_confirmation_with_manage_link()
    {
        $round = $this->makeOpenRound();
        $workOrder = WorkOrder::factory()->for($this->user->corporation)->create();
        $token = $this->attach($round, $workOrder);

        $this->post("/book/{$token}", [
            'starts_at' => now()->addDay()->toDateString().' 09:00',
        ])->assertSessionHasNoErrors();

        $message = OutboxMessage::where('type', 'booked')->first();
        $this->assertNotNull($message);
        $this->assertSame($workOrder->customer->email, $message->recipient_email);

        $visit = Visit::find($message->payload['visit_id']);
        $this->assertStringContainsString("/book/{$token}", $visit->bookingUrl());
        $this->assertStringContainsString('/book/', (new VisitBookedMail($visit))->render());
    }

    public function test_a_fresh_admin_visit_queues_a_confirmation_without_manage_link()
    {
        $workOrder = WorkOrder::factory()->for($this->user->corporation)->create();

        $this->actingAs($this->user)->post("/cases/{$workOrder->id}/visits", [
            'type' => 'survey',
            'staff_id' => $this->staff->id,
            'starts_at' => '2026-08-01 09:00',
            'ends_at' => '2026-08-01 10:00',
        ])->assertSessionHasNoErrors();

        $message = OutboxMessage::where('type', 'booked')->first();
        $this->assertNotNull($message);

        $visit = Visit::find($message->payload['visit_id']);
        $this->assertNull($visit->bookingUrl());
        $this->assertStringNotContainsString('/book/', (new VisitBookedMail($visit))->render());
    }

    public function test_an_admin_move_queues_a_change_notice()
    {
        $visit = Visit::factory()->for($this->user->corporation)
            ->scheduled($this->staff, now()->parse('2026-08-01 09:00'), now()->parse('2026-08-01 10:00'))
            ->create();

        $this->actingAs($this->user)->patch("/visits/{$visit->id}", [
            'staff_id' => $this->staff->id,
            'starts_at' => '2026-08-02 13:00',
            'ends_at' => '2026-08-02 14:00',
        ])->assertSessionHasNoErrors();

        $message = OutboxMessage::where('type', 'changed')->first();
        $this->assertNotNull($message);
        $this->assertArrayHasKey('previous_starts_at', $message->payload);
    }

    public function test_a_cancellation_queues_a_cancellation_notice()
    {
        $visit = Visit::factory()->for($this->user->corporation)->create();

        $this->actingAs($this->user)->post("/visits/{$visit->id}/cancel")->assertSessionHasNoErrors();

        $this->assertNotNull(OutboxMessage::where('type', 'cancelled')->first());
    }

    public function test_send_outbox_sends_pending_rows_and_stamps_the_membership()
    {
        Mail::fake();

        $round = Round::factory()->for($this->user->corporation)->create();
        $workOrder = WorkOrder::factory()->for($this->user->corporation)->create();
        $this->attach($round, $workOrder);
        $this->actingAs($this->user)->post("/rounds/{$round->id}/open");

        $this->artisan('app:send-outbox')->assertSuccessful();

        Mail::assertSent(VisitInviteMail::class, 1);

        $message = OutboxMessage::where('type', 'invite')->first();
        $this->assertSame(OutboxStatus::Sent, $message->status);
        $this->assertNotNull($message->sent_at);

        $membership = $round->workOrders()->first()->pivot;
        $this->assertNotNull($membership->invite_sent_at);
        $this->assertNull($membership->invite_failed_at);
    }

    public function test_a_transport_failure_marks_the_row_and_membership_failed()
    {
        $round = Round::factory()->for($this->user->corporation)->create();
        $workOrder = WorkOrder::factory()->for($this->user->corporation)->create();
        $this->attach($round, $workOrder);
        $this->actingAs($this->user)->post("/rounds/{$round->id}/open");

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->artisan('app:send-outbox')->assertSuccessful();

        $message = OutboxMessage::where('type', 'invite')->first();
        $this->assertSame(OutboxStatus::Failed, $message->status);
        $this->assertSame('SMTP down', $message->error);

        $this->assertNotNull($round->workOrders()->first()->pivot->invite_failed_at);
    }

    public function test_a_resend_after_failure_clears_the_failure_state()
    {
        $round = $this->makeOpenRound();
        $workOrder = WorkOrder::factory()->for($this->user->corporation)->create();
        $this->attach($round, $workOrder, ['invite_failed_at' => now()]);

        Mail::fake();

        $this->actingAs($this->user)
            ->post("/rounds/{$round->id}/cases/{$workOrder->id}/send-invite")
            ->assertSessionHasNoErrors();

        $this->artisan('app:send-outbox')->assertSuccessful();

        $membership = $round->workOrders()->first()->pivot;
        $this->assertNotNull($membership->invite_sent_at);
        $this->assertNull($membership->invite_failed_at);
    }

    public function test_a_mail_carries_the_corporation_logo_only_when_set()
    {
        \Illuminate\Support\Facades\Storage::fake();

        $visit = Visit::factory()->for($this->user->corporation)
            ->scheduled($this->staff, now()->parse('2026-08-01 09:00'), now()->parse('2026-08-01 10:00'))
            ->create();

        // No logo yet — the footer partial receives null and renders nothing.
        $this->assertNull((new VisitBookedMail($visit))->content()->with['logo']);
        $this->assertStringNotContainsString('cid:', (new VisitBookedMail($visit))->render());

        \Illuminate\Support\Facades\Storage::put('logos/x.png', 'fake-png-bytes');
        $this->user->corporation->update(['logo_path' => 'logos/x.png']);

        $this->assertSame('fake-png-bytes', (new VisitBookedMail($visit->fresh()))->content()->with['logo']);
    }

    public function test_all_four_mailables_render()
    {
        $round = $this->makeOpenRound();
        $workOrder = WorkOrder::factory()->for($this->user->corporation)->create();
        $token = $this->attach($round, $workOrder);
        $membership = \App\Models\RoundWorkOrder::where('token', $token)->first();

        $visit = Visit::factory()->for($this->user->corporation)
            ->scheduled($this->staff, now()->parse('2026-08-01 09:00'), now()->parse('2026-08-01 10:00'))
            ->create(['work_order_id' => $workOrder->id, 'round_id' => $round->id]);

        $this->assertStringContainsString("/book/{$token}", (new VisitInviteMail($membership))->render());
        $this->assertStringContainsString('booked', (new VisitBookedMail($visit))->render());
        $this->assertStringContainsString('moved', (new VisitChangedMail($visit, '2026-07-30 10:00'))->render());
        $this->assertStringContainsString('cancelled', (new VisitCancelledMail($visit))->render());
    }

    public function test_send_invite_is_rejected_on_a_draft_round_and_after_close_off()
    {
        $draft = Round::factory()->for($this->user->corporation)->create();
        $draftCase = WorkOrder::factory()->for($this->user->corporation)->create();
        $this->attach($draft, $draftCase);

        $this->actingAs($this->user)
            ->post("/rounds/{$draft->id}/cases/{$draftCase->id}/send-invite")
            ->assertSessionHasErrors('invite');

        $closedOff = Round::factory()->for($this->user->corporation)->open()->create([
            'close_off_on' => now()->subDay()->toDateString(),
        ]);
        $lateCase = WorkOrder::factory()->for($this->user->corporation)->create();
        $this->attach($closedOff, $lateCase);

        $this->actingAs($this->user)
            ->post("/rounds/{$closedOff->id}/cases/{$lateCase->id}/send-invite")
            ->assertSessionHasErrors('invite');

        $this->assertSame(0, OutboxMessage::count());
    }
}
