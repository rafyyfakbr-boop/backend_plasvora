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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'api_key'         => env('GEMINI_API_KEY'),
        'model'           => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'fallback_model'  => env('GEMINI_FALLBACK_MODEL', 'gemini-3.5-flash-lite'),
        'timeout'         => (int) env('GEMINI_TIMEOUT', 20),
        'connect_timeout' => (int) env('GEMINI_CONNECT_TIMEOUT', 5),
    ],

    'rajaongkir' => [
        'api_key'          => env('RAJAONGKIR_API_KEY'),
        'base_url'         => env('RAJAONGKIR_BASE_URL', 'https://rajaongkir.komerce.id/api/v1/'),
        'couriers'         => env('RAJAONGKIR_COURIERS', 'jne:jnt'),
        'price_mode'       => 'lowest',
        'timeout'          => (int) env('RAJAONGKIR_TIMEOUT', 15),
        'connect_timeout'  => (int) env('RAJAONGKIR_CONNECT_TIMEOUT', 5),
        'search_cache_ttl' => (int) env('RAJAONGKIR_SEARCH_CACHE_TTL', 300),
    ],

    'midtrans' => [
        'server_key'                    => env('MIDTRANS_SERVER_KEY'),
        'signature_secret_key'          => env('MIDTRANS_SIGNATURE_SECRET_KEY'),
        'signature_secret_key_previous' => env('MIDTRANS_SIGNATURE_SECRET_KEY_PREVIOUS'),
        'is_production'                 => filter_var(env('MIDTRANS_IS_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN),
        'snap_base_url'                 => filter_var(env('MIDTRANS_IS_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN)
            ? 'https://app.midtrans.com'
            : 'https://app.sandbox.midtrans.com',
        'api_base_url'                  => filter_var(env('MIDTRANS_IS_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN)
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com',
        'timeout'                       => (int) env('MIDTRANS_TIMEOUT', 15),
        'payment_expiry_minutes'        => (int) env('MIDTRANS_PAYMENT_EXPIRY_MINUTES', 1440),
    ],

];
