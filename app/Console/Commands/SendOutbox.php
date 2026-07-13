<?php

namespace App\Console\Commands;

use App\Enums\OutboxStatus;
use App\Mail\VisitBookedMail;
use App\Mail\VisitCancelledMail;
use App\Mail\VisitChangedMail;
use App\Mail\VisitInviteMail;
use App\Models\OutboxMessage;
use App\Models\RoundWorkOrder;
use App\Models\Visit;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOutbox extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-outbox';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send pending outbox messages through the mail transport';

    /**
     * Drains the outbox (X1): every pending row becomes one Mailable. A
     * failure marks the row failed with its error and never blocks the
     * rest of the batch; invite rows also stamp the membership's SO4
     * delivery state (invite_sent_at / invite_failed_at).
     */
    public function handle(): int
    {
        foreach (OutboxMessage::query()->pending()->orderBy('id')->get() as $message) {
            try {
                Mail::to($message->recipient_email)->send($this->mailableFor($message));

                $message->update([
                    'status' => OutboxStatus::Sent,
                    'sent_at' => now(),
                    'error' => null,
                ]);

                $this->membershipFor($message)?->update([
                    'invite_sent_at' => now(),
                    'invite_failed_at' => null,
                ]);
            } catch (Throwable $e) {
                $message->update([
                    'status' => OutboxStatus::Failed,
                    'error' => $e->getMessage(),
                ]);

                $this->membershipFor($message)?->update(['invite_failed_at' => now()]);
            }
        }

        return self::SUCCESS;
    }

    private function mailableFor(OutboxMessage $message): Mailable
    {
        return match ($message->type) {
            'invite' => new VisitInviteMail(
                RoundWorkOrder::findOrFail($message->payload['round_work_order_id'])
            ),
            'booked' => new VisitBookedMail(
                Visit::findOrFail($message->payload['visit_id'])
            ),
            'changed' => new VisitChangedMail(
                Visit::findOrFail($message->payload['visit_id']),
                $message->payload['previous_starts_at'],
            ),
            'cancelled' => new VisitCancelledMail(
                Visit::findOrFail($message->payload['visit_id'])
            ),
        };
    }

    private function membershipFor(OutboxMessage $message): ?RoundWorkOrder
    {
        if ($message->type !== 'invite') {
            return null;
        }

        return RoundWorkOrder::find($message->payload['round_work_order_id']);
    }
}
