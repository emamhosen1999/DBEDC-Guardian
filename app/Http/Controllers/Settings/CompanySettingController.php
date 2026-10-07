<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\Approvals\ApprovalRouting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanySettingController extends Controller
{
    public function index()
    {
        $companySettings = CompanySetting::first() ? CompanySetting::first() : [];

        $canSetEscalation = (bool) request()->user()?->hasRole(ApprovalRouting::LAST_RESORT_ROLE);

        return Inertia::render('Settings/CompanySettings', [
            'title' => 'Company Settings',
            'companySettings' => $companySettings,
            'escalation' => [
                'can_edit' => $canSetEscalation,
                'approver_id' => app(ApprovalRouting::class)->configuredEscalationApproverId(),
                'has_hr_manager' => app(ApprovalRouting::class)->hasEscalationApprover(),
                'candidates' => $canSetEscalation
                    ? User::query()->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
                        ->orderBy('name')->get(['employee_id', 'name'])
                        ->map(fn (User $u) => ['id' => (string) $u->employee_id, 'name' => (string) $u->name])->values()
                    : [],
            ],
        ]);
    }

    /** approvals.escalation_approver_id - Super Administrator only. */
    public function updateEscalationApprover(Request $request)
    {
        abort_unless($request->user()?->hasRole(ApprovalRouting::LAST_RESORT_ROLE), 403, 'Only a Super Administrator can set the escalation approver.');

        $data = $request->validate([
            'escalation_approver_id' => ['nullable', 'string', 'exists:users,employee_id'],
        ]);
        $id = $data['escalation_approver_id'] ?? null;

        if ($id !== null && User::whereKey($id)->where('is_active', false)->exists()) {
            return response()->json(['message' => 'The escalation approver must be an active employee.', 'errors' => ['escalation_approver_id' => ['The escalation approver must be an active employee.']]], 422);
        }

        $settings = CompanySetting::first() ?? new CompanySetting;
        $settings->forceFill(['escalation_approver_id' => $id])->save();

        return response()->json(['message' => 'Escalation approver updated.', 'approver_id' => $id]);
    }

    public function update(Request $request)
    {
        // Validate the incoming request data
        $validatedData = $request->validate([
            'companyName' => 'required|string|max:255',
            'contactPerson' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'country' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'postalCode' => 'required|string|max:10',
            'email' => 'required|email|max:255',
            'phoneNumber' => 'nullable|string|max:20',
            'mobileNumber' => 'nullable|string|max:20',
            'fax' => 'nullable|string|max:20',
            'websiteUrl' => 'nullable|url|max:255',
        ]);

        try {
            $companySettings = CompanySetting::first();
            $companySettings ? $companySettings->update(
                $validatedData
            ) : $companySettings = CompanySetting::create(
                $validatedData
            );

            // Return success response
            return response()->json([
                'message' => 'Company settings updated successfully.',
                'companySettings' => $companySettings,
            ], 200);

        } catch (\Exception $e) {
            // Return error response in case of an exception
            return response()->json([
                'message' => 'An error occurred while updating company settings.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
