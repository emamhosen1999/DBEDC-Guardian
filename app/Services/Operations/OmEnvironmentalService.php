<?php

namespace App\Services\Operations;

use App\Models\OmEnvironmentalLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OmEnvironmentalService
{
    /**
     * Get paginated environmental logs with filters.
     */
    public function getLogs(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = OmEnvironmentalLog::query()->with('reporter');

        if (! empty($filters['monitoring_type']) && $filters['monitoring_type'] !== 'all') {
            $query->where('monitoring_type', $filters['monitoring_type']);
        }

        if (! empty($filters['compliance_status']) && $filters['compliance_status'] !== 'all') {
            $query->where('compliance_status', $filters['compliance_status']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('log_code', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('chainage', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->latest('log_date')->paginate($perPage);
    }

    /**
     * Get summary environmental statistics.
     */
    public function getEnvironmentalStats(): array
    {
        $totalLogs = OmEnvironmentalLog::count();
        $thisMonth = OmEnvironmentalLog::where('log_date', '>=', now()->startOfMonth())->count();
        $violations = OmEnvironmentalLog::where('compliance_status', 'critical_violation')->count();
        $minorExceedances = OmEnvironmentalLog::where('compliance_status', 'minor_exceedance')->count();
        $spillsReported = OmEnvironmentalLog::where('monitoring_type', 'spill_hazardous')->count();
        $wasteDumping = OmEnvironmentalLog::where('monitoring_type', 'illegal_waste_dumping')->count();

        return [
            'total_logs' => $totalLogs,
            'logs_this_month' => $thisMonth,
            'critical_violations' => $violations,
            'minor_exceedances' => $minorExceedances,
            'spills_reported' => $spillsReported,
            'illegal_waste_dumping' => $wasteDumping,
        ];
    }

    /**
     * Record a new environmental event or monitoring reading.
     */
    public function createLog(array $data, ?int $userId): OmEnvironmentalLog
    {
        $code = 'ENV-'.date('Y').'-'.str_pad(
            (OmEnvironmentalLog::whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT
        );

        return OmEnvironmentalLog::create(array_merge($data, [
            'log_code' => $code,
            'reported_by' => $userId,
        ]));
    }
}
