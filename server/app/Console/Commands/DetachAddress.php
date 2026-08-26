<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCorporations;
use App\Enums\AddressType;
use App\Support\EventRecorder;
use Illuminate\Console\Command;
use InvalidArgumentException;

use function Laravel\Prompts\text;

/**
 * Give up an address the firm no longer wants to be reached at — a mistyped
 * attach, or a number handed back to the provider.
 *
 * Soft-deleted rather than removed, so the messages that went out from it
 * still say where they came from. Detaching the primary leaves the firm with
 * no primary of that kind, which means it cannot start new exchanges there
 * until another is attached with --primary — deliberately loud, rather than
 * silently picking a successor the firm did not choose.
 */
class DetachAddress extends Command
{
    use ResolvesCorporations;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:detach-address
        {--corporation= : Corporation id or exact name}
        {--type=phone : Kind of address — phone}
        {--address= : The address to give up, e.g. +4520123456}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detach an address a firm is no longer reachable at';

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

        $type = AddressType::tryFrom((string) $this->option('type'));

        if ($type === null) {
            $this->error("'{$this->option('type')}' is not a kind of address.");

            return self::FAILURE;
        }

        $input = $this->option('address') ?? text('Address', required: true);

        try {
            $value = $type->normalize($input);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $address = $corporation->addresses()
            ->where('address_type', $type)
            ->where('value', $value)
            ->first();

        if ($address === null) {
            $this->error("'{$corporation->name}' has no address {$value}.");

            return self::FAILURE;
        }

        $wasPrimary = $address->is_primary;

        // The flag goes with the row: a soft-deleted primary would keep
        // occupying the one primary slot its kind is allowed.
        $address->update(['is_primary' => false]);
        $address->delete();

        EventRecorder::record(
            $corporation,
            'corporation.address_detached',
            subject: $address,
            payload: [
                'address_type' => $type->value,
                'value' => $value,
                'was_primary' => $wasPrimary,
            ],
        );

        $this->info("Detached {$value} from '{$corporation->name}'.");

        if ($wasPrimary) {
            $this->warn("'{$corporation->name}' now has no primary {$type->value} address — attach one with --primary before it can start new exchanges there.");
        }

        return self::SUCCESS;
    }
}
