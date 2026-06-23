<?php

namespace App\Http\Controllers;

use App\Models\WorkLocation;
use App\Models\HRM\AttendanceType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkLocationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Employees/EmployeesPage', [
            'title' => 'Work Locations',
            'workLocations' => $this->locationsWithMeta(),
            'attendanceTypes' => AttendanceType::all(),
            'users' => User::select('id', 'name', 'employee_id', 'department_id', 'designation_id')->with('roles:id,name')->get(),
        ]);
    }

    public function showWorkLocations(): Response
    {
        return Inertia::render('Employees/EmployeesPage', [
            'title' => 'Work Locations Management',
            'workLocations' => $this->locationsWithMeta(),
            'attendanceTypes' => AttendanceType::all(),
            'users' => User::select('id', 'name', 'employee_id', 'department_id', 'designation_id')->with('roles:id,name')->get(),
        ]);
    }

    public function allWorkLocations(Request $request)
    {
        try {
            return response()->json([
                'work_locations' => $this->locationsWithMeta(),
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
            $validatedData = $this->validatePayload($request);

            WorkLocation::create($validatedData);

            return response()->json([
                'message' => 'Work location added successfully',
                'work_locations' => $this->locationsWithMeta(),
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
            $request->validate(['id' => 'required|exists:work_locations,id']);
            $workLocation = WorkLocation::findOrFail($request->id);

            $validatedData = $this->validatePayload($request, $workLocation->id);
            $workLocation->update($validatedData);

            return response()->json([
                'message' => 'Work location updated successfully',
                'work_locations' => $this->locationsWithMeta(),
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
            $request->validate([
                'id' => 'required|exists:work_locations,id',
            ], [
                'id.required' => 'Work location ID is required.',
                'id.exists' => 'Work location not found.',
            ]);

            $workLocation = WorkLocation::withCount('employees')->findOrFail($request->id);

            // Dependency guard: never silently orphan assigned employees.
            if ($workLocation->employees_count > 0) {
                return response()->json([
                    'error' => 'work_location_in_use',
                    'message' => "This location has {$workLocation->employees_count} assigned employee(s). Reassign them before deleting.",
                ], 409);
            }

            $workLocation->delete();

            return response()->json([
                'message' => 'Work location deleted successfully',
                'work_locations' => $this->locationsWithMeta(),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Work locations with their default rule and assigned-employee count.
     */
    protected function locationsWithMeta()
    {
        return WorkLocation::with('attendanceType')->withCount('employees')->get();
    }

    /**
     * Validate and normalise the work-location payload for create/update.
     * Accepts the legacy `location` alias for the name field.
     */
    protected function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        // Support both `name` and the legacy `location` field used by the form.
        if ($request->filled('location') && !$request->filled('name')) {
            $request->merge(['name' => $request->input('location')]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('work_locations', 'name')->ignore($ignoreId)],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('work_locations', 'code')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'geofence_radius' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'is_active' => ['nullable', 'boolean'],
            'attendance_type_id' => ['nullable', 'exists:attendance_types,id'],
        ], [
            'name.required' => 'Work location name is required.',
            'name.unique' => 'A work location with this name already exists.',
            'code.unique' => 'A work location with this code already exists.',
        ]);

        $validated['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;

        return $validated;
    }
}
