<?php

namespace App\Http\Controllers;

use App\Models\WorkLocation;
use App\Models\HRM\AttendanceType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkLocationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Employees/EmployeesPage', [
            'title' => 'Work Locations',
            'workLocations' => WorkLocation::with('attendanceType')->get(),
            'attendanceTypes' => AttendanceType::all(),
            'users' => User::select('id', 'name', 'employee_id', 'department_id', 'designation_id')->with('roles:id,name')->get(),
        ]);
    }

    public function showWorkLocations(): Response
    {
        return Inertia::render('Employees/EmployeesPage', [
            'title' => 'Work Locations Management',
            'workLocations' => WorkLocation::with('attendanceType')->get(),
            'attendanceTypes' => AttendanceType::all(),
            'users' => User::select('id', 'name', 'employee_id', 'department_id', 'designation_id')->with('roles:id,name')->get(),
        ]);
    }

    public function allWorkLocations(Request $request)
    {
        try {
            $workLocations = WorkLocation::with('attendanceType')->get();
            return response()->json([
                'work_locations' => $workLocations,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve work locations',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function addWorkLocation(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'location' => 'required|string|unique:work_locations,name',
                'attendance_type_id' => 'nullable|exists:attendance_types,id',
            ], [
                'location.required' => 'Work location name is required.',
                'location.unique' => 'A work location with this name already exists.',
            ]);

            $workLocation = WorkLocation::create([
                'name' => $validatedData['location'],
                'attendance_type_id' => $validatedData['attendance_type_id'] ?? null,
            ]);

            $workLocations = WorkLocation::with('attendanceType')->get();

            return response()->json([
                'message' => 'Work location added successfully',
                'work_locations' => $workLocations,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateWorkLocation(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'id' => 'required|exists:work_locations,id',
                'location' => 'required|string|unique:work_locations,name,' . $request->id,
                'attendance_type_id' => 'nullable|exists:attendance_types,id',
            ], [
                'location.required' => 'Work location name is required.',
                'location.unique' => 'A work location with this name already exists.',
            ]);

            $workLocation = WorkLocation::findOrFail($validatedData['id']);
            $workLocation->update([
                'name' => $validatedData['location'],
                'attendance_type_id' => $validatedData['attendance_type_id'] ?? null,
            ]);

            $workLocations = WorkLocation::with('attendanceType')->get();

            return response()->json([
                'message' => 'Work location updated successfully',
                'work_locations' => $workLocations,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function deleteWorkLocation(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'id' => 'required|exists:work_locations,id',
            ], [
                'id.required' => 'Work location ID is required.',
                'id.exists' => 'Work location not found.',
            ]);

            $workLocation = WorkLocation::findOrFail($validatedData['id']);
            $workLocation->delete();

            $workLocations = WorkLocation::with('attendanceType')->get();

            return response()->json([
                'message' => 'Work location deleted successfully',
                'work_locations' => $workLocations,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
