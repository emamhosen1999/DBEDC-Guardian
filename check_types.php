<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$types = \App\Models\HRM\AttendanceType::all();
foreach ($types as $type) {
    echo "ID: {$type->id} | Name: {$type->name} | Slug: {$type->slug} | Active: {$type->is_active}\n";
    echo "Config: " . json_encode($type->config) . "\n";
    echo "---------------------------------------------------\n";
}
