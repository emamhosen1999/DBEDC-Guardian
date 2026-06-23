<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$tenants = DB::select("SELECT * FROM tenants");
echo "Tenants:\n" . json_encode($tenants, JSON_PRETTY_PRINT) . PHP_EOL;

$domains = DB::select("SELECT * FROM domains");
echo "Domains:\n" . json_encode($domains, JSON_PRETTY_PRINT) . PHP_EOL;
