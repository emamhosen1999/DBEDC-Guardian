<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile Login Brute-Force Protection
    |--------------------------------------------------------------------------
    |
    | Per-account (email + IP) rate limiting for the mobile login endpoint
    | POST /api/v1/auth/login. This sits on top of the global "throttle:api"
    | per-IP limiter (defense in depth). After "max_attempts" failed password
    | attempts within the decay window the account/IP pair is locked out for
    | "decay_seconds" and receives an HTTP 429 with a Retry-After header.
    |
    | A successful login clears the counter immediately.
    |
    */

    'mobile_login' => [
        // Number of failed attempts allowed before the lockout kicks in.
        'max_attempts' => (int) env('MOBILE_LOGIN_MAX_ATTEMPTS', 5),

        // Cooldown window, in seconds, that a locked key must wait out.
        'decay_seconds' => (int) env('MOBILE_LOGIN_DECAY_SECONDS', 60),
    ],

];
