<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$dbName = "tenant0cc7bc69-c89c-472f-a713-545efa6f5b68";
config(['database.connections.mysql.database' => $dbName]);
DB::purge('mysql');
DB::reconnect('mysql');

$all = DB::select("SHOW TABLES");
$matched = [];
foreach ($all as $row) {
    $tbl = array_values((array)$row)[0];
    if (strpos($tbl, 'shift') !== false || strpos($tbl, 'rotation') !== false || strpos($tbl, 'pattern') !== false) {
        $matched[] = $tbl;
    }
}
echo "Filtered tables:\n" . json_encode($matched, JSON_PRETTY_PRINT) . PHP_EOL;
