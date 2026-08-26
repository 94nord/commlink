<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default phone region
    |--------------------------------------------------------------------------
    |
    | Region used to interpret local-format phone numbers (a bare Danish
    | "12345678") when normalizing them to E.164. Numbers that already carry
    | a country code are unaffected. Set per deployment — never assume the
    | firm and its customers are Danish just because the first pilot is.
    |
    */

    'default_phone_region' => env('INBOX_DEFAULT_PHONE_REGION', 'DK'),

    /*
    |--------------------------------------------------------------------------
    | SMS channel
    |--------------------------------------------------------------------------
    |
    | transport: how texts leave the building. "log" writes them to the log
    | and invents a plausible provider id, so development and the test suite
    | need no Twilio account and text nobody's real phone. Production sets
    | "twilio" and supplies credentials in config/services.php.
    |
    | webhook_url: the public URL Twilio calls, used only when checking the
    | request signature. Twilio signs the URL it dialled; behind a
    | TLS-terminating proxy PHP reconstructs a different one (plain http, an
    | internal host) and every webhook would be rejected. Leave this empty
    | when the app sees its own real URL — set it otherwise.
    |
    */

    'sms' => [
        'transport' => env('SMS_TRANSPORT', 'log'),
        'webhook_url' => env('INBOX_SMS_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email channel
    |--------------------------------------------------------------------------
    |
    | transport: how email leaves the building. "log" writes it to the log,
    | so development and the test suite send nothing to anybody. "mail" goes
    | through Laravel's own mailer, which means Mailpit in development and
    | the provider's SMTP relay in production — no provider SDK either way,
    | just MAIL_* configured per environment.
    |
    | Defaults to "log" for the same reason SMS does: a developer should have
    | to opt in before anything can reach a real mailbox.
    |
    | webhook_token: the shared secret in the inbound webhook's URL. The
    | inbound endpoint itself is not built yet — it needs the provider's
    | verification scheme, and the provider is not chosen. Kept here so the
    | shape of the config does not change when it lands.
    |
    */

    'email' => [
        'transport' => env('EMAIL_TRANSPORT', 'log'),
        'webhook_token' => env('INBOX_EMAIL_WEBHOOK_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Photo upload links
    |--------------------------------------------------------------------------
    |
    | What a customer may hand in through a texted link, and for how long.
    | This is the only unauthenticated way to write into the inbox, so every
    | limit on it is deliberate — but the limit is on each submission, not on
    | the link's lifetime: a link keeps taking files, in as many visits as it
    | takes, until it expires or is replaced. Capping the total would turn a
    | job that produces more photos than expected into a support request.
    |
    | link_ttl_days is long enough for a job that produces photos over several
    | days from one text, short enough that a forwarded message does not stay
    | live indefinitely.
    |
    | max_total_kilobytes is the one size limit: the sum of everything in a
    | single submission, not a per-file or per-count rule — simpler to reason
    | about than two separate ceilings, and matches how someone actually
    | thinks about "how much can I send at once."
    |
    */

    'uploads' => [
        'link_ttl_days' => (int) env('INBOX_UPLOAD_LINK_TTL_DAYS', 7),
        'max_total_kilobytes' => (int) env('INBOX_UPLOAD_MAX_TOTAL_KILOBYTES', 25600),
    ],

];
