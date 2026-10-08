<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Widget registry cache store
    |--------------------------------------------------------------------------
    |
    | Per-user, per-scope widget payloads are cached for their own short TTL
    | (see App\Services\Dashboard\DashboardWidget::ttl()). Leave this null to
    | use the application's default cache store. When that default is the
    | `null` driver (CACHE_STORE=null, the current production setting) every
    | dashboard poll would recompute all widgets, so the registry falls back
    | to the `file` store instead. Set DASHBOARD_CACHE_STORE to force a store.
    |
    */

    'cache_store' => env('DASHBOARD_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Client refresh hint
    |--------------------------------------------------------------------------
    |
    | Seconds between client refreshes of the widget payload (web polling and
    | the mobile home). Never below 60: the server-side TTLs are that short, so
    | polling faster only burns queries.
    |
    */

    'refresh_seconds' => max(60, (int) env('DASHBOARD_REFRESH_SECONDS', 120)),
];
