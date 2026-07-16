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

    // Mock-location (fake GPS) rejection. Android exposes whether a position was
    // produced by a mock provider (expo-location LocationObject.mocked); the app
    // forwards it as the `is_mocked` punch field. When this flag is TRUE (the
    // secure default) a punch that EXPLICITLY reports `is_mocked = true` is
    // rejected (422). A punch that OMITS the field (older client that cannot
    // report it) is NEVER rejected for this reason, so the control degrades safely
    // during a staged app rollout. Set false to observe/stage without enforcing.
    'reject_mock_location' => (bool) env('ATTENDANCE_REJECT_MOCK_LOCATION', true),

    /*
    |--------------------------------------------------------------------------
    | Working-time compliance rules
    |--------------------------------------------------------------------------
    | Fatigue/overwork guardrails evaluated against a user's rostered shift
    | sequence (see App\Services\Attendance\WorkTimeComplianceService). Every
    | rule below can be disabled independently by setting it to 0. Ships as a
    | WARN-FIRST rollout: violations are always computed and surfaced to the
    | caller (e.g. `compliance_violations` on the roster/swap/assignment JSON
    | responses), but nothing is blocked until `enforce` is turned on.
    */

    'compliance' => [
        // Master enforcement switch. false = OBSERVE: violations are returned
        // for display only, nothing is rejected. true = ENFORCE: an action
        // that would create a NEW violation with severity=error (e.g.
        // min-rest breach or a 24h scheduled-hours breach) is rejected with a
        // 422. Warning-severity violations (consecutive nights/days, weekly
        // hours) are NEVER blocking, even when enforce is on — they surface
        // for HR review only. Roster *generation* (bulk pattern
        // materialization) never blocks regardless of this flag; it is
        // purely informational there, since it can affect many users at once
        // and is not the point of employee/manager decision-making.
        'enforce' => (bool) env('ATTENDANCE_COMPLIANCE_ENFORCE', false),

        // Minimum rest, in hours, required between the end of one scheduled
        // shift and the start of the next (ILO/EU daily-rest norm is 11h).
        // This is what catches a night shift immediately followed by a
        // morning/evening shift with little or no gap. Set to 0 to disable.
        'min_rest_hours' => (float) env('ATTENDANCE_COMPLIANCE_MIN_REST_HOURS', 11),

        // Maximum total scheduled hours allowed inside ANY rolling 24h
        // window. This is what catches "two full shifts back-to-back on the
        // same calendar day" (e.g. an 08:00-20:00 day shift immediately
        // followed by a 20:00-08:00 night shift) even though each individual
        // shift assignment looks legitimate on its own. Set to 0 to disable.
        'max_span_in_24h_hours' => (float) env('ATTENDANCE_COMPLIANCE_MAX_SPAN_24H_HOURS', 16),

        // Maximum number of CONSECUTIVE calendar days a person may be
        // rostered onto a "night" shift, where night = the shift crosses
        // midnight OR its start time is 21:00 or later. Set to 0 to disable.
        'max_consecutive_nights' => (int) env('ATTENDANCE_COMPLIANCE_MAX_CONSECUTIVE_NIGHTS', 4),

        // Maximum number of CONSECUTIVE calendar days a person may be
        // rostered to work at all (any shift) before a rest day is required.
        // Set to 0 to disable.
        'max_consecutive_working_days' => (int) env('ATTENDANCE_COMPLIANCE_MAX_CONSECUTIVE_WORKING_DAYS', 7),

        // Maximum total scheduled hours allowed within a single Monday-Sunday
        // calendar week. Set to 0 to disable.
        'max_weekly_hours' => (float) env('ATTENDANCE_COMPLIANCE_MAX_WEEKLY_HOURS', 60),
    ],
];
