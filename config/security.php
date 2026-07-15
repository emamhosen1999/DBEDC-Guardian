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

    /*
    |--------------------------------------------------------------------------
    | Mobile Device Binding (per-device HMAC request signing)
    |--------------------------------------------------------------------------
    |
    | On mobile login the server mints a random per-device secret, stores it
    | encrypted, and returns it once. The client HMAC-signs every authenticated
    | request. Enforcement is staged like the ADMS auth switch:
    |   - observe (strict=false, default): bad/absent/stale signatures are
    |     logged as "would reject" but ALLOWED — the live fleet keeps working.
    |   - strict (strict=true): forged/stale/replayed/wrong-device requests are
    |     rejected 401 invalid_device. Unenrolled (pre-secret) devices are still
    |     allowed so legacy sessions are never stranded.
    |
    */

    'device_binding' => [
        // Master enforcement switch. FALSE = observe/log-only (safe default).
        'strict' => (bool) env('AUTH_DEVICE_BINDING_STRICT', false),

        // Max allowed clock skew (seconds) between client timestamp and server.
        'timestamp_tolerance' => (int) env('AUTH_DEVICE_BINDING_TOLERANCE', 300),

        // Bytes of entropy in each issued device secret (32 = 256-bit).
        'secret_bytes' => (int) env('AUTH_DEVICE_BINDING_SECRET_BYTES', 32),

        // How long (seconds) a nonce is remembered to block replays. Should be
        // >= timestamp_tolerance so a captured request cannot be replayed within
        // its own validity window.
        'nonce_ttl' => (int) env('AUTH_DEVICE_BINDING_NONCE_TTL', 600),
    ],

];
