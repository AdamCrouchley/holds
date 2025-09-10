<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    // ----------------------------------------------------------------------
    // VEVS Booking API
    // ----------------------------------------------------------------------
    'vevs' => [
        'base_url' => env('VEVS_BASE_URL', ''),   // e.g. https://rental.jimny.co.nz/api/<API_KEY>
        'api_key'  => env('VEVS_API_KEY', ''),

        'endpoints' => [
            'week_made'   => env('VEVS_ENDPOINT_WEEK_MADE', 'ReservationWeekMade'),
            'week_pickup' => env('VEVS_ENDPOINT_WEEK_PICKUP', ''), // e.g. ReservationWeekPickup
            'by_ref'      => env('VEVS_ENDPOINT_BY_REF', 'Reservation'),
        ],

        'timeout'        => env('VEVS_TIMEOUT', 20),
        'webhook_secret' => env('VEVS_WEBHOOK_SECRET'),
        'queue'          => env('VEVS_QUEUE', true), // false = run inline (local testing)
    ],

    // ----------------------------------------------------------------------
    // Dream Drives feed
    // ----------------------------------------------------------------------
    'dreamdrives' => [
        'base' => env('DREAMDRIVES_FEED_BASE', 'https://api.dreamdrives.example'),
        'key'  => env('DREAMDRIVES_FEED_KEY'),
        'tz'   => env('DREAMDRIVES_DEFAULT_TZ', 'Pacific/Auckland'),
    ],

    // ----------------------------------------------------------------------
    // Email / Notification Providers
    // ----------------------------------------------------------------------
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ----------------------------------------------------------------------
    // Payments
    // ----------------------------------------------------------------------
    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // ----------------------------------------------------------------------
    // Magic Links
    // ----------------------------------------------------------------------
    'magic_links' => [
        'secret' => env('MAGIC_LINKS_SECRET'),
    ],

];
