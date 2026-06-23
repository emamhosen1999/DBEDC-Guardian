<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$dbs = DB::select("SHOW DATABASES");
echo "Databases:\n" . json_encode($dbs, JSON_PRETTY_PRINT) . PHP_EOL;

$active_db = DB::connection()->getDatabaseName();
echo "Active DB Name: " . $active_db . PHP_EOL;
