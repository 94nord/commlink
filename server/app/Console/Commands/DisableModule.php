<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCorporations;
use App\Enums\Module;
use App\Support\EventRecorder;
use Illuminate\Console\Command;

use function Laravel\Prompts\text;

/**
 * Switch a firm off for a module.
 *
 * This freezes, it never deletes. Every message already exchanged stays
 * readable in the inbox, greyed; nothing new goes out; anything the customer
 * sends in is swallowed silently, because telling a customer the firm has
 * gone away is worse than quietly not answering. Turning the module back on
 * restores sending with the history intact.
 */
class DisableModule extends Command
{
    use ResolvesCorporations;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:disable-module
        {--corporation= : Corporation id or exact name}
        {--module= : Which module — sms}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Switch a firm off for a module (freezes it — history is kept)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $reference = $this->option('corporation') ?? text('Corporation id or name', required: true);
        $corporation = $this->resolveCorporation($reference);

        if ($corporation === null) {
            $this->error("No corporation matches '{$reference}'.");

            return self::FAILURE;
        }

        $name = $this->option('module') ?? text('Module', required: true);
        $module = Module::tryFrom($name);

        if ($module === null) {
            $this->error("'{$name}' is not a module.");

            return self::FAILURE;
        }

        if (! $corporation->hasModule($module)) {
            $this->info("'{$corporation->name}' is not switched on for {$module->value}.");

            return self::SUCCESS;
        }

        $corporation->modules()->where('module', $module)->delete();

        EventRecorder::record(
            $corporation,
            'corporation.module_disabled',
            subject: $corporation,
            payload: ['module' => $module->value],
        );

        $this->info("Switched '{$corporation->name}' off for {$module->value}. Existing history stays readable.");

        return self::SUCCESS;
    }
}
