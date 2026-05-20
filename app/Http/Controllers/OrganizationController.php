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
            
        $activeUsers = User::select('id', 'name', 'email', 'profile_image', 'department_id', 'designation_id')
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
        $initialDepartments = Department::with(['manager:id,name,profile_image', 'parent:id,name'])
            ->withCount('employees')
            ->paginate(10);
            
        $initialDesignations = Designation::with('department:id,name')
            ->withCount('employees')
            ->paginate(10);

        return Inertia::render('Organization/OrganizationPage', [
            'title' => 'Organization Directory',
            
            // Shared Data
            'departments' => $departments,
            'designations' => $designations,
            'attendanceTypes' => $attendanceTypes,
            
            // Employee Tab specific
            'allManagers' => $activeUsers, // or apply your specific manager logic here
            
            // Department Tab specific
            'managers' => $activeUsers,
            'parentDepartments' => $parentDepartments,
            'departmentsData' => $initialDepartments, // Matched to initialDepartments in props
            'stats' => $departmentStats, // DepartmentsTab expects this as initialStats
            
            // Designation Tab specific
            'allDesignations' => $designations,
            'initialDesignations' => $initialDesignations,
            'designationStats' => $designationStats, // If you need to separate it from department stats
            
            // Work Locations Tab specific
            'users' => $activeUsers, 
        ]);
    }
}