<?php

namespace App\Console\Commands;

use App\Enums\RoundStatus;
use App\Models\Round;
use App\Support\EventRecorder;
use Illuminate\Console\Command;

class OpenDueRounds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:open-due-rounds';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Open draft rounds whose scheduled start has arrived';

    /**
     * Runs every minute via the scheduler. A due round that cannot open yet
     * (no cases, or no window) is skipped with its schedule kept, so it
     * opens automatically as soon as it becomes valid — the round page
     * shows the pending schedule until then.
     */
    public function handle(): int
    {
        $due = Round::query()
            ->where('status', RoundStatus::Draft)
            ->where('scheduled_open_at', '<=', now())
            ->get();

        foreach ($due as $round) {
            // Same invariants as a manual open (RoundController::open):
            // at least one case, and a window for the slots to live in.
            if (! $round->workOrders()->exists() || $round->window_starts_on === null) {
                continue;
            }

            $round->update(['status' => RoundStatus::Open]);

            EventRecorder::record($round->corporation, 'round.opened', subject: $round, payload: [
                'trigger' => 'scheduled',
            ]);

            $this->info("Opened round {$round->id}.");
        }

        return self::SUCCESS;
    }
}
