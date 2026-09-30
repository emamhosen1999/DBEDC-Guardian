<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\Attendance;
use App\Models\HRM\Leave;
use App\Models\HRM\OvertimeRequest;
use App\Models\HRM\Payroll;
use App\Models\HRM\Payslip;
use App\Models\PettyCashLoan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    /**
     * List payroll runs and monthly summaries.
     */
    public function index(Request $request): JsonResponse|Response
    {
        $selectedMonth = $request->input('month', now()->format('Y-m'));

        $start = Carbon::parse($selectedMonth . '-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $query = Payroll::with(['employee:employee_id,name,department_id,designation_id', 'employee.department:id,name', 'employee.designation:id,title'])
            ->where('pay_period_start', $start->toDateString())
            ->where('pay_period_end', $end->toDateString())
            ->when($request->input('search'), function ($q, $search) {
                $q->whereHas('employee', fn ($eq) => $eq->where('name', 'like', "%{$search}%")->orWhere('employee_id', 'like', "%{$search}%"));
            });

        $payrolls = $query->paginate($request->input('per_page', 25));

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payrolls);
        }

        $stats = [
            'total_employees' => Payroll::where('pay_period_start', $start->toDateString())->count(),
            'total_gross' => Payroll::where('pay_period_start', $start->toDateString())->sum('gross_salary'),
            'total_deductions' => Payroll::where('pay_period_start', $start->toDateString())->sum('total_deductions'),
            'total_net' => Payroll::where('pay_period_start', $start->toDateString())->sum('net_salary'),
            'total_overtime' => Payroll::where('pay_period_start', $start->toDateString())->sum('overtime_amount'),
        ];

        return Inertia::render('HR/Payroll', [
            'title' => 'Monthly Payroll & Compensation',
            'payrolls' => $payrolls,
            'stats' => $stats,
            'selectedMonth' => $selectedMonth,
        ]);
    }

    /**
     * Process / Run monthly payroll batch for all active employees.
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        $monthStr = $request->input('month');
        $start = Carbon::parse($monthStr . '-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $totalDaysInMonth = $start->daysInMonth;

        $employees = User::whereNull('deleted_at')
            ->where('date_of_joining', '<=', $end->toDateString())
            ->get();

        $processedCount = 0;

        DB::transaction(function () use ($employees, $start, $end, $totalDaysInMonth, $request, &$processedCount) {
            foreach ($employees as $emp) {
                $grossSalary = (float) ($emp->salary_amount ?? 0);
                if ($grossSalary <= 0) {
                    continue;
                }

                $basicSalary = round($grossSalary * 0.6, 2);
                $dailyRate = round($grossSalary / 30, 2);

                // Attendance stats for the month
                $presentDays = Attendance::where('user_id', $emp->employee_id)
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->whereIn('status', ['present', 'late'])
                    ->count();

                $leaveDays = Attendance::where('user_id', $emp->employee_id)
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->where('status', 'leave')
                    ->count();

                // Approved Overtime
                $otHours = (float) OvertimeRequest::where('user_id', $emp->employee_id)
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->where('status', 'approved')
                    ->sum('overtime_hours');

                $hourlyRate = round($basicSalary / 208, 2); // standard 26 days * 8 hours
                $otAmount = round($otHours * $hourlyRate * 2, 2); // 2x basic under BLA s.108

                // Petty Cash Loan Monthly Installment Deduction
                $loanDeduction = 0.00;
                $activeLoan = PettyCashLoan::where('user_id', $emp->employee_id)
                    ->where('status', 'active')
                    ->where('remaining_amount', '>', 0)
                    ->first();

                if ($activeLoan && $activeLoan->installment_amount > 0) {
                    $loanDeduction = min((float) $activeLoan->installment_amount, (float) $activeLoan->remaining_amount);
                }

                // Unpaid Absence Deduction
                $absentDays = max(0, $totalDaysInMonth - ($presentDays + $leaveDays + 4)); // assuming 4 weekly rest days
                $absentDeduction = round($absentDays * $dailyRate, 2);

                $totalEarnings = round($grossSalary + $otAmount, 2);
                $totalDeductions = round($absentDeduction + $loanDeduction, 2);
                $netSalary = max(0, round($totalEarnings - $totalDeductions, 2));

                $payroll = Payroll::updateOrCreate(
                    [
                        'user_id' => $emp->employee_id,
                        'pay_period_start' => $start->toDateString(),
                        'pay_period_end' => $end->toDateString(),
                    ],
                    [
                        'basic_salary' => $basicSalary,
                        'gross_salary' => $grossSalary,
                        'total_deductions' => $totalDeductions,
                        'net_salary' => $netSalary,
                        'working_days' => $totalDaysInMonth,
                        'present_days' => $presentDays,
                        'absent_days' => $absentDays,
                        'leave_days' => $leaveDays,
                        'overtime_hours' => $otHours,
                        'overtime_amount' => $otAmount,
                        'status' => 'draft',
                        'processed_by' => (string) $request->user()->employee_id,
                        'processed_at' => now(),
                        'remarks' => "Generated for {$start->format('F Y')}",
                    ]
                );

                // Auto-generate / update payslip
                $payslipNum = 'PS-' . $start->format('Ym') . '-' . str_pad($emp->employee_id, 4, '0', STR_PAD_LEFT);
                Payslip::updateOrCreate(
                    [
                        'payroll_id' => $payroll->id,
                        'user_id' => $emp->employee_id,
                    ],
                    [
                        'payslip_number' => $payslipNum,
                        'pay_period_start' => $start->toDateString(),
                        'pay_period_end' => $end->toDateString(),
                        'basic_salary' => $basicSalary,
                        'gross_salary' => $grossSalary,
                        'total_allowances' => $otAmount,
                        'total_deductions' => $totalDeductions,
                        'net_salary' => $netSalary,
                        'generated_at' => now(),
                        'status' => 'draft',
                    ]
                );

                $processedCount++;
            }
        });

        return response()->json([
            'message' => "Payroll successfully generated for {$processedCount} active employees.",
            'month' => $monthStr,
            'processed_count' => $processedCount,
        ]);
    }

    /**
     * View individual payslip details.
     */
    public function payslip(int $id): JsonResponse
    {
        $payslip = Payslip::with(['employee.department', 'employee.designation'])->findOrFail($id);

        return response()->json($payslip);
    }
}
