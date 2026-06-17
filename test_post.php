<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

// Start session to generate CSRF token
$request = Request::create('/settings/attendance-type', 'POST');
$request->setLaravelSession($app->make('session')->driver());
$request->session()->start();
$csrfToken = $request->session()->token();

$user = User::first();
if ($user) {
    auth()->login($user);
}

$payload = [
    '_token' => $csrfToken,
    'name' => 'Wifi Office Test',
    'description' => 'Wifi IP based',
    'is_active' => true,
    'config' => [
        'ip_locations' => [
            [
                'id' => 'office_12345',
                'name' => 'Primary Office',
                'allowed_ips' => ['192.168.1.1'],
                'allowed_ranges' => [],
                'is_active' => true,
            ],
        ],
        'validation_mode' => 'any',
        'allow_without_network' => false,
    ],
    'slug' => 'wifi_ip',
    'icon' => '📶',
];

$request = Request::create('/settings/attendance-type', 'POST', $payload);
$request->setLaravelSession($app->make('session')->driver());
$request->session()->put('_token', $csrfToken);
$request->headers->set('Accept', 'application/json');
$request->headers->set('X-CSRF-TOKEN', $csrfToken);

$response = $kernel->handle($request);
$kernel->terminate($request, $response);

echo 'Response status: '.$response->getStatusCode()."\n";
echo 'Response body: '.$response->getContent()."\n";
