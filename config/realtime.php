<?php

return [
    'enabled' => env('REALTIME_ENABLED', true),
    'namespace' => env('REALTIME_NAMESPACE', env('FIREBASE_PROJECT_ID', 'app')),
];
