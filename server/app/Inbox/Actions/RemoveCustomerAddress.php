<?php

namespace App\Inbox\Actions;

use App\Models\Identity;
use App\Support\EventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Takes an address off a customer's record.
 *
 * What that means depends on whether anything was ever said on it. An
 * address with no history is a typo somebody wants gone, and it goes. An
 * address that carried real messages cannot simply vanish — those messages
 * hang off its thread, and the firm said them — so it is unlinked instead:
 * the address and its whole history move to a person of their own, exactly
 * as if somebody had realised in the inbox that the number was never theirs.
 */
class RemoveCustomerAddress
{
    public function __construct(private readonly UnlinkIdentity $unlink) {}

    public function handle(Identity $identity): void
    {
        if ($identity->threads()->whereHas('messages')->exists()) {
            $this->unlink->handle($identity);

            return;
        }

        DB::transaction(function () use ($identity) {
            $customer = $identity->customer;

            $identity->delete();

            EventRecorder::record(
                $customer->corporation,
                'customer.address_removed',
                subject: $customer,
                payload: ['identity_id' => $identity->getKey()],
            );
        });
    }
}
