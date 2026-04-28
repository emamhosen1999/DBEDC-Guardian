<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DailyWork;
use App\Models\DailyWorkSummary;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

echo "=== Daily Works Diagnostic Test ===\n\n";

// Test 1: Check if daily works exist in database
echo "Test 1: Checking daily works count in database...\n";
try {
    $totalDailyWorks = DailyWork::count();
    echo "Total daily works in database: " . $totalDailyWorks . "\n";
    
    if ($totalDailyWorks > 0) {
        $latestWork = DailyWork::latest()->first();
        echo "Latest daily work: ID=" . $latestWork->id . ", Date=" . $latestWork->date . ", Number=" . $latestWork->number . "\n";
        
        $earliestDate = DailyWork::min('date');
        $latestDate = DailyWork::max('date');
        echo "Date range in database: " . $earliestDate . " to " . $latestDate . "\n";
    } else {
        echo "WARNING: No daily works found in database!\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: Check daily work summaries
echo "Test 2: Checking daily work summaries...\n";
try {
    $totalSummaries = DailyWorkSummary::count();
    echo "Total daily work summaries: " . $totalSummaries . "\n";
    
    if ($totalSummaries > 0) {
        $latestSummary = DailyWorkSummary::latest()->first();
        echo "Latest summary: Date=" . $latestSummary->date . ", Incharge=" . $latestSummary->incharge . ", Total=" . $latestSummary->totalDailyWorks . "\n";
    } else {
        echo "No summaries found (this is normal if no imports have been done yet)\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: Test pagination service directly
echo "Test 3: Testing pagination service...\n";
try {
    $user = User::first();
    if (!$user) {
        echo "ERROR: No users found in database!\n";
    } else {
        echo "Using user: ID=" . $user->id . ", Name=" . $user->name . "\n";
        
        // Simulate pagination request
        $request = new Illuminate\Http\Request([
            'startDate' => DailyWork::min('date') ?? '2020-01-01',
            'endDate' => DailyWork::max('date') ?? date('Y-m-d'),
            'page' => 1,
            'perPage' => 30,
        ]);
        
        // Authenticate the user for the request
        Auth::login($user);
        
        $paginationService = app(\App\Services\DailyWork\DailyWorkPaginationService::class);
        $result = $paginationService->getPaginatedDailyWorks($request);
        
        echo "Pagination result type: " . get_class($result) . "\n";
        echo "Total records: " . $result->total() . "\n";
        echo "Current page: " . $result->currentPage() . "\n";
        echo "Items on current page: " . $result->count() . "\n";
        
        if ($result->count() > 0) {
            echo "First item: " . json_encode($result->first()->toArray()) . "\n";
        }
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "\n";

// Test 4: Check if there are any works in the date range from the screenshot
echo "Test 4: Checking date range from screenshot (2022-08-04 to 2025-12-19)...\n";
try {
    $worksInRange = DailyWork::whereBetween('date', ['2022-08-04', '2025-12-19'])->count();
    echo "Daily works in range 2022-08-04 to 2025-12-19: " . $worksInRange . "\n";
    
    if ($worksInRange > 0) {
        $sampleWork = DailyWork::whereBetween('date', ['2022-08-04', '2025-12-19'])->first();
        echo "Sample work in range: ID=" . $sampleWork->id . ", Date=" . $sampleWork->date . "\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
