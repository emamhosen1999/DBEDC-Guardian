<?php

// Cross-origin access is only needed by browser clients of the JSON API
// (the web export of the mobile app and any future SPA). Native app builds
// send no Origin header and are unaffected. Server-to-server device traffic
// (/iclock/*, biometric webhooks) is not browser-originated and is excluded.
//
// Origins are an explicit allowlist from CORS_ALLOWED_ORIGINS (comma-separated),
// never "*". The mobile/web clients authenticate with Bearer tokens, not cookies,
// so credentialed CORS is off by default — a "*" origin with credentials is both
// a security hole and rejected by browsers anyway.

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('APP_URL', '')))
)));

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))
    ))),

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-Device-ID', 'X-Device-Signature', 'X-Idempotency-Key'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),
];
