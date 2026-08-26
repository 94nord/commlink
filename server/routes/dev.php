<?php

use App\Enums\AddressType;
use App\Enums\Channel;
use App\Enums\MessageDirection;
use App\Inbox\Actions\IngestInboundMessage;
use App\Inbox\ChannelRegistry;
use App\Models\CorporationAddress;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dev-only routes
|--------------------------------------------------------------------------
|
| Loaded exclusively in the local environment (see routes/web.php) — never
| testing, never prod. They let `devtools/` fake a customer texting or
| emailing in without a real provider, and glance at what a reply would
| have put on the wire. No auth: the environment gate is the fence, same as
| routes/testing.php's `_test/*`.
|
*/

/**
 * Every address a devtools user might pick, so the UI can offer a real
 * picker instead of asking for raw ids.
 */
Route::get('_dev/addresses', function () {
    return CorporationAddress::with('corporation')
        ->orderBy('corporation_id')
        ->get()
        ->map(fn (CorporationAddress $address) => [
            'id' => $address->id,
            'corporationName' => $address->corporation->name,
            'addressType' => $address->address_type->value,
            'value' => $address->value,
        ]);
});

/**
 * Pretend a customer texted in. Same pipeline as `_test/inbound-sms` — only
 * `to` is required here, not nullable: a dev box can hold more than one
 * corporation, so devtools always names the address explicitly rather than
 * relying on "the only one in the database".
 */
Route::post('_dev/inbound-sms', function (Request $request, ChannelRegistry $channels, IngestInboundMessage $ingest) {
    $validated = $request->validate([
        'to' => ['required', 'string'],
        'from' => ['required', 'string'],
        'body' => ['required', 'string'],
    ]);

    $adapter = $channels->for(Channel::Sms);

    $address = CorporationAddress::where('address_type', AddressType::Phone)
        ->where('value', $adapter->normalizeAddress($validated['to']))
        ->firstOrFail();

    $ingest->handle($address->corporation, $adapter, $adapter->receive([
        'From' => $validated['from'],
        'To' => $address->value,
        'Body' => $validated['body'],
        'MessageSid' => 'SMdev'.bin2hex(random_bytes(8)),
    ]));

    return response()->noContent();
});

/**
 * Pretend a customer emailed in. Same pipeline as `_test/inbound-email`,
 * `to` required for the same reason as above.
 */
Route::post('_dev/inbound-email', function (Request $request, ChannelRegistry $channels, IngestInboundMessage $ingest) {
    $validated = $request->validate([
        'to' => ['required', 'string'],
        'from' => ['required', 'string'],
        'subject' => ['nullable', 'string'],
        'text' => ['nullable', 'string'],
    ]);

    $adapter = $channels->for(Channel::Email);

    $address = CorporationAddress::where('address_type', AddressType::Email)
        ->where('value', $adapter->normalizeAddress($validated['to']))
        ->firstOrFail();

    $ingest->handle($address->corporation, $adapter, $adapter->receive([
        'from' => $validated['from'],
        'to' => $address->value,
        'subject' => $validated['subject'] ?? null,
        'text' => $validated['text'] ?? null,
        'message_id' => bin2hex(random_bytes(8)).'@dev.invalid',
    ]));

    return response()->noContent();
});

/**
 * What a reply would have put on the wire — SMS only. Email needs no
 * equivalent: dev outbound already lands in Mailpit.
 *
 * No new storage: SmsChannelAdapter::send() already writes
 * {from, to, message_sid} into channel_data on every outbound message.
 */
Route::get('_dev/outbound', function () {
    return Message::where('channel', Channel::Sms)
        ->where('direction', MessageDirection::Outbound)
        ->latest('sent_at')
        ->limit(50)
        ->get()
        ->map(fn (Message $message) => [
            'id' => $message->id,
            'from' => $message->channel_data['from'] ?? null,
            'to' => $message->channel_data['to'] ?? null,
            'body' => $message->body,
            'messageSid' => $message->channel_data['message_sid'] ?? null,
            'sentAt' => $message->sent_at,
        ]);
});
