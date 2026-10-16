<?php

/*
|--------------------------------------------------------------------------
| Day-based overtime and replacement leave (owner policy, 2026-10-15)
|--------------------------------------------------------------------------
| No hourly overtime on working days. A claim is only for a day WORKED (punch evidence) that is a holiday (overtime
| pay only) or a rostered rest day (overtime pay OR replacement leave). HR can tune every number here.
*/
return [
    // Day credit from the time worked against the usual shift length.
    'full_day_fraction' => (float) env('OT_FULL_DAY_FRACTION', 0.5), // worked >= this share of the shift = 1 day
    'min_minutes' => (int) env('OT_MIN_MINUTES', 120), // 2 h; between this and the full-day share = 0.5 day, below = no claim

    // A holiday or rest day with a punch-in but no punch-out can still be claimed: the employee declares the end time, the claim is
    // flagged for the approver (who can accept or adjust it). Holiday overtime on a declared end time is always allowed (owner rule).
    // For OVERTIME PAY on a rostered REST day the same applies by default; set to false to refuse rest-day pay until the punch-out is
    // corrected (replacement leave is still claimable). ASSUMPTION to confirm with the owner.
    'allow_ot_pay_without_punch_out' => (bool) env('OT_ALLOW_PAY_WITHOUT_PUNCH_OUT', true),

    // Replacement credit.
    'replacement_expiry_days' => (int) env('REPLACEMENT_EXPIRY_DAYS', 60),
    'replacement_reminder_days' => [14, 3],

    // How far back a day can still be claimed, and how far back the "worked, not claimed" helper and its reminder look.
    'claim_window_days' => (int) env('OT_CLAIM_WINDOW_DAYS', 14),

    // Next-morning reminder for rest-day / holiday work that has not been claimed.
    'unclaimed_reminder_enabled' => (bool) env('OT_UNCLAIMED_REMINDER', true),
    'unclaimed_reminder_time' => env('OT_UNCLAIMED_REMINDER_TIME', '09:00'),
];
