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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'three_rings' => [
        'base_url' => env('THREE_RINGS_BASE_URL', 'https://www.3r.org.uk'),
        'key' => env('THREE_RINGS_API_KEY'),
        'contact_email' => env('THREE_RINGS_CONTACT_EMAIL'),

        /*
         * Three Rings allows at most 30 requests in any sliding 60-second
         * window. The limiter uses a fixed window, and two fixed windows can
         * straddle a sliding one (up to 2N requests), so 15 per fixed window
         * guarantees the published limit is never exceeded.
         */
        'rate_limit' => [
            'max_attempts' => 15,
            'decay_seconds' => 60,
        ],

        /*
         * Per-endpoint cache lifetimes in seconds. The "fresh" copy is served
         * without hitting Three Rings; the "stale" copy is only served when
         * Three Rings is unavailable or the rate limit has been reached.
         */
        'cache' => [
            'volunteers' => ['fresh' => 3600, 'stale' => 86400],
            'roles' => ['fresh' => 21600, 'stale' => 172800],
            'shifts' => ['fresh' => 300, 'stale' => 3600],
        ],
    ],

];
