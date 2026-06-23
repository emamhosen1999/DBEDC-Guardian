<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Override database connection to tenant DB
config(['database.connections.mysql.database' => 'tenant0cc7bc69-c89c-472f-a713-545efa6f5b68']);
DB::purge('mysql');
DB::reconnect('mysql');

$active_db = DB::connection()->getDatabaseName();
echo "Active DB Name: " . $active_db . PHP_EOL;

$results = DB::select("SELECT id, name, code, cycle_length_days FROM shift_rotation_patterns");
echo "Rotation Patterns:\n" . json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL;

$shifts = DB::select("SELECT id, name, code FROM shifts");
echo "Shifts:\n" . json_encode($shifts, JSON_PRETTY_PRINT) . PHP_EOL;
