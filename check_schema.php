<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "TABLES:\n";
foreach (DB::select('SHOW TABLES') as $table) {
    $array = (array) $table;
    echo '- '.reset($array)."\n";
}

echo "\nUSERS TABLE COLUMNS:\n";
if (Schema::hasTable('users')) {
    $columns = Schema::getColumnListing('users');
    foreach ($columns as $column) {
        $type = Schema::getColumnType('users', $column);
        echo "- {$column} ({$type})\n";
    }
} else {
    echo "users table does not exist.\n";
}

echo "\nMIGRATIONS TABLE CONTENT:\n";
if (Schema::hasTable('migrations')) {
    $migrations = DB::table('migrations')->get();
    foreach ($migrations as $m) {
        echo "- Batch: {$m->batch} | Migration: {$m->migration}\n";
    }
} else {
    echo "migrations table does not exist.\n";
}
