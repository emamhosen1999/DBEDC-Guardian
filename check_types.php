<?php

use App\Models\HRM\AttendanceType;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$types = AttendanceType::all();
foreach ($types as $type) {
    echo "ID: {$type->id} | Name: {$type->name} | Slug: {$type->slug} | Active: {$type->is_active}\n";
    echo 'Config: '.json_encode($type->config)."\n";
    echo "---------------------------------------------------\n";
}
