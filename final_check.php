<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

// Test database connection
try {
    DB::connection()->getPdo();
    echo "✓ Database connection: SUCCESS\n";
} catch (Exception $e) {
    echo "✗ Database connection: FAILED - " . $e->getMessage() . "\n";
}

// Test FULLTEXT index
try {
    $indexes = DB::select('SHOW INDEX FROM daily_works');
    $found = false;
    foreach($indexes as $index) {
        if($index->Key_name == 'idx_daily_works_search') {
            $found = true;
            break;
        }
    }
    
    if($found) {
        echo "✓ FULLTEXT index: FOUND\n";
    } else {
        echo "✗ FULLTEXT index: NOT FOUND\n";
    }
} catch (Exception $e) {
    echo "✗ FULLTEXT index check: FAILED - " . $e->getMessage() . "\n";
}

// Test search functionality
try {
    $results = DB::table('daily_works')
        ->whereRaw("MATCH(number, location, description, type, side, inspection_details) AGAINST('test' IN NATURAL LANGUAGE MODE)")
        ->limit(5)
        ->get();
    echo "✓ Search test: SUCCESS - Found " . count($results) . " results\n";
} catch (Exception $e) {
    echo "✗ Search test: FAILED - " . $e->getMessage() . "\n";
}

// Test analytics endpoint
try {
    $response = \Illuminate\Support\Facades\Http::get('http://127.0.0.1:8000/api/v1/analytics/daily-works/dashboard');
    if($response->successful()) {
        echo "✓ Analytics endpoint: SUCCESS - Status " . $response->status() . "\n";
    } else {
        echo "✗ Analytics endpoint: FAILED - Status " . $response->status() . "\n";
    }
} catch (Exception $e) {
    echo "✗ Analytics endpoint: FAILED - " . $e->getMessage() . "\n";
}

echo "\nSystem check complete!\n";
?>