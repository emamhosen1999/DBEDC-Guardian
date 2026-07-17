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

    'openrouteservice' => [
        'key' => env('OPENROUTESERVICE_API_KEY'),
    ],

    'graphhopper' => [
        'key' => env('GRAPHHOPPER_API_KEY'),
    ],

    'mapbox' => [
        'token' => env('MAPBOX_ACCESS_TOKEN'),
    ],
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'project_id' => env('FIREBASE_PROJECT_ID'),

        // Firebase WEB API key. This is a public client identifier, not a secret —
        // it is already shipped in the web bundle (resources/js/firebase-config.js
        // reads VITE_FIREBASE_API_KEY). Native clients need it to exchange the
        // server-issued custom token for an ID token via Identity Toolkit, which is
        // the only way RTDB will accept them. Access stays gated by that custom
        // token plus database.rules.json. Falls back to the web var so no new
        // production env key is required; when unset, realtime simply stays off.
        'web_api_key' => env('FIREBASE_WEB_API_KEY', env('VITE_FIREBASE_API_KEY')),
    ],

];
