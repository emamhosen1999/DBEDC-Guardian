<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CAPTCHA Configuration
    |--------------------------------------------------------------------------
    |
    | Configure CAPTCHA settings for suspicious login attempts and
    | automated attack prevention.
    |
    */

    'enabled' => env('CAPTCHA_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | CAPTCHA Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "recaptcha_v3", "hcaptcha", "turnstile"
    |
    */
    'provider' => env('CAPTCHA_PROVIDER', 'turnstile'),

    /*
    |--------------------------------------------------------------------------
    | reCAPTCHA v3 Settings
    |--------------------------------------------------------------------------
    */
    'recaptcha_v3' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'threshold' => env('RECAPTCHA_THRESHOLD', 0.5),
        'action' => 'login',
    ],

    /*
    |--------------------------------------------------------------------------
    | hCaptcha Settings
    |--------------------------------------------------------------------------
    */
    'hcaptcha' => [
        'site_key' => env('HCAPTCHA_SITE_KEY'),
        'secret_key' => env('HCAPTCHA_SECRET_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Turnstile Settings
    |--------------------------------------------------------------------------
    */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk-Based Triggers
    |--------------------------------------------------------------------------
    |
    | Configure conditions that trigger CAPTCHA verification.
    |
    */
    'triggers' => [
        'failed_attempts_threshold' => env('CAPTCHA_FAILED_ATTEMPTS_THRESHOLD', 3),
        'new_device' => env('CAPTCHA_NEW_DEVICE', true),
        'unusual_location' => env('CAPTCHA_UNUSUAL_LOCATION', true),
        'outside_business_hours' => env('CAPTCHA_OUTSIDE_BUSINESS_HOURS', true),
        'business_hours_start' => env('CAPTCHA_BUSINESS_HOURS_START', '06:00'),
        'business_hours_end' => env('CAPTCHA_BUSINESS_HOURS_END', '18:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Location-Based Settings
    |--------------------------------------------------------------------------
    |
    | Configure geolocation-based risk assessment.
    |
    */
    'location' => [
        'check_country' => env('CAPTCHA_CHECK_COUNTRY', true),
        'suspicious_countries' => array_filter(explode(',', env('CAPTCHA_SUSPICIOUS_COUNTRIES', ''))),
        'distance_threshold_km' => env('CAPTCHA_DISTANCE_THRESHOLD_KM', 500),
    ],
];
