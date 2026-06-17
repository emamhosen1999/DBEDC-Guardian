<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $count = \App\Models\RequestLog::count();
    echo "Total request logs: " . $count . "\n";
    if ($count > 0) {
        $latest = \App\Models\RequestLog::orderBy('id', 'desc')->first();
        echo "Latest log ID: " . $latest->id . "\n";
        echo "Latest log URL: " . $latest->url . "\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
