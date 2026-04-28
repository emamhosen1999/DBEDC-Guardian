<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Http;

// Test analytics endpoint without auth first (should redirect to login)
try {
    $response = Http::get('http://127.0.0.1:8000/api/v1/analytics/daily-works/dashboard');
    echo "Analytics endpoint status (no auth): " . $response->status() . PHP_EOL;
    if($response->clientError()) {
        echo "Body: " . $response->body() . PHP_EOL;
    }
} catch (Exception $e) {
    echo "Error calling analytics endpoint: " . $e->getMessage() . PHP_EOL;
}

// Test with a simple database query to make sure data exists
try {
    $count = DB::table('daily_works')->count();
    echo "Daily works count: " . $count . PHP_EOL;
} catch (Exception $e) {
    echo "Error counting daily works: " . $e->getMessage() . PHP_EOL;
}

// Test analytics service directly
try {
    $analyticsService = app(App\Services\DailyWork\DailyWorkAnalyticsService::class);
    $data = $analyticsService->getDashboardSummary([]);
    echo "Analytics service test: SUCCESS - Got data with keys: " . implode(', ', array_keys($data ?? [])) . PHP_EOL;
} catch (Exception $e) {
    echo "Analytics service test: FAILED - " . $e->getMessage() . PHP_EOL;
}
?>