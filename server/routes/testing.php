<?php

use App\Enums\AddressType;
use App\Enums\Channel;
use App\Enums\CustomerKind;
use App\Enums\Module;
use App\Inbox\Actions\IngestInboundMessage;
use App\Inbox\ChannelRegistry;
use App\Models\Conversation;
use App\Models\Corporation;
use App\Models\CorporationAddress;
use App\Models\UploadLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Test-only routes
|--------------------------------------------------------------------------
|
| Loaded exclusively in the testing environment (see routes/web.php). They
| let browser-level E2E tests reset and seed backend state over HTTP, so the
| Node toolchain needs no database driver.
|
*/

Route::post('_test/reset', function () {
    Artisan::call('migrate:fresh', ['--force' => true]);

    return response()->noContent();
});

Route::post('_test/seed', function (Request $request) {
    $validated = $request->validate([
        'corporation' => ['required', 'string', 'max:255'],
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'email', 'max:255'],
        'password' => ['required', 'string'],
        'sms_number' => ['nullable', 'string'],
        'sms_module' => ['nullable', 'boolean'],
        'email_module' => ['nullable', 'boolean'],
        'email_address' => ['nullable', 'string'],
    ]);

    $corporation = Corporation::create(['name' => $validated['corporation']]);

    // The inbox needs a number to send from *and* the module switched on;
    // give every seeded firm both so tests never have to think about it.
    // A test that wants the switched-off states asks for them explicitly.
    $address = $corporation->addresses()->create([
        'address_type' => AddressType::Phone,
        'value' => $validated['sms_number'] ?? '+4533221100',
        'is_primary' => true,
        'verified_at' => now(),
    ]);

    if ($validated['sms_module'] ?? true) {
        $corporation->modules()->create(['module' => Module::Sms]);
    }

    // An email address, verified, so a test that wants the channel only has
    // to switch the module on. The module itself stays *off* by default:
    // the seeded firm is an SMS firm, and tests that assert on "a firm with
    // no email channel" have to keep meaning that.
    $corporation->addresses()->create([
        'address_type' => AddressType::Email,
        'value' => $validated['email_address'] ?? 'kontakt@firma.test',
        'is_primary' => true,
        'verified_at' => now(),
    ]);

    if ($validated['email_module'] ?? false) {
        $corporation->modules()->create(['module' => Module::Email]);
    }

    $user = $corporation->users()->forceCreate([
        'name' => $validated['name'],
        'email' => $validated['email'],
        'email_verified_at' => now(),
        'password' => $validated['password'],
    ]);

    // S2: a corporation always has at least one staff member — the founder,
    // who is the admin and can log in (so the E2E user sees admin surfaces).
    $staff = $corporation->staff()->create([
        'user_id' => $user->id,
        'name' => $validated['name'],
        'email' => $validated['email'],
        'is_admin' => true,
        'is_founder' => true,
    ]);

    return response()->json([
        'corporation_id' => $corporation->id,
        'user_id' => $user->id,
        'staff_id' => $staff->id,
        'sms_number' => $address->value,
    ]);
});

/**
 * A customer, for tests that need somebody to link a conversation to.
 */
Route::post('_test/customer', function (Request $request) {
    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'email', 'max:255'],
    ]);

    $customer = Corporation::firstOrFail()->customers()->create([
        'kind' => CustomerKind::Person,
        'name' => $validated['name'],
    ]);

    // An email is an address like any other now, so it is an identity.
    $customer->identities()->create([
        'corporation_id' => $customer->corporation_id,
        'address_type' => AddressType::Email,
        'value' => mb_strtolower(trim($validated['email'])),
    ]);

    return response()->json(['customer_id' => $customer->id]);
});

/**
 * Pretend a customer texted in.
 *
 * This is the real inbound pipeline — the SMS adapter normalizing a Twilio
 * payload, then the same ingest the webhook uses — with only the signature
 * check skipped, since a browser test has no Twilio credentials to sign
 * with. Everything downstream of the middleware is genuinely exercised.
 */
