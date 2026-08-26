<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCorporations;
use App\Enums\AddressType;
use App\Models\Corporation;
use App\Models\CorporationAddress;
use App\Support\EventRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

use function Laravel\Prompts\text;

/**
 * Attach an address the firm can be reached at.
 *
 * Nothing here provisions anything: a number is bought in the provider's
 * console first, exactly as before. This only records which firm it belongs
 * to, so inbound arriving at it can be routed back.
 */
class AttachAddress extends Command
{
    use ResolvesCorporations;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:attach-address
        {--corporation= : Corporation id or exact name}
        {--type=phone : Kind of address — phone}
        {--address= : The address, e.g. +4520123456}
        {--primary : Start new exchanges of this kind from this address}
        {--verified : Mark it already proved — for kinds that need proving}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Attach an address a firm is reachable at (bought in the provider\'s console first)';

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

        $takenBy = CorporationAddress::where('address_type', $type)
            ->where('value', $value)
            ->where('corporation_id', '!=', $corporation->getKey())
            ->first();

        if ($takenBy !== null) {
            $this->error("{$value} is already attached to '{$takenBy->corporation->name}'.");

            return self::FAILURE;
        }

        // The first address of its kind has to be primary — otherwise the
        // firm has an address it can never start an exchange from.
        $primary = $this->option('primary') || $corporation->primaryAddress($type) === null;

        DB::transaction(function () use ($corporation, $type, $value, $primary) {
            $address = $corporation->addresses()->firstOrNew([
                'address_type' => $type,
                'value' => $value,
            ]);

            $attaching = ! $address->exists;

            // A number is ours the moment the provider hands it over; there
            // is nothing to check. A mailbox on the firm's own domain has to
            // prove itself through DNS — which is what `--verified` overrides
            // by hand, for development and for an ops person who has already
            // checked. Until an address is proved, the channel reads as
            // unavailable and nothing will send from it.
            $stampNow = $type === AddressType::Phone || $this->option('verified');
            $address->verified_at ??= $stampNow ? now() : null;
            $address->save();

            if ($attaching) {
                EventRecorder::record(
                    $corporation,
                    'corporation.address_attached',
                    subject: $address,
                    payload: ['address_type' => $type->value, 'value' => $value],
                );
            }

            if ($primary && ! $address->is_primary) {
                $this->promote($corporation, $address);
            }
        });

        $this->info("Attached {$value} to '{$corporation->name}'.");

        return self::SUCCESS;
    }

    /**
     * Move the primary flag onto this address. Only one primary per kind is
     * allowed, so the sitting one steps down first.
     */
    private function promote(Corporation $corporation, CorporationAddress $address): void
    {
        $previous = $corporation->primaryAddress($address->address_type);
        $previous?->update(['is_primary' => false]);

        $address->update(['is_primary' => true]);

        EventRecorder::record(
            $corporation,
            'corporation.address_primary_changed',
            subject: $address,
            payload: [
                'address_type' => $address->address_type->value,
                'value' => $address->value,
                'previous' => $previous?->value,
            ],
        );
    }
}
