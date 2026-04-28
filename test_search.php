<?php
header('Content-Type: text/plain');

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    // Test connection
    DB::connection()->getPdo();
    echo "Database connection: SUCCESS\n";
    
    // Check for FULLTEXT index
    $indexes = DB::select('SHOW INDEX FROM daily_works');
    $found = false;
    foreach($indexes as $index) {
        if($index->Key_name == 'idx_daily_works_search') {
            $found = true;
            break;
        }
    }
    
    if($found) {
        echo "FULLTEXT index: FOUND\n";
    } else {
        echo "FULLTEXT index: NOT FOUND\n";
        // Try to create it
        try {
            DB::statement('ALTER TABLE daily_works ADD FULLTEXT INDEX idx_daily_works_search (number, location, description, type, side, inspection_details)');
            echo "FULLTEXT index: CREATED\n";
        } catch (Exception $e) {
            echo "FULLTEXT index creation failed: " . $e->getMessage() . "\n";
        }
    }
    
    // Test search
    try {
        $results = DB::table('daily_works')
            ->whereRaw("MATCH(number, location, description, type, side, inspection_details) AGAINST('test' IN NATURAL LANGUAGE MODE)")
            ->limit(5)
            ->get();
        echo "Search test: SUCCESS - Found " . count($results) . " results\n";
    } catch (Exception $e) {
        echo "Search test: FAILED - " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "Database connection: FAILED - " . $e->getMessage() . "\n";
}
?>