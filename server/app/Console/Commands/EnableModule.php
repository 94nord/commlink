<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCorporations;
use App\Enums\Module;
use App\Support\EventRecorder;
use Illuminate\Console\Command;

use function Laravel\Prompts\text;

/**
 * Switch a firm on for a module. Ops-only, deliberately: there is no
 * self-serve UI for this, because turning a channel on is a billing decision
 * somebody makes on the phone, not a toggle in settings.
 */
class EnableModule extends Command
{
    use ResolvesCorporations;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:enable-module
        {--corporation= : Corporation id or exact name}
        {--module= : Which module — sms}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Switch a firm on for a module';

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

        if ($corporation->hasModule($module)) {
            $this->info("'{$corporation->name}' is already switched on for {$module->value}.");

            return self::SUCCESS;
        }

        $row = $corporation->modules()->create(['module' => $module]);

        EventRecorder::record(
            $corporation,
            'corporation.module_enabled',
            subject: $row,
            payload: ['module' => $module->value],
        );

        $this->info("Switched '{$corporation->name}' on for {$module->value}.");

        // Being switched on is not the same as being able to send: without an
        // address of the right kind the channel still shows as unavailable.
        if ($corporation->primaryAddress($module->addressType()) === null) {
            $this->warn("'{$corporation->name}' has no {$module->addressType()->value} address yet — attach one with `php artisan app:attach-address` before it can send.");
        }

        return self::SUCCESS;
    }
}
