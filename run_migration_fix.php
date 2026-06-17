<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Remove the migration record so it runs again
$migrationName = '2024_07_20_000002_create_project_management_tables';
$deleted = DB::table('migrations')->where('migration', $migrationName)->delete();

if ($deleted) {
    echo "Removed migration record: {$migrationName}\n";
} else {
    echo "Migration record not found or already deleted: {$migrationName}\n";
}

// Drop all project-management and task related tables that might have been partially created
$tablesToDrop = [
    'project_task_issues',
    'project_issues',
    'project_task_attachments',
    'project_task_comments',
    'project_tasks',
    'project_milestones',
    'project_resources',
    'tasks',
];

DB::statement('SET FOREIGN_KEY_CHECKS=0;');
foreach ($tablesToDrop as $table) {
    if (Schema::hasTable($table)) {
        Schema::dropIfExists($table);
        echo "Dropped table: {$table}\n";
    }
}
DB::statement('SET FOREIGN_KEY_CHECKS=1;');

// Run artisan migrate
echo "Running php artisan migrate...\n";
try {
    $exitCode = Artisan::call('migrate');
    echo "Migration completed with exit code: {$exitCode}\n";
    echo Artisan::output();
} catch (Exception $e) {
    echo 'Migration failed with exception: '.$e->getMessage()."\n";
}
