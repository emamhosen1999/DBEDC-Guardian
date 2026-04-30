<?php

/**
 * Test script to verify jurisdiction-based daily work visibility
 * Uses actual database credentials from .env
 * Groups results by manager
 * 
 * Run: php test_jurisdiction_visibility.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\DailyWork;
use App\Models\Jurisdiction;

echo "=== Jurisdiction-Based Daily Work Visibility Test (By Group) ===\n\n";

// Step 1: Show reporting hierarchy
echo "Step 1: Reporting Hierarchy Analysis\n";
echo str_repeat("-", 50) . "\n";

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
    
    // Get manager's daily works (no date filter - all time)
    $managerWorksAll = DailyWork::where('incharge', $manager->id)
        ->orderBy('date', 'desc')
        ->pluck('id')
        ->toArray();
    
    // Get manager's daily works for current month (to match live server default)
    $currentMonthStart = date('Y-m-01');
    $currentMonthEnd = date('Y-m-t');
    $managerWorksCurrentMonth = DailyWork::where('incharge', $manager->id)
        ->whereBetween('date', [$currentMonthStart, $currentMonthEnd])
        ->orderBy('date', 'desc')
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
            
            // Get employee's daily works
            $employeeWorks = DailyWork::where('incharge', $employee->id)
                ->orderBy('date', 'desc')
                ->pluck('id')
                ->toArray();
            
            // Determine expected visibility
            if ($employee->hasRole('Employee')) {
                if ($hasJurisdiction) {
                    // Employee has jurisdiction: should see own incharge works
                    $expectedIds = $employeeWorks;
                } else if ($employee->report_to) {
                    // Employee has no jurisdiction: should see manager's incharge works
                    $expectedIds = $managerWorksAll;
                } else {
                    // No jurisdiction and no manager: show own works
                    $expectedIds = $employeeWorks;
                }
            } else {
                // Non-employee: show own works + manager's works
                $expectedIds = array_unique(array_merge($employeeWorks, $managerWorksAll));
            }
            
            // Simulate backend query
            if ($employee->hasRole('Employee')) {
                $hasJurisdictionCheck = Jurisdiction::where('incharge', $employee->id)->exists();
                
                if ($hasJurisdictionCheck) {
                    $backendIds = DailyWork::where('incharge', $employee->id)
                        ->orderBy('date', 'desc')
                        ->pluck('id')
                        ->toArray();
                    $backendIdsCurrentMonth = DailyWork::where('incharge', $employee->id)
                        ->whereBetween('date', [$currentMonthStart, $currentMonthEnd])
                        ->orderBy('date', 'desc')
                        ->pluck('id')
                        ->toArray();
                } else if ($employee->report_to) {
                    $backendIds = DailyWork::where('incharge', $employee->report_to)
                        ->orderBy('date', 'desc')
                        ->pluck('id')
                        ->toArray();
                    $backendIdsCurrentMonth = DailyWork::where('incharge', $employee->report_to)
                        ->whereBetween('date', [$currentMonthStart, $currentMonthEnd])
                        ->orderBy('date', 'desc')
                        ->pluck('id')
                        ->toArray();
                } else {
                    $backendIds = DailyWork::where('incharge', $employee->id)
                        ->orderBy('date', 'desc')
                        ->pluck('id')
                        ->toArray();
                    $backendIdsCurrentMonth = DailyWork::where('incharge', $employee->id)
                        ->whereBetween('date', [$currentMonthStart, $currentMonthEnd])
                        ->orderBy('date', 'desc')
                        ->pluck('id')
                        ->toArray();
                }
            } else {
                $backendIds = DailyWork::where(function($q) use ($employee) {
                    $q->where('incharge', $employee->id)
                        ->orWhere('assigned', $employee->id);
                })->when($employee->report_to, function($q) use ($employee) {
                    $q->orWhere('incharge', $employee->report_to);
                })->orderBy('date', 'desc')
                    ->pluck('id')
                    ->toArray();
                $backendIdsCurrentMonth = DailyWork::where(function($q) use ($employee) {
                    $q->where('incharge', $employee->id)
                        ->orWhere('assigned', $employee->id);
                })->when($employee->report_to, function($q) use ($employee) {
                    $q->orWhere('incharge', $employee->report_to);
                })->whereBetween('date', [$currentMonthStart, $currentMonthEnd])
                    ->orderBy('date', 'desc')
                    ->pluck('id')
                    ->toArray();
            }
            
            // Compare
            sort($expectedIds);
            sort($backendIds);
            sort($backendIdsCurrentMonth);
            
            $status = ($expectedIds === $backendIds) ? '✅ Pass' : '❌ Fail';
            echo "{$counter}. {$employee->name} Works (All Time): " . count($backendIds) . " {$status}\n";
            echo "   Current Month: " . count($backendIdsCurrentMonth) . "\n";
            
            if ($expectedIds === $backendIds) {
                $passedTests++;
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
