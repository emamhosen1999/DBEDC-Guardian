<?php

/**
 * Specific test scenario: Prodip (manager) and Fuad (employee)
 * Demonstrates that employee without jurisdiction sees manager's works
 * 
 * Run: php test_specific_scenario.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\DailyWork;
use App\Models\Jurisdiction;

echo "=== Specific Test Scenario: Prodip and Fuad ===\n\n";

// Get Prodip (manager)
$prodip = User::where('name', 'like', '%Prodip%')->first();

if (!$prodip) {
    echo "❌ Prodip not found in database\n";
    exit(1);
}

echo "Manager: {$prodip->name} (ID: {$prodip->id})\n";
echo "Roles: " . $prodip->roles->pluck('name')->implode(', ') . "\n\n";

// Get Fuad (employee who reports to Prodip)
$fuad = User::where('name', 'like', '%Fuad%')->where('report_to', $prodip->id)->first();

if (!$fuad) {
    echo "❌ Fuad not found or does not report to Prodip\n";
    exit(1);
}

echo "Employee: {$fuad->name} (ID: {$fuad->id})\n";
echo "Roles: " . $fuad->roles->pluck('name')->implode(', ') . "\n";
echo "Reports to: {$prodip->name} (ID: {$prodip->id})\n";

$hasJurisdiction = Jurisdiction::where('incharge', $fuad->id)->exists();
echo "Has Jurisdiction: " . ($hasJurisdiction ? 'Yes' : 'No') . "\n\n";

// Get Prodip's incharge works
$prodipWorks = DailyWork::where('incharge', $prodip->id)
    ->orderBy('date', 'desc')
    ->get(['id', 'number', 'date', 'status']);

echo str_repeat("=", 70) . "\n";
echo "Prodip's Incharge Works\n";
echo str_repeat("=", 70) . "\n";
echo "Total: {$prodipWorks->count()} works\n\n";

if ($prodipWorks->count() > 0) {
    echo "Sample works (first 5):\n";
    foreach ($prodipWorks->take(5) as $work) {
        echo "  - {$work->number} ({$work->date}) - Status: {$work->status}\n";
    }
} else {
    echo "(No works found)\n";
}

// Get Fuad's incharge works
$fuadWorks = DailyWork::where('incharge', $fuad->id)
    ->orderBy('date', 'desc')
    ->get(['id', 'number', 'date', 'status']);

echo "\n" . str_repeat("=", 70) . "\n";
echo "Fuad's Incharge Works\n";
echo str_repeat("=", 70) . "\n";
echo "Total: {$fuadWorks->count()} works\n\n";

if ($fuadWorks->count() > 0) {
    echo "Sample works (first 5):\n";
    foreach ($fuadWorks->take(5) as $work) {
        echo "  - {$work->number} ({$work->date}) - Status: {$work->status}\n";
    }
} else {
    echo "(No works found)\n";
}

// Expected: Fuad (without jurisdiction) should see Prodip's works
echo "\n" . str_repeat("=", 70) . "\n";
echo "Expected Visibility for Fuad\n";
echo str_repeat("=", 70) . "\n";

if ($fuad->hasRole('Employee') && !$hasJurisdiction && $fuad->report_to) {
    echo "Fuad is Employee without jurisdiction\n";
    echo "Expected to see: Prodip's incharge works ({$prodipWorks->count()} works)\n";
    $expectedWorks = $prodipWorks;
} else {
    echo "Fuad does not match the test scenario (Employee without jurisdiction)\n";
    echo "Actual status:\n";
    echo "  - Is Employee: " . ($fuad->hasRole('Employee') ? 'Yes' : 'No') . "\n";
    echo "  - Has Jurisdiction: " . ($hasJurisdiction ? 'Yes' : 'No') . "\n";
    echo "  - Has Manager: " . ($fuad->report_to ? 'Yes' : 'No') . "\n";
    exit(1);
}

// Backend query simulation
echo "\n" . str_repeat("=", 70) . "\n";
echo "Backend Query Simulation\n";
echo str_repeat("=", 70) . "\n";

if ($fuad->hasRole('Employee')) {
    $hasJurisdictionCheck = Jurisdiction::where('incharge', $fuad->id)->exists();
    
    if ($hasJurisdictionCheck) {
        $backendWorks = DailyWork::where('incharge', $fuad->id)
            ->orderBy('date', 'desc')
            ->get(['id', 'number', 'date', 'status']);
    } else if ($fuad->report_to) {
        $backendWorks = DailyWork::where('incharge', $fuad->report_to)
            ->orderBy('date', 'desc')
            ->get(['id', 'number', 'date', 'status']);
    } else {
        $backendWorks = DailyWork::where('incharge', $fuad->id)
            ->orderBy('date', 'desc')
            ->get(['id', 'number', 'date', 'status']);
    }
} else {
    $backendWorks = DailyWork::where(function($q) use ($fuad) {
        $q->where('incharge', $fuad->id)
            ->orWhere('assigned', $fuad->id);
    })->when($fuad->report_to, function($q) use ($fuad) {
        $q->orWhere('incharge', $fuad->report_to);
    })->orderBy('date', 'desc')
        ->get(['id', 'number', 'date', 'status']);
}

echo "Backend returns: {$backendWorks->count()} works\n\n";

if ($backendWorks->count() > 0) {
    echo "Sample works (first 5):\n";
    foreach ($backendWorks->take(5) as $work) {
        echo "  - {$work->number} ({$work->date}) - Status: {$work->status}\n";
    }
} else {
    echo "(No works returned)\n";
}

// Comparison
echo "\n" . str_repeat("=", 70) . "\n";
echo "Test Result\n";
echo str_repeat("=", 70) . "\n";

$expectedIds = $expectedWorks->pluck('id')->sort()->values()->toArray();
$backendIds = $backendWorks->pluck('id')->sort()->values()->toArray();

echo "Expected work count: " . count($expectedIds) . "\n";
echo "Backend work count: " . count($backendIds) . "\n";

if ($expectedIds === $backendIds) {
    echo "\n✅ PASS: Fuad sees exactly Prodip's works!\n";
    echo "   Prodip's Works: " . count($expectedIds) . "\n";
    echo "   Fuad's Work: " . count($backendIds) . " (sees Prodip's works)\n";
} else {
    echo "\n❌ FAIL: Fuad does NOT see Prodip's works\n";
    $diffExpected = array_diff($expectedIds, $backendIds);
    $diffBackend = array_diff($backendIds, $expectedIds);
    if (!empty($diffExpected)) {
        echo "   Expected but not in backend: " . count($diffExpected) . " works\n";
    }
    if (!empty($diffBackend)) {
        echo "   Backend but not expected: " . count($diffBackend) . " works\n";
    }
}

echo "\n=== Test Complete ===\n";
