<?php

/**
 * Test script using actual backend code logic
 * Copies the exact logic from DailyWorkPaginationService::buildBaseQuery
 * 
 * Run: php test_backend_service.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\DailyWork;
use App\Models\Jurisdiction;

echo "=== Backend Logic Test - Using Actual Backend Code ===\n\n";

// Function that copies the exact logic from DailyWorkPaginationService::buildBaseQuery
function buildBaseQueryLogic(User $user) {
    // Include active objections count for RFI warning indicators
    $baseQuery = DailyWork::with([
        'inchargeUser:id,name', // Load user names for display
        'assignedUser:id,name',  // Load assigned user names
    ])->withCount(['activeObjections']);

    // Super Administrator and Administrator get all data
    if ($user->hasRole('Super Administrator') || $user->hasRole('Administrator')) {
        return $baseQuery;
    }

    // Employee logic based on jurisdiction incharge
    if ($user->hasRole('Employee')) {
        // Check if user is incharge of any jurisdiction
        $hasJurisdiction = \App\Models\Jurisdiction::where('incharge', $user->id)->exists();
        
        if ($hasJurisdiction) {
            // Employee has jurisdiction (is incharge of a jurisdiction): show works where they are incharge
            return $baseQuery->where('incharge', $user->id);
        } else {
            // Employee has no jurisdiction: show works where their manager (report_to) is incharge
            if ($user->report_to) {
                return $baseQuery->where('incharge', $user->report_to);
            }
            // No jurisdiction and no manager: show own works
            return $baseQuery->where('incharge', $user->id);
        }
    }

    // For other roles (non-employee, non-admin): show own works (incharge/assigned) AND manager's works (incharge) if report_to is set
    if ($user->report_to) {
        return $baseQuery->where(function ($q) use ($user) {
            $q->where('incharge', $user->id)
                ->orWhere('assigned', $user->id)
                ->orWhere('incharge', $user->report_to);
        });
    }

    // Otherwise, show only own works (incharge or assigned)
    return $baseQuery->where(function ($q) use ($user) {
        $q->where('incharge', $user->id)
            ->orWhere('assigned', $user->id);
    });
}

// Get all users with report_to set
$usersWithManager = User::whereNotNull('report_to')
    ->where('report_to', '!=', 0)
    ->with(['roles'])
    ->get(['id', 'name', 'email', 'report_to']);

echo "Found {$usersWithManager->count()} users with managers\n\n";

// Group users by their manager
$managerGroups = [];
foreach ($usersWithManager as $user) {
    $managerId = $user->report_to;
    if (!isset($managerGroups[$managerId])) {
        $managerGroups[$managerId] = [];
    }
    $managerGroups[$managerId][] = $user;
}

// Get manager details
$managerIds = array_keys($managerGroups);
$managers = User::whereIn('id', $managerIds)->get(['id', 'name', 'email']);

$totalTests = 0;
$passedTests = 0;

foreach ($managers as $manager) {
    echo str_repeat("=", 70) . "\n";
    echo "Incharge: {$manager->name}\n";
    echo str_repeat("=", 70) . "\n";
    
    // Get manager's daily works using the actual backend logic
    $managerQuery = buildBaseQueryLogic($manager);
    $managerWorksAll = $managerQuery->pluck('id')->toArray();
    
    // Get manager's daily works for current month
    $currentMonthStart = date('Y-m-01');
    $currentMonthEnd = date('Y-m-t');
    $managerWorksCurrentMonth = DailyWork::where('incharge', $manager->id)
        ->whereBetween('date', [$currentMonthStart, $currentMonthEnd])
        ->pluck('id')
        ->toArray();
    
    echo "Works (All Time): " . count($managerWorksAll) . "\n";
    echo "Works (Current Month): " . count($managerWorksCurrentMonth) . "\n";
    
    $employees = $managerGroups[$manager->id] ?? [];
    
    if (count($employees) > 0) {
        echo "\nHis Reporters:\n";
        $counter = 1;
        
        foreach ($employees as $employee) {
            $totalTests++;
            
            $hasJurisdiction = Jurisdiction::where('incharge', $employee->id)->exists();
            
            // Use the actual backend logic to build the query
            $employeeQuery = buildBaseQueryLogic($employee);
            $backendIds = $employeeQuery->pluck('id')->toArray();
            
            // Get current month count using the same logic
            $employeeQueryCurrentMonth = buildBaseQueryLogic($employee);
            $backendIdsCurrentMonth = $employeeQueryCurrentMonth
                ->whereBetween('date', [$currentMonthStart, $currentMonthEnd])
                ->pluck('id')
                ->toArray();
            
            // Determine expected visibility (same as backend logic)
            if ($employee->hasRole('Employee')) {
                if ($hasJurisdiction) {
                    $expectedIds = DailyWork::where('incharge', $employee->id)->pluck('id')->toArray();
                } else if ($employee->report_to) {
                    $expectedIds = $managerWorksAll;
                } else {
                    $expectedIds = DailyWork::where('incharge', $employee->id)->pluck('id')->toArray();
                }
            } else {
                $expectedIds = array_unique(array_merge(
                    DailyWork::where('incharge', $employee->id)->pluck('id')->toArray(),
                    DailyWork::where('assigned', $employee->id)->pluck('id')->toArray(),
                    $managerWorksAll
                ));
            }
            
            // Compare
            sort($expectedIds);
            sort($backendIds);
            
            $status = ($expectedIds === $backendIds) ? '✅ Pass' : '❌ Fail';
            echo "{$counter}. {$employee->name} Works (All Time): " . count($backendIds) . " {$status}\n";
            echo "   Current Month: " . count($backendIdsCurrentMonth) . "\n";
            echo "   Has Jurisdiction: " . ($hasJurisdiction ? 'Yes' : 'No') . "\n";
            echo "   Role: " . $employee->roles->pluck('name')->implode(', ') . "\n";
            
            if ($expectedIds === $backendIds) {
                $passedTests++;
            } else {
                echo "   ❌ Expected: " . count($expectedIds) . ", Got: " . count($backendIds) . "\n";
            }
            
            $counter++;
        }
    } else {
        echo "No reporters found\n";
    }
    
    echo "\n";
}

echo str_repeat("=", 70) . "\n";
echo "SUMMARY\n";
echo str_repeat("=", 70) . "\n";
echo "Total tests: {$totalTests}\n";
echo "Passed: {$passedTests}\n";
echo "Failed: " . ($totalTests - $passedTests) . "\n";

if ($passedTests === $totalTests) {
    echo "\n✅ ALL TESTS PASSED\n";
} else {
    echo "\n❌ SOME TESTS FAILED\n";
}

echo "\n=== Test Complete ===\n";
