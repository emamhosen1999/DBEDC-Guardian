<?php

namespace App\Services\Operations;

use App\Models\OmTollExemption;
use App\Models\OmTollRecord;
use App\Models\OmTollShiftAudit;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OmTollAuditService
{
    /**
     * Get Toll plaza records.
     */
    public function getTollRecords(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = OmTollRecord::query();

        if (! empty($filters['payment_method']) && $filters['payment_method'] !== 'all') {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (! empty($filters['vehicle_class']) && $filters['vehicle_class'] !== 'all') {
            $query->where('vehicle_class', $filters['vehicle_class']);
        }

        if (! empty($filters['lane_id']) && $filters['lane_id'] !== 'all') {
            $query->where('lane_id', $filters['lane_id']);
        }

        return $query->latest('transacted_at')->paginate($perPage);
    }

    /**
     * Get Shift Audits.
     */
    public function getShiftAudits(int $perPage = 15): LengthAwarePaginator
    {
        return OmTollShiftAudit::with(['auditor', 'shiftSupervisor', 'exemptions'])
            ->latest('shift_date')
            ->paginate($perPage);
    }

    /**
     * Get Exemptions.
     */
    public function getExemptions(int $perPage = 15): LengthAwarePaginator
    {
        return OmTollExemption::latest('passed_at')->paginate($perPage);
    }

    /**
     * Get Toll KPI summary.
     */
    public function getTollSummary(): array
    {
        $todayRevenue = OmTollRecord::whereDate('transacted_at', Carbon::today())->sum('amount');
        $totalTransactions = OmTollRecord::whereDate('transacted_at', Carbon::today())->count();

        $etcCount = OmTollRecord::whereDate('transacted_at', Carbon::today())->where('payment_method', 'etc')->count();
        $etcRatio = $totalTransactions > 0 ? round(($etcCount / $totalTransactions) * 100, 1) : 0.0;

        $unresolvedAudits = OmTollShiftAudit::where('audit_status', 'discrepancy_flagged')->count();

        return [
            'total_revenue_today' => $todayRevenue,
            'total_transactions_today' => $totalTransactions,
            'etc_percentage' => $etcRatio,
            'cash_percentage' => round(100 - $etcRatio, 1),
            'discrepancy_audits_count' => $unresolvedAudits,
        ];
    }

    /**
     * Record a new Shift Reconciliation Audit.
     */
    public function recordShiftAudit(array $data, string $auditorId): OmTollShiftAudit
    {
        $auditCode = 'TOLL-AUDIT-'.date('Ymd').'-'.strtoupper(substr($data['shift_type'] ?? 'M', 0, 1)).rand(10, 99);

        $sysTotal = (float) ($data['system_calculated_total'] ?? 0);
        $cashTotal = (float) ($data['cash_declared_by_collectors'] ?? 0);
        $etcTotal = (float) ($data['etc_automatic_revenue'] ?? 0);
        $posTotal = (float) ($data['pos_card_mfs_revenue'] ?? 0);
        $declaredTotal = $cashTotal + $etcTotal + $posTotal;
        $variance = $declaredTotal - $sysTotal;

        $status = abs($variance) < 100 ? 'verified_matched' : 'discrepancy_flagged';

        return OmTollShiftAudit::create([
            'audit_code' => $auditCode,
            'plaza_name' => $data['plaza_name'] ?? 'Main Toll Plaza (Ch 0+000)',
            'shift_date' => $data['shift_date'] ?? now()->toDateString(),
            'shift_type' => $data['shift_type'] ?? 'morning',
            'auditor_id' => $auditorId,
            'shift_supervisor_id' => $data['shift_supervisor_id'] ?? null,
            'system_calculated_total' => $sysTotal,
            'cash_declared_by_collectors' => $cashTotal,
            'etc_automatic_revenue' => $etcTotal,
            'pos_card_mfs_revenue' => $posTotal,
            'variance_amount' => $variance,
            'total_vehicle_transactions' => (int) ($data['total_vehicle_transactions'] ?? 0),
            'avc_physical_axle_count' => (int) ($data['avc_physical_axle_count'] ?? 0),
            'exempted_vehicle_count' => (int) ($data['exempted_vehicle_count'] ?? 0),
            'evasion_violation_count' => (int) ($data['evasion_violation_count'] ?? 0),
            'bank_deposit_reference' => $data['bank_deposit_reference'] ?? null,
            'bank_deposit_amount' => (float) ($data['bank_deposit_amount'] ?? $cashTotal),
            'audit_status' => $status,
            'auditor_notes' => $data['auditor_notes'] ?? null,
        ]);
    }
}
