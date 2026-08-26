<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\MobileTokenController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ConversationMessageController;
use App\Http\Controllers\CorporationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPhoneController;
use App\Http\Controllers\IdentityController;
use App\Http\Controllers\PhotoUploadController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\TwilioWebhookController;
use App\Http\Controllers\UploadLinkController;
use App\Http\Middleware\VerifyTwilioSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Twilio calls this one, so it carries no session and no Sanctum guard —
// the request signature is what authenticates it, and the number the text
// was sent to is what identifies the firm.
Route::post('webhooks/twilio/sms', TwilioWebhookController::class)
    ->middleware(VerifyTwilioSignature::class)
    ->name('webhooks.twilio.sms');

// The customer's photo upload page. No account, no session behind it — the
// token in the URL is the whole authority, which is why it is narrow (one
// conversation), bounded (a fixed number of pictures) and short-lived, and
// why these two routes are rate limited. Unknown, expired and used-up tokens
// are all resolved to the same 404 by the binding in AppServiceProvider.
Route::middleware('throttle:20,1')->group(function () {
    Route::get('uploads/{uploadLink}', [PhotoUploadController::class, 'show'])->name('uploads.show');
    Route::post('uploads/{uploadLink}', [PhotoUploadController::class, 'store'])->name('uploads.store');
});

Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login');
Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');

// The mobile app's login. Same credentials as the web login above, but it
// answers with a bearer token instead of a session cookie — a native client
// sends no Origin header, so Sanctum never treats it as first-party and it
// falls through to the token guard.
Route::post('mobile/token', [MobileTokenController::class, 'store'])->name('mobile.token');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    // Its own route because the web logout invalidates a session, which a
    // token-authenticated request does not have.
    Route::post('mobile/logout', [MobileTokenController::class, 'destroy'])->name('mobile.logout');
    Route::get('user', fn (Request $request) => $request->user()?->loadMissing('staff'));

    Route::apiResource('customers', CustomerController::class)->except(['create', 'edit']);
    Route::post('customers/{customer}/phones', [CustomerPhoneController::class, 'store'])
        ->whereNumber('customer')
        ->name('customers.phones.store');
    Route::delete('customers/{customer}/phones/{phone}', [CustomerPhoneController::class, 'destroy'])
        ->whereNumber(['customer', 'phone'])
        ->name('customers.phones.destroy');

    // The shared inbox. Visible to everyone in the firm, so no admin gate.
    Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::post('conversations', [ConversationController::class, 'store'])->name('conversations.store');
    // Before the {conversation} routes, and they only match numbers, so
    // "resolve" is never mistaken for an id.
    Route::get('conversations/resolve', [ConversationController::class, 'resolve'])->name('conversations.resolve');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation')->name('conversations.show');
    Route::post('conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])
        ->whereNumber('conversation')
        ->name('conversations.messages.store');
    Route::post('conversations/{conversation}/link', [ConversationController::class, 'link'])->whereNumber('conversation')->name('conversations.link');
    Route::get('conversations/{conversation}/upload-link', [UploadLinkController::class, 'show'])
        ->whereNumber('conversation')
        ->name('conversations.upload-link.show');
    Route::post('conversations/{conversation}/upload-link', [UploadLinkController::class, 'store'])
        ->whereNumber('conversation')
        ->name('conversations.upload-link.store');
    // Addressed on its own, not through the message it arrived with: a case
    // will point at the same file, and it has no reason to know that id.
    Route::get('attachments/{attachment}', AttachmentController::class)
        ->whereNumber('attachment')
        ->name('attachments.show');
    Route::post('identities/{identity}/unlink', [IdentityController::class, 'unlink'])->whereNumber('identity')->name('identities.unlink');

    Route::middleware('admin')->group(function () {
        Route::apiResource('staff', StaffController::class)->except(['create', 'edit']);
        Route::post('staff/{staff}/invite', [StaffController::class, 'invite'])->name('staff.invite');

        Route::get('corporation', [CorporationController::class, 'edit'])->name('corporation.edit');
        Route::get('corporation/logo', [CorporationController::class, 'logo'])->name('corporation.logo');
        Route::post('corporation/logo', [CorporationController::class, 'uploadLogo'])->name('corporation.logo.store');
        Route::delete('corporation/logo', [CorporationController::class, 'deleteLogo'])->name('corporation.logo.destroy');
    });

    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');
});
