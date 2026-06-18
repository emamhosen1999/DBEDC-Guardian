<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$files = scandir(__DIR__.'/database/migrations');
$migrationFiles = [];
foreach ($files as $file) {
    if (str_ends_with($file, '.php')) {
        $migrationFiles[] = substr($file, 0, -4);
    }
}

$dbMigrations = DB::table('migrations')->pluck('migration')->toArray();

echo 'MIGRATION FILES IN FOLDER: '.count($migrationFiles)."\n";
echo 'MIGRATIONS IN DB: '.count($dbMigrations)."\n";

$missingInDb = array_diff($migrationFiles, $dbMigrations);
echo 'MISSING IN DB MIGRATIONS TABLE: '.count($missingInDb)."\n\n";

foreach ($missingInDb as $m) {
    // Try to guess table from name
    $tableName = null;
    if (preg_with_match('/_create_(.*)_table/', $m, $matches)) {
        $tableName = $matches[1];
    } elseif (preg_with_match('/_create_(.*)s/', $m, $matches)) {
        $tableName = $matches[1].'s';
    }

    $exists = false;
    if ($tableName) {
        $exists = Schema::hasTable($tableName);
    }

    echo "- {$m}".($tableName ? " (table: {$tableName}, exists: ".($exists ? 'YES' : 'NO').')' : '')."\n";
}

function preg_with_match($pattern, $subject, &$matches)
{
    return preg_match($pattern, $subject, $matches);
}
