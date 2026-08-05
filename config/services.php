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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'printify' => [
        'token' => env('PRINTIFY_TOKEN'),
        'base_url' => env('PRINTIFY_BASE_URL', 'https://api.printify.com/v1'),
        'timeout' => (int) env('PRINTIFY_TIMEOUT', 15),
        'retry_times' => (int) env('PRINTIFY_RETRY_TIMES', 3),
        'retry_sleep_ms' => (int) env('PRINTIFY_RETRY_SLEEP_MS', 500),
        'sync_lock_seconds' => (int) env('PRINTIFY_SYNC_LOCK_SECONDS', 900),
    ],

];
