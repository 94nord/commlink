<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twilio\Security\RequestValidator;

/**
 * Proves an inbound webhook really came from Twilio. The endpoint has to be
 * open to the internet and cannot use our own auth, so this signature is the
 * only thing standing between a stranger and the ability to post fake
 * customer messages into a firm's inbox.
 *
 * It fails closed: no configured auth token means nothing can be verified,
 * so nothing is accepted.
 *
 * The signature covers the exact URL Twilio called. Behind a TLS-terminating
 * proxy (which is how this app is deployed) PHP sees plain http on an
 * internal hostname, and the URL it reconstructs will not match what Twilio
 * signed — hence INBOX_SMS_WEBHOOK_URL, which pins the public URL for this
 * comparison.
 */
class VerifyTwilioSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('services.twilio.token');

        if (blank($token)) {
            abort(403, 'Twilio signature verification is not configured.');
        }

        $validator = new RequestValidator($token);

        $valid = $validator->validate(
            $request->header('X-Twilio-Signature', ''),
            config('inbox.sms.webhook_url') ?: $request->fullUrl(),
            $request->post(),
        );

        abort_unless($valid, 403, 'Invalid Twilio signature.');

        return $next($request);
    }
}
