<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$missingMigrations = [
    '2024_08_04_230532_create_leaves_table',
    '2026_06_04_155200_create_biometric_download_sessions_table',
];

$maxBatch = DB::table('migrations')->max('batch') ?? 0;
$newBatch = $maxBatch + 1;

foreach ($missingMigrations as $migrationName) {
    $existsInDb = DB::table('migrations')->where('migration', $migrationName)->exists();
    if (! $existsInDb) {
        DB::table('migrations')->insert([
            'migration' => $migrationName,
            'batch' => $newBatch,
        ]);
        echo "Inserted: {$migrationName} with batch {$newBatch}\n";
    } else {
        echo "Already exists in migrations table: {$migrationName}\n";
    }
}
