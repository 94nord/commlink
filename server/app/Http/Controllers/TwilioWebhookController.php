<?php

namespace App\Http\Controllers;

use App\Enums\AddressType;
use App\Enums\Channel;
use App\Inbox\Actions\IngestInboundMessage;
use App\Inbox\ChannelRegistry;
use App\Models\Corporation;
use App\Models\CorporationAddress;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Where customers' texts arrive.
 *
 * This is the one endpoint in the application whose tenant does not come
 * from an authenticated user — there is no user, only Twilio. The firm is
 * identified by the number the text was sent *to*, which is why each
 * corporation gets its own. A number we do not recognize is not ours to
 * accept.
 *
 * Authenticity is the signature middleware's job, not this controller's.
 */
class TwilioWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        ChannelRegistry $channels,
        IngestInboundMessage $ingest,
    ): Response {
        $corporation = $this->resolveCorporation($request->input('To'));

        $adapter = $channels->for(Channel::Sms);

        // A firm switched off for SMS swallows what arrives: no ingest, no
        // record, and the same empty TwiML a delivered text gets. Anything
        // else — a 4xx, an error — would make Twilio retry for hours, and
        // would eventually bounce something back at a customer who has done
        // nothing wrong and should not learn about our billing.
        if (! $corporation->hasModule(AddressType::Phone->module())) {
            return $this->accepted();
        }

        try {
            $inbound = $adapter->receive($request->post());
        } catch (InvalidArgumentException) {
            // A sender we cannot make an address out of. Nothing to file it
            // under, and no point making Twilio retry it.
            abort(422, 'Unusable sender number.');
        }

        $ingest->handle($corporation, $adapter, $inbound);

        return $this->accepted();
    }

    /**
     * Twilio expects TwiML; an empty response means "no auto-reply".
     */
    private function accepted(): Response
    {
        return response('<Response/>', 200, ['Content-Type' => 'text/xml']);
    }

    private function resolveCorporation(mixed $to): Corporation
    {
        try {
            $number = PhoneNumber::normalize((string) $to);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        // Any of the firm's numbers, not just its primary one: a customer
        // may be replying to something sent from a number the firm has since
        // stopped starting new exchanges on.
        return CorporationAddress::where('address_type', AddressType::Phone)
            ->where('value', $number)
            ->firstOr(fn () => abort(404))
            ->corporation;
    }
}
