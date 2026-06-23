<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$patterns = App\Models\HRM\ShiftRotationPattern::all()->toArray();
echo json_encode($patterns, JSON_PRETTY_PRINT) . PHP_EOL;
