<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;

$payload = [
    'config' => [
        'ip_locations' => [
            [
                'id' => 'office_12345',
                'name' => 'Primary Office',
                'allowed_ips' => ['192.168.1.1'],
                'allowed_ranges' => [],
                'is_active' => true
            ]
        ],
        'validation_mode' => 'any',
        'allow_without_network' => false
    ]
];

$request = Request::create('/settings/attendance-type/8', 'PUT', $payload);
$request->headers->set('Accept', 'application/json');

try {
    $rules = [
        'config' => 'required|array',

        // Polygon multi-config validation
        'config.polygons' => 'sometimes|array',
        'config.polygons.*.id' => 'sometimes|string',
        'config.polygons.*.name' => 'sometimes|string',
        'config.polygons.*.points' => 'sometimes|array',
        'config.polygons.*.points.*.lat' => 'sometimes|numeric|between:-90,90',
        'config.polygons.*.points.*.lng' => 'sometimes|numeric|between:-180,180',
        'config.polygons.*.is_active' => 'sometimes|boolean',

        // IP location multi-config validation
        'config.ip_locations' => 'sometimes|array',
        'config.ip_locations.*.id' => 'sometimes|string',
        'config.ip_locations.*.name' => 'sometimes|string',
        'config.ip_locations.*.allowed_ips' => 'sometimes|array',
        'config.ip_locations.*.allowed_ranges' => 'sometimes|array',
        'config.ip_locations.*.is_active' => 'sometimes|boolean',

        // Route multi-config validation
        'config.routes' => 'sometimes|array',
        'config.routes.*.id' => 'sometimes|string',
        'config.routes.*.name' => 'sometimes|string',
        'config.routes.*.waypoints' => 'sometimes|array',
        'config.routes.*.waypoints.*.lat' => 'sometimes|numeric|between:-90,90',
        'config.routes.*.waypoints.*.lng' => 'sometimes|numeric|between:-180,180',
        'config.routes.*.tolerance' => 'sometimes|integer|min:1|max:10000',
        'config.routes.*.is_active' => 'sometimes|boolean',

        // QR code multi-config validation
        'config.qr_codes' => 'sometimes|array',
        'config.qr_codes.*.id' => 'sometimes|string',
        'config.qr_codes.*.code' => 'sometimes|string',
        'config.qr_codes.*.name' => 'sometimes|string',
        'config.qr_codes.*.location' => 'sometimes|array',
        'config.qr_codes.*.max_distance' => 'sometimes|integer|min:1',
        'config.qr_codes.*.require_location' => 'sometimes|boolean',
        'config.qr_codes.*.one_time_use' => 'sometimes|boolean',
        'config.qr_codes.*.is_active' => 'sometimes|boolean',
        'config.qr_codes.*.expires_at' => 'sometimes|nullable|date',

        // Global config options
        'config.validation_mode' => 'sometimes|string|in:any,all',
        'config.allow_without_location' => 'sometimes|boolean',
        'config.allow_without_network' => 'sometimes|boolean',
        'config.code_expiry_hours' => 'sometimes|integer|min:1|max:720',
        'config.one_time_use' => 'sometimes|boolean',
        'config.require_location' => 'sometimes|boolean',
        'config.max_distance' => 'sometimes|integer|min:1|max:10000',
    ];

    $validator = validator($payload, $rules);
    $data = $validator->validate();
    echo "Validated data:\n";
    print_r($data);
} catch (\Illuminate\Validation\ValidationException $e) {
    echo "Validation failed: " . $e->getMessage() . "\n";
    print_r($e->errors());
}
