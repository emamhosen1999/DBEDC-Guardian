<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\User;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\AttendanceType;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * Render the unified Organization Directory page.
     */
    public function index(Request $request)
    {
        // 1. Shared Base Data (Lightweight for dropdowns and mapping)
        $departments = Department::select('id', 'name', 'code', 'parent_id', 'is_active')->get();
        $designations = Designation::select('id', 'title', 'department_id', 'hierarchy_level', 'parent_id', 'is_active')->get();
        
        // 2. Tab-Specific Prerequisites
        $attendanceTypes = AttendanceType::select('id', 'name', 'slug', 'config', 'is_active')
            ->with(['biometricDevices:id,name,serial_number,location'])
            ->get();
            
        $activeUsers = User::select('id', 'name', 'email', 'department_id', 'designation_id')
            ->whereNull('deleted_at')
            ->get();

        // 3. Department Initial Data (to prevent empty flash before async fetch)
        $parentDepartments = $departments->whereNull('parent_id')->values();
        
        $departmentStats = [
            'total' => $departments->count(),
            'active' => $departments->where('is_active', true)->count(),
            'inactive' => $departments->where('is_active', false)->count(),
            'parent_departments' => $parentDepartments->count(),
        ];

        // 4. Designation Initial Data
        $designationStats = [
            'total' => $designations->count(),
            'active' => $designations->where('is_active', true)->count(),
            'inactive' => $designations->where('is_active', false)->count(),
            'parent_designations' => $designations->whereNull('parent_id')->count(),
        ];

        // 5. Initial Paginated Data (Optional: passing the first page directly to avoid layout shift)
        $initialDepartments = Department::with(['manager:id,name,email', 'parent:id,name'])
            ->withCount('employees')
            ->paginate(10);
            
        $initialDesignations = Designation::with('department:id,name')
            ->withCount(['users as employee_count'])
            ->paginate(10);

        $overviewStats = [
            'total_employees' => $activeUsers->count(),
            'total_departments' => $departments->count(),
            'total_designations' => $designations->count(),
            'total_locations' => $attendanceTypes->count(),
        ];

        return Inertia::render('Organization/OrganizationPage', [
            'title' => 'Organization Directory',
            
            // Shared Data (flat, for dropdowns)
            'departments' => $departments,
            'designations' => $designations,
            'attendanceTypes' => $attendanceTypes,
            
            // Employee Tab specific
            'allManagers' => $activeUsers,
            
            // Department Tab specific
            'managers' => $activeUsers,
            'parentDepartments' => $parentDepartments,
            'departmentsData' => $initialDepartments,
            'stats' => $departmentStats,
            
            // Designation Tab specific
            'allDesignations' => $designations,
            'initialDesignations' => $initialDesignations,
            'designationStats' => $designationStats,
            
            // Work Locations Tab specific
            'users' => $activeUsers, 
            
            // Page Overview Stats
            'overviewStats' => $overviewStats,
        ]);
    }
}