<?php

namespace App\Services\Operations;

use App\Models\OmAsset;
use App\Models\OmAssetConditionSurvey;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OmAssetService
{
    /**
     * Get paginated assets with optional filters.
     */
    public function getAssets(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = OmAsset::query()->withCount(['defects', 'workOrders']);

        if (! empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('operational_status', $filters['status']);
        }

        if (! empty($filters['condition']) && $filters['condition'] !== 'all') {
            $query->where('condition_grade', $filters['condition']);
        }

        if (! empty($filters['direction']) && $filters['direction'] !== 'all') {
            $query->where('direction', $filters['direction']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('asset_code', 'like', "%{$search}%")
                    ->orWhere('start_chainage', 'like', "%{$search}%")
                    ->orWhere('location_description', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Get summary KPI statistics for assets.
     */
    public function getAssetStats(): array
    {
        $total = OmAsset::count();
        $active = OmAsset::where('operational_status', 'active')->count();
        $underMaintenance = OmAsset::where('operational_status', 'under_maintenance')->count();
        $critical = OmAsset::where('condition_grade', 'critical')->orWhere('condition_grade', 'poor')->count();
        $rawAverageScore = OmAsset::avg('condition_score');
        $avgScore = $rawAverageScore === null ? null : round((float) $rawAverageScore, 1);
        $totalValuation = OmAsset::sum('purchase_cost');

        return [
            'total_assets' => $total,
            'active_assets' => $active,
            'under_maintenance' => $underMaintenance,
            'critical_attention' => $critical,
            'avg_condition_pci' => $avgScore,
            'total_asset_valuation' => $totalValuation,
        ];
    }

    /**
     * Store new asset.
     */
    public function createAsset(array $data): OmAsset
    {
        if (empty($data['asset_code'])) {
            $prefix = strtoupper(substr($data['category'] ?? 'AST', 0, 4));
            $data['asset_code'] = 'AST-'.$prefix.'-'.rand(1000, 9999);
        }

        return OmAsset::create($data);
    }

    /**
     * Update asset details.
     */
    public function updateAsset(OmAsset $asset, array $data): OmAsset
    {
        $asset->update($data);

        return $asset->fresh();
    }

    /**
     * Record a new condition survey for an asset.
     */
    public function recordConditionSurvey(OmAsset $asset, array $surveyData): OmAssetConditionSurvey
    {
        return DB::transaction(function () use ($asset, $surveyData) {
            $survey = OmAssetConditionSurvey::create(array_merge($surveyData, [
                'asset_id' => $asset->id,
            ]));

            // Update asset condition score and last inspected time
            $asset->update([
                'condition_score' => $survey->condition_score,
                'condition_grade' => $survey->condition_grade,
                'last_inspected_at' => now(),
            ]);

            return $survey;
        });
    }
}
