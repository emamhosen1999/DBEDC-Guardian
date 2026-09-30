<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\FinalSettlement;
use App\Models\HRM\Offboarding;
use App\Models\PettyCashLoan;
use App\Services\Access\DepartmentScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettlementController extends Controller
{
    public function __construct(private readonly DepartmentScope $scope) {}

    /**
     * Auto-calculate the Full & Final Settlement for an offboarding record.
     */
    public function calculate(Request $request, int $offboardingId): JsonResponse
    {
        $offboarding = Offboarding::with(['employee.department', 'employee.designation'])->findOrFail($offboardingId);
        abort_unless($this->scope->canActOn($request->user(), $offboarding->employee_id), 403, 'This employee is outside your scope.');
        $employee = $offboarding->employee;

        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $lwd = Carbon::parse($offboarding->last_working_date);
        $monthlyGross = (float) ($employee->salary_amount ?? 0);
        $dailyRate = $monthlyGross > 0 ? round($monthlyGross / 30, 2) : 0.00;

        // 1. Pro-rated working days in the last month up to LWD
        $payableDays = (float) $lwd->day;
        $earnedSalary = round($payableDays * $dailyRate, 2);

        // 2. Unavailed Earned Leave Balance (BLA Section 117 encashment)
        $leaveLedgerBalance = DB::table('leave_ledger')
            ->where('employee_id', $employee->employee_id)
            ->where('leave_type', 'earned')
            ->orderByDesc('id')
            ->value('balance');

        $unavailedLeaveDays = max(0, (float) ($leaveLedgerBalance ?? 0));
        $leaveEncashment = round($unavailedLeaveDays * $dailyRate, 2);

        // 3. Service Gratuity under BLA Section 27 (if completed service >= 5 years and not absconded/terminated for misconduct)
        $gratuityAmount = 0.00;
        if ($employee->date_of_joining && ! in_array($offboarding->reason, [Offboarding::REASON_ABSCONDED, Offboarding::REASON_TERMINATION], true)) {
            $doj = Carbon::parse($employee->date_of_joining);
            $serviceYears = $doj->diffInYears($lwd);
            if ($serviceYears >= 5) {
                // 30 days basic salary per year of completed service
                $basicSalary = $monthlyGross * 0.6; // standard 60% basic split
                $gratuityAmount = round($serviceYears * $basicSalary, 2);
            }
        }

        // 4. Notice Shortfall Recovery Deduction
        $noticeShortfallDays = (int) ($offboarding->notice_shortfall_days ?? 0);
        $noticeShortfallDeduction = round($noticeShortfallDays * $dailyRate, 2);

        // 5. Active Petty Cash Loan Recovery Balance
        $loanRecovery = (float) PettyCashLoan::where('user_id', $employee->employee_id)
            ->where('status', 'active')
            ->sum('remaining_amount');

        // Net settlement summary
        $totalEarnings = round($earnedSalary + $leaveEncashment + $gratuityAmount, 2);
        $totalDeductions = round($noticeShortfallDeduction + $loanRecovery, 2);
        $netPayable = round($totalEarnings - $totalDeductions, 2);

        // Check if existing saved settlement exists
        $existing = FinalSettlement::where('offboarding_id', $offboardingId)->first();

        return response()->json([
            'offboarding_id' => $offboarding->id,
            'employee' => [
                'employee_id' => $employee->employee_id,
                'name' => $employee->name,
                'designation' => $employee->designation?->title ?? 'N/A',
                'department' => $employee->department?->name ?? 'N/A',
                'date_of_joining' => $employee->date_of_joining,
                'bank_name' => $employee->bank_name,
                'bank_account_no' => $employee->bank_account_no,
            ],
            'last_working_date' => $lwd->toDateString(),
            'monthly_gross_salary' => $monthlyGross,
            'daily_rate' => $dailyRate,
            'payable_working_days' => $payableDays,
            'earned_salary' => $earnedSalary,
            'unavailed_leave_days' => $unavailedLeaveDays,
            'leave_encashment_amount' => $leaveEncashment,
            'gratuity_amount' => $gratuityAmount,
            'other_earnings' => 0.00,
            'total_earnings' => $totalEarnings,
            'notice_shortfall_days' => $noticeShortfallDays,
            'notice_shortfall_deduction' => $noticeShortfallDeduction,
            'loan_recovery_amount' => $loanRecovery,
            'asset_damage_deduction' => 0.00,
            'other_deductions' => 0.00,
            'total_deductions' => $totalDeductions,
            'net_payable' => $netPayable,
            'existing_settlement' => $existing,
        ]);
    }

    /**
     * Save / Update a Full & Final Settlement record.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'offboarding_id' => 'required|exists:offboardings,id',
            'monthly_gross_salary' => 'required|numeric|min:0',
            'daily_rate' => 'required|numeric|min:0',
            'payable_working_days' => 'required|numeric|min:0',
            'earned_salary' => 'required|numeric|min:0',
            'unavailed_leave_days' => 'nullable|numeric|min:0',
            'leave_encashment_amount' => 'nullable|numeric|min:0',
            'gratuity_amount' => 'nullable|numeric|min:0',
            'other_earnings' => 'nullable|numeric|min:0',
            'total_earnings' => 'required|numeric',
            'notice_shortfall_days' => 'nullable|integer|min:0',
            'notice_shortfall_deduction' => 'nullable|numeric|min:0',
            'loan_recovery_amount' => 'nullable|numeric|min:0',
            'asset_damage_deduction' => 'nullable|numeric|min:0',
            'other_deductions' => 'nullable|numeric|min:0',
            'total_deductions' => 'required|numeric',
            'net_payable' => 'required|numeric',
            'remarks' => 'nullable|string',
        ]);

        $offboarding = Offboarding::findOrFail($validated['offboarding_id']);
        abort_unless($this->scope->canManage($request->user(), $offboarding->employee_id), 403, 'This employee is outside your scope.');

        $current = FinalSettlement::where('offboarding_id', $offboarding->id)->first();
        if ($current && $current->status !== FinalSettlement::STATUS_DRAFT) {
            return response()->json(['message' => 'Only a draft settlement can be edited.'], 422);
        }

        $validated['employee_id'] = $offboarding->employee_id;
        $validated['last_working_date'] = $offboarding->last_working_date;
        $validated['prepared_by'] = (string) $request->user()->employee_id;
        $validated['status'] = FinalSettlement::STATUS_DRAFT;

        $settlement = FinalSettlement::updateOrCreate(
            ['offboarding_id' => $offboarding->id],
            $validated
        );

        return response()->json([
            'message' => 'Full & Final Settlement voucher saved successfully.',
            'settlement' => $settlement,
        ], 200);
    }

    /**
     * Approve a settlement.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $settlement = FinalSettlement::findOrFail($id);
        $actorId = (string) $request->user()->employee_id;

        abort_unless($this->scope->canManage($request->user(), $settlement->employee_id), 403, 'This employee is outside your scope.');

        // Four-eyes: the preparer and the employee being paid can never approve.
        abort_if($actorId === (string) $settlement->prepared_by || $actorId === (string) $settlement->employee_id, 403, 'You cannot approve a settlement you prepared or that is payable to you.');

        if ($settlement->status !== FinalSettlement::STATUS_DRAFT) {
            return response()->json(['message' => 'Only a draft settlement can be approved.'], 422);
        }

        $settlement->update([
            'status' => FinalSettlement::STATUS_APPROVED,
            'approved_by' => (string) $request->user()->employee_id,
        ]);

        return response()->json([
            'message' => 'Settlement voucher approved.',
            'settlement' => $settlement,
        ]);
    }

    /**
     * Disburse / Mark settlement as paid.
     */
    public function disburse(Request $request, int $id): JsonResponse
    {
        $settlement = FinalSettlement::findOrFail($id);

        abort_unless($this->scope->canManage($request->user(), $settlement->employee_id), 403, 'This employee is outside your scope.');

        if ($settlement->status !== FinalSettlement::STATUS_APPROVED) {
            return response()->json(['message' => 'Only an approved settlement can be disbursed.'], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'required|string|in:bank_transfer,cheque,cash',
            'payment_reference' => 'nullable|string|max:100',
        ]);

        DB::transaction(function () use ($settlement, $validated) {
            $settlement->update([
                'status' => FinalSettlement::STATUS_PAID,
                'payment_method' => $validated['payment_method'],
                'payment_reference' => $validated['payment_reference'] ?? null,
                'paid_at' => now(),
            ]);

            // If loan recovery was deducted, close active loans for this employee
            if ($settlement->loan_recovery_amount > 0) {
                PettyCashLoan::where('user_id', $settlement->employee_id)
                    ->where('status', 'active')
                    ->update([
                        'remaining_amount' => 0,
                        'status' => 'settled',
                    ]);
            }
        });

        return response()->json([
            'message' => 'Settlement payment marked as disbursed.',
            'settlement' => $settlement->fresh(),
        ]);
    }

    /**
     * Generate printable Experience & Release Certificate (BLA Section 31).
     */
    public function printCertificate(Request $request, int $offboardingId, string $type = 'experience'): JsonResponse
    {
        $offboarding = Offboarding::with(['employee.department', 'employee.designation'])->findOrFail($offboardingId);
        abort_unless($this->scope->canActOn($request->user(), $offboarding->employee_id, allowSelf: true), 403, 'This employee is outside your scope.');
        $employee = $offboarding->employee;

        if (! $employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        $doj = $employee->date_of_joining ? Carbon::parse($employee->date_of_joining)->format('F d, Y') : 'N/A';
        $lwd = $offboarding->last_working_date ? Carbon::parse($offboarding->last_working_date)->format('F d, Y') : 'N/A';

        $certificate = [
            'type' => $type,
            'title' => $type === 'release' ? 'RELEASE & CLEARANCE CERTIFICATE' : 'CERTIFICATE OF EXPERIENCE',
            'reference_no' => 'DBEDC/HR/'.date('Y').'/'.str_pad($offboarding->id, 4, '0', STR_PAD_LEFT),
            'issue_date' => now()->format('F d, Y'),
            'company_name' => 'Dhaka Bypass Expressway Development Company Limited (DBEDC)',
            'employee' => [
                'name' => $employee->name,
                'employee_id' => $employee->employee_id,
                'designation' => $employee->designation?->title ?? 'N/A',
                'department' => $employee->department?->name ?? 'N/A',
                'date_of_joining' => $doj,
                'last_working_date' => $lwd,
            ],
            'body' => $type === 'release'
                ? "This is to certify that {$employee->name} (Employee ID: {$employee->employee_id}), who served as {$employee->designation?->title} in the Department of {$employee->department?->name} from {$doj} to {$lwd}, has been officially released from all duties. All organizational clearances, company assets, and financial accounts have been satisfactorily settled."
                : "This is to certify that {$employee->name} was employed with Dhaka Bypass Expressway Development Company Limited (DBEDC) as {$employee->designation?->title} from {$doj} to {$lwd}. During their tenure, their work conduct and professional performance were found to be satisfactory. We wish them all success in their future endeavors.",
        ];

        return response()->json($certificate);
    }
}