Route::post('_test/inbound-sms', function (Request $request, ChannelRegistry $channels, IngestInboundMessage $ingest) {
    $validated = $request->validate([
        'to' => ['nullable', 'string'],
        'from' => ['required', 'string'],
        'body' => ['required', 'string'],
    ]);

    $adapter = $channels->for(Channel::Sms);

    $address = CorporationAddress::where('address_type', AddressType::Phone)
        ->when(
            isset($validated['to']),
            fn ($query) => $query->where('value', $adapter->normalizeAddress($validated['to'])),
        )
        ->firstOrFail();

    $ingest->handle($address->corporation, $adapter, $adapter->receive([
        'From' => $validated['from'],
        'To' => $address->value,
        'Body' => $validated['body'],
        'MessageSid' => 'SMtest'.bin2hex(random_bytes(8)),
    ]));

    return response()->noContent();
});

/**
 * Pretend a customer emailed in.
 *
 * The real inbound pipeline — the email adapter normalizing a provider-shaped
 * payload, then the same ingest and threading the webhook will use — with
 * only the provider's signature check missing, because there is no provider
 * yet. Everything downstream is genuinely exercised, including which
 * exchange the message lands in.
 */
Route::post('_test/inbound-email', function (Request $request, ChannelRegistry $channels, IngestInboundMessage $ingest) {
    $validated = $request->validate([
        'to' => ['nullable', 'string'],
        'from' => ['required', 'string'],
        'subject' => ['nullable', 'string'],
        'text' => ['nullable', 'string'],
        'html' => ['nullable', 'string'],
        'message_id' => ['nullable', 'string'],
        'in_reply_to' => ['nullable', 'string'],
        'references' => ['nullable', 'string'],
    ]);

    $adapter = $channels->for(Channel::Email);

    $address = CorporationAddress::where('address_type', AddressType::Email)
        ->when(
            isset($validated['to']),
            fn ($query) => $query->where('value', $adapter->normalizeAddress($validated['to'])),
        )
        ->firstOrFail();

    $ingest->handle($address->corporation, $adapter, $adapter->receive([
        'from' => $validated['from'],
        'to' => $address->value,
        'subject' => $validated['subject'] ?? null,
        'text' => $validated['text'] ?? null,
        'html' => $validated['html'] ?? null,
        'message_id' => $validated['message_id'] ?? bin2hex(random_bytes(8)).'@test.invalid',
        'in_reply_to' => $validated['in_reply_to'] ?? null,
        'references' => $validated['references'] ?? null,
    ]));

    return response()->noContent();
});

/**
 * Switch the firm on or off for a module, as ops would.
 *
 * The browser suite shares one database and runs single-file, so a test that
 * switches something off is expected to switch it back on again afterwards.
 */
Route::post('_test/module', function (Request $request) {
    $validated = $request->validate([
        'module' => ['required', 'string'],
        'enabled' => ['required', 'boolean'],
    ]);

    $corporation = Corporation::firstOrFail();
    $module = Module::from($validated['module']);

    if ($validated['enabled']) {
        $corporation->modules()->firstOrCreate(['module' => $module]);
    } else {
        $corporation->modules()->where('module', $module)->delete();
    }

    return response()->noContent();
});

/**
 * Mint a photo upload link for the newest conversation.
 *
 * The real endpoint needs a logged-in session, which a Playwright API
 * request context does not carry; everything the link itself does — the
 * token, the hash, the expiry — is the production path.
 */
Route::post('_test/upload-link', function () {
    $conversation = Conversation::latest('id')->firstOrFail();
    $token = UploadLink::newToken();

    $conversation->corporation->uploadLinks()->create([
        'conversation_id' => $conversation->id,
        'token_hash' => UploadLink::hash($token),
        'token' => $token,
        'expires_at' => now()->addDays((int) config('inbox.uploads.link_ttl_days')),
    ]);

    return response()->json(['token' => $token]);
});
