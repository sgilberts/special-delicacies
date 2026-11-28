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

    /*
    |--------------------------------------------------------------------------
    | Paystack settlement integration (Stage 4D-PAYSTACK)
    |--------------------------------------------------------------------------
    |
    | Read-only settlement retrieval for the Ghana / GHS merchant account.
    | The secret key is never sent to the browser, returned by an API response
    | or written to a log. There is no automatic live/test fallback: whatever
    | key is configured is the only key used.
    |
    */
    'paystack' => [
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'settlement_enabled' => (bool) env('PAYSTACK_SETTLEMENT_ENABLED', false),
        'timeout' => (int) env('PAYSTACK_TIMEOUT', 15),
        // One retry for transient failures (connection loss, 429, 5xx).
        'retries' => (int) env('PAYSTACK_RETRIES', 2),
        'retry_sleep_ms' => (int) env('PAYSTACK_RETRY_SLEEP_MS', 250),
    ],

];
