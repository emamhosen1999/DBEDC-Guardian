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

        // The standalone Leave Settings page was removed — leave management is now the
        // unified tabbed page. A browser hit redirects there; the JSON branch above still
        // serves leave-type data (consumed by the unified panel via useLeaveTypes).
        return redirect()->route('leaves.index');
    }

    /**
     * Validation rules shared by store/update — base fields + Phase-3 accrual policy.
     */
    private function rules(): array
    {
        return [
            'type' => 'required|string|max:255',
            'days' => 'required|integer',
            'eligibility' => 'nullable|string',
            'carry_forward' => 'required|boolean',
            'earned_leave' => 'required|boolean',
            'requires_approval' => 'nullable|boolean',
            'auto_approve' => 'nullable|boolean',
            'special_conditions' => 'nullable|string',
            // Phase 3 — configurable accrual policy
            'accrual_method' => 'nullable|in:annual_upfront,monthly,none',
            'accrual_rate' => 'nullable|numeric|min:0|max:365',
            'probation_months' => 'nullable|integer|min:0|max:60',
            'prorate_on_join' => 'nullable|boolean',
            'carry_forward_cap' => 'nullable|numeric|min:0|max:365',
            'carry_expiry_months' => 'nullable|integer|min:0|max:60',
            'is_encashable' => 'nullable|boolean',
            'allow_negative' => 'nullable|boolean',
            // Policy hardening — structured eligibility + encashment cap
            'eligible_gender' => 'nullable|in:male,female',
            'min_service_months' => 'nullable|integer|min:0|max:600',
            'max_encash_days' => 'nullable|numeric|min:0|max:365',
        ];
    }

    /**
     * Build the persistable payload from a validated request (base + policy).
     */
    private function payload(Request $request): array
    {
        return [
            'type' => $request->input('type'),
            'days' => $request->input('days'),
            'eligibility' => $request->input('eligibility'),
            'carry_forward' => $request->boolean('carry_forward'),
            'earned_leave' => $request->boolean('earned_leave'),
            'requires_approval' => $request->input('requires_approval', true),
            'auto_approve' => $request->input('auto_approve', false),
            'special_conditions' => $request->input('special_conditions'),
            // Policy (fall back to the entitlement/flags when unspecified).
            'accrual_method' => $request->input('accrual_method', $request->boolean('earned_leave') ? 'monthly' : 'annual_upfront'),
            'accrual_rate' => $request->input('accrual_rate', $request->input('days')),
            'probation_months' => $request->input('probation_months', 0),
            'prorate_on_join' => $request->boolean('prorate_on_join', true),
            'carry_forward_cap' => $request->input('carry_forward_cap'),
            'carry_expiry_months' => $request->input('carry_expiry_months'),
            'is_encashable' => $request->boolean('is_encashable'),
            'allow_negative' => $request->boolean('allow_negative'),
            'eligible_gender' => $request->input('eligible_gender'),
            'min_service_months' => $request->input('min_service_months'),
            'max_encash_days' => $request->input('max_encash_days'),
        ];
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('leave-settings.update')) {
            return response()->json(['error' => 'Unauthorized to manage leave settings.'], 403);
        }

        $request->validate($this->rules());

        try {
            $leaveType = LeaveSetting::create($this->payload($request));

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

        $request->validate($this->rules());

        try {
            $leaveType = LeaveSetting::findOrFail($id);
            $leaveType->update($this->payload($request));

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
