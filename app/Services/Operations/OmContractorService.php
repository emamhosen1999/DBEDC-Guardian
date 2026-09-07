<?php

namespace App\Services\Operations;

use App\Models\OmContractor;
use App\Models\OmWorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class OmContractorService
{
    /**
     * Get paginated contractors with filters.
     */
    public function getContractors(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = OmContractor::query();

        if (! empty($filters['trade_specialty']) && $filters['trade_specialty'] !== 'all') {
            $query->where('trade_specialty', $filters['trade_specialty']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('trade_specialty', 'like', "%{$search}%");
            });
        }

        return $query->orderByDesc('quality_score')->paginate($perPage);
    }

    /**
     * Get summary scorecard metrics across all contractors.
     */
    public function getContractorStats(): array
    {
        $total = OmContractor::count();
        $active = OmContractor::where('status', 'active')->count();
        $avgQuality = OmContractor::where('status', 'active')->avg('quality_score');
        $avgSla = OmContractor::where('status', 'active')->avg('sla_compliance_rate');
        $totalCompleted = OmContractor::sum('jobs_completed_count');
        $totalDelayed = OmContractor::sum('jobs_delayed_count');

        return [
            'total_contractors' => $total,
            'active_contractors' => $active,
            'average_quality_score' => $avgQuality !== null ? round((float) $avgQuality, 1) : 0,
            'average_sla_compliance' => $avgSla !== null ? round((float) $avgSla, 1) : 0,
            'total_jobs_completed' => (int) $totalCompleted,
            'total_jobs_delayed' => (int) $totalDelayed,
        ];
    }

    /**
     * Create or update contractor.
     */
    public function saveContractor(array $data, ?int $id = null): OmContractor
    {
        if ($id) {
            $contractor = OmContractor::findOrFail($id);
            $contractor->update($data);
            return $contractor->fresh();
        }

        return OmContractor::create($data);
    }
}
