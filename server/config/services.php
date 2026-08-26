<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),

        // Signs nothing outbound: this is what inbound webhook signatures are
        // checked against, so it is required even when sending uses an API
        // key below.
        'token' => env('TWILIO_AUTH_TOKEN'),

        // Where the number is homed. A number registered in one region cannot
        // be sent from through another region's endpoint — Twilio rejects it
        // outright (error 21663) — so these must match what the console says.
        // Empty means Twilio's default US region.
        'region' => env('TWILIO_REGION'),
        'edge' => env('TWILIO_EDGE'),

        // An API key is per-region, and the account token authenticates only
        // against the default region — so a number homed anywhere else can be
        // sent from only with a key issued in that same region. Left empty,
        // sending falls back to the account token.
        'key' => env('TWILIO_API_KEY'),
        'secret' => env('TWILIO_API_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
