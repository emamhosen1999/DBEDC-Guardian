<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Leave Policy Hardening
    |--------------------------------------------------------------------------
    | Server-side request-time policy knobs. All enforced in
    | LeaveValidationService / LeaveCrudService.
    */

    // How many days in the past a leave may start (covers post-facto sick leave).
    // 0 = no backdating at all.
    'max_backdate_days' => (int) env('LEAVE_MAX_BACKDATE_DAYS', 30),

    // Minimum notice (days) required for future-dated leave. 0 = disabled.
    'min_notice_days' => (int) env('LEAVE_MIN_NOTICE_DAYS', 0),

    // When a (user, type, year) balance is untracked, lazily seed the ledger from
    // the type's accrual policy so balance enforcement is never silently dormant.
    'auto_seed_ledger' => (bool) env('LEAVE_AUTO_SEED_LEDGER', true),

    // Warn (non-blocking) when >= N teammates in the same department already have
    // pending/approved leave overlapping the requested range.
    'team_conflict_warn_threshold' => (int) env('LEAVE_TEAM_CONFLICT_WARN', 1),
];
