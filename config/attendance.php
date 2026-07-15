<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ZKTeco ADMS device authentication
    |--------------------------------------------------------------------------
    | The /iclock/* push endpoints are reached by ZKTeco devices that identify
    | themselves by serial number (?SN=). Serial numbers are printed on the
    | device and are NOT secret, so serial-only trust lets anyone forge
    | attendance (table=ATTLOG) or enroll users (table=USERINFO). These settings
    | layer a per-device shared secret on top of the registered+active allowlist,
    | with a safe, staged rollout so the fix can ship to live hardware first.
    */

    // Master enforcement switch for the per-device ADMS token.
    //   false = OBSERVE mode: a request that WOULD be rejected for a bad/absent
    //           token is logged (level: warning) but STILL processed, so you can
    //           watch the logs on live hardware before enforcing.
    //   true  = ENFORCE mode: a device that has a token configured but presents
    //           a wrong/absent token is rejected with a ZKTeco "ERROR" body.
    // A device with NO adms_token configured always falls back to allowlist-only
    // (registered + active) in BOTH modes, so today's un-configured devices keep
    // ingesting punches even after strict mode is turned on.
    'adms_strict_auth' => env('ATTENDANCE_ADMS_STRICT_AUTH', false),

    // Query-string parameter the device / reverse-proxy uses to present its
    // secret, e.g. /iclock/cdata?SN=...&table=ATTLOG&token=xxxx. The
    // X-Device-Token and X-ADMS-Token request headers are also accepted.
    'adms_token_param' => env('ATTENDANCE_ADMS_TOKEN_PARAM', 'token'),

    // Device-initiated user enrollment (table=USERINFO) can create/rewrite
    // system users and is far higher risk than a punch. When true (default), an
    // enrollment push is ALWAYS rejected unless the device presents a valid
    // per-device token — independent of the observe/enforce switch above.
    'adms_enrollment_requires_token' => env('ATTENDANCE_ADMS_ENROLLMENT_REQUIRES_TOKEN', true),

    /*
    |--------------------------------------------------------------------------
    | Punch integrity: capture-photo + GPS trust
    |--------------------------------------------------------------------------
    | Anti-falsification controls for the location punch flow. Conservative and
    | config-driven so they can be tuned or staged-off per environment without a
    | code change. Defaults are the secure values.
    */

    // When a user's resolved attendance method(s) STRUCTURALLY require a capture
    // photo (geo-polygon / route-waypoint field methods), reject a punch that
    // arrives with no photo (422). Enforcement is conservative: it only fires
    // when EVERY resolved method requires a photo, so a user who also holds a
    // non-photo method (wifi/IP, QR, biometric) is never blocked, and photo is
    // never blanket-required. Set false to restore the legacy "photo optional"
    // behaviour during a staged rollout.
    'require_photo_for_field_methods' => (bool) env('ATTENDANCE_REQUIRE_PHOTO', true),

    // GPS accuracy sanity ceiling, in metres. When a client reports a location
    // `accuracy` value WORSE (numerically larger) than this — i.e. the GPS fix is
    // too coarse to trust for a geofence decision — the punch is rejected (422).
    // Clients that send no `accuracy` field (older apps) are NEVER rejected by
    // this rule. Set to 0 to disable the ceiling entirely.
    'max_gps_accuracy_meters' => (float) env('ATTENDANCE_MAX_GPS_ACCURACY_METERS', 1000),
];
