<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\HRM\LeaveSetting;
use App\Traits\HandlesApiExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LeaveSettingController extends Controller
{
    use HandlesApiExceptions;

    public function index(Request $request): Response|JsonResponse
    {
        $leaveSettings = LeaveSetting::orderBy('type')->get();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => $leaveSettings,
            ]);
        }

        return Inertia::render('Settings/LeaveSettings', [
            'title' => 'Leave Settings',
            'leaveTypes' => $leaveSettings,
        ]);
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('leave-settings.update')) {
            return response()->json(['error' => 'Unauthorized to manage leave settings.'], 403);
        }

        $request->validate([
            'type' => 'required|string|max:255',
            'days' => 'required|integer',
            'eligibility' => 'nullable|string',
            'carry_forward' => 'required|boolean',
            'earned_leave' => 'required|boolean',
            'requires_approval' => 'nullable|boolean',
            'auto_approve' => 'nullable|boolean',
            'special_conditions' => 'nullable|string',
        ]);

        try {
            $leaveType = LeaveSetting::create([
                'type' => $request->input('type'),
                'days' => $request->input('days'),
                'eligibility' => $request->input('eligibility'),
                'carry_forward' => $request->input('carry_forward'),
                'earned_leave' => $request->input('earned_leave'),
                'requires_approval' => $request->input('requires_approval', true),
                'auto_approve' => $request->input('auto_approve', false),
                'special_conditions' => $request->input('special_conditions'),
            ]);

            return response()->json([
                'id' => $leaveType->id,
                'message' => 'Leave type added successfully.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add leave type. Please try again.',
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        if (! Auth::user()->can('leave-settings.update')) {
            return response()->json(['error' => 'Unauthorized to manage leave settings.'], 403);
        }

        $request->validate([
            'type' => 'required|string|max:255',
            'days' => 'required|integer',
            'eligibility' => 'nullable|string',
            'carry_forward' => 'required|boolean',
            'earned_leave' => 'required|boolean',
            'requires_approval' => 'nullable|boolean',
            'auto_approve' => 'nullable|boolean',
            'special_conditions' => 'nullable|string',
        ]);

        try {
            $leaveType = LeaveSetting::findOrFail($id);
            $leaveType->update([
                'type' => $request->input('type'),
                'days' => $request->input('days'),
                'eligibility' => $request->input('eligibility'),
                'carry_forward' => $request->input('carry_forward'),
                'earned_leave' => $request->input('earned_leave'),
                'requires_approval' => $request->input('requires_approval', true),
                'auto_approve' => $request->input('auto_approve', false),
                'special_conditions' => $request->input('special_conditions'),
            ]);

            return response()->json([
                'id' => $leaveType->id,
                'message' => 'Leave type updated successfully.',
            ], 200);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update leave type. Please try again.',
                'error_code' => 'LEAVE_TYPE_UPDATE_FAILED',
            ], 500);
        }
    }

    public function destroy($id)
    {
        if (! Auth::user()->can('leave-settings.update')) {
            return response()->json(['error' => 'Unauthorized to manage leave settings.'], 403);
        }

        try {
            // Find the leave type by ID
            $leaveType = LeaveSetting::findOrFail($id);

            // Delete the leave type
            $leaveType->delete();

            return response()->json(['message' => 'Leave type deleted successfully.'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete leave type.'], 500);
        }
    }
}
