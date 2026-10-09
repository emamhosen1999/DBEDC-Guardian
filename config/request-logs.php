<?php

return [
    // Master switch for request logging.
    'enabled' => (bool) env('REQUEST_LOG_ENABLED', true),

    // 30 days stay queryable in the database; older rows are archived (gzip NDJSON, see archive_path) before
    // deletion, so incident investigation keeps the full history without a multi-GB table (owner, 2026-10-09).
    'retention_days' => (int) env('REQUEST_LOG_RETENTION_DAYS', 30),

    // Stored bodies are capped; anything larger is truncated (response) or replaced by a marker (request).
    'max_response_bytes' => (int) env('REQUEST_LOG_MAX_RESPONSE_BYTES', 10000),
    'max_request_bytes' => (int) env('REQUEST_LOG_MAX_REQUEST_BYTES', 10000),

    // Response bodies are only stored from this status upward (errors). Set to 0 to store all, 1000 for none.
    'response_body_min_status' => (int) env('REQUEST_LOG_RESPONSE_BODY_MIN_STATUS', 400),

    // Where `request-logs:prune --archive` writes its gzip NDJSON files (relative to the local disk root).
    'archive_path' => 'request-log-archives',

    // Request paths (Request::is() patterns) that are never logged: health, static, assets, polling noise.
    'exclude' => [
        'up',
        'health*',
        'api/health*',
        'api/v1/health*',
        'api/v1/heartbeat',
        'api/biometric/heartbeat',
        'csrf-token',
        'sanctum/csrf-cookie',
        'broadcasting/auth',
        'notifications/unread-count',
        'api/notifications/unread-count',
        'telescope*',
        'horizon*',
        '_debugbar*',
        'storage/*',
        'build/*',
        'assets/*',
        'vendor/*',
        'favicon.ico',
        'robots.txt',
        'om/camera/snapshot*',
        'om/camera/webrtc*',
        'om/camera/hls*',
        // The log viewer itself would otherwise log every refresh of its own table.
        'settings/request-logs/list',
    ],

    // Static file extensions are never logged.
    'exclude_extensions' => ['css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot'],

    // Responses of these paths are never stored (they carry personal data); the request itself is still logged.
    'omit_response_body' => ['api/*', 'login', 'logout', 'password*', 'sanctum/*', 'profile*', 'auth/*', 'om/camera*'],

    // A header, query or body key is redacted when it contains any of these fragments (case-insensitive).
    'redact_keys' => [
        'password', 'passwd', 'pwd', 'secret', 'token', 'otp', 'verification_code', 'reset_code',
        'authorization', 'cookie', 'api_key', 'apikey', 'api-key', 'x-csrf', 'x-xsrf', 'xsrf', 'csrf', 'signature',
        'nid', 'national_id', 'passport', 'bank', 'account_number', 'iban', 'swift', 'routing', 'card_number', 'cvv', 'ssn',
        'private_key', 'credential',
    ],
];
