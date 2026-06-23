<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$userIds = [6, 3, 8, 19, 26, 7, 25, 95, 98, 16, 5, 97, 4, 13, 12, 9, 14, 23];

$assignments = App\Models\HRM\ShiftAssignment::where('scope_type', 'user')
    ->whereIn('scope_id', $userIds)
    ->get()
    ->toArray();

echo json_encode($assignments, JSON_PRETTY_PRINT) . PHP_EOL;
