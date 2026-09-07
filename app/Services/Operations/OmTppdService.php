<?php

namespace App\Services\Operations;

use App\Models\OmTppdClaim;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class OmTppdService
{
    // Standard contract BOQ rates for damaged expressway components (in BDT)
    public const BOQ_RATES = [
        'guardrail_w_beam_meter' => ['name' => 'W-Beam Guardrail (per meter)', 'rate' => 4500, 'unit' => 'm'],
        'guardrail_post' => ['name' => 'Steel C-Post with Base Plate', 'rate' => 6200, 'unit' => 'pcs'],
        'spacer_block' => ['name' => 'Recycled Polymer Spacer Block', 'rate' => 1200, 'unit' => 'pcs'],
        'terminal_end_section' => ['name' => 'Fishtail / Sloped End Terminal', 'rate' => 18500, 'unit' => 'set'],
        'crash_cushion_module' => ['name' => 'Attenuator Crash Cushion Module', 'rate' => 85000, 'unit' => 'unit'],
        'traffic_sign_board' => ['name' => 'Retroreflective Directional Sign Board', 'rate' => 22000, 'unit' => 'sqm'],
        'light_pole_galvanized' => ['name' => '12m Octagonal Lighting Pole', 'rate' => 55000, 'unit' => 'pole'],
        'delineator_post' => ['name' => 'Flexible Delineator Post', 'rate' => 1500, 'unit' => 'pcs'],
        'traffic_control_labor' => ['name' => 'Emergency Traffic Management Crew', 'rate' => 12000, 'unit' => 'day'],
    ];

    public function getClaims(array $filters = [], int $perPage = 15): LengthAwarePaginator|array
    {
        if (! Schema::hasTable('om_tppd_claims')) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        $query = OmTppdClaim::with('creator')->latest('incident_date');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('claim_number', 'like', "%{$s}%")
                    ->orWhere('vehicle_registration_number', 'like', "%{$s}%")
                    ->orWhere('police_fir_number', 'like', "%{$s}%")
                    ->orWhere('insurance_company', 'like', "%{$s}%");
            });
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function getClaimStats(): array
    {
        if (! Schema::hasTable('om_tppd_claims')) {
            return [
                'total_claims' => 0,
                'total_claimed_bdt' => 0,
                'total_recovered_bdt' => 0,
                'recovery_rate' => 0,
                'active_claims' => 0,
            ];
        }

        $totalClaimed = (float) OmTppdClaim::sum('claimed_amount');
        $totalRecovered = (float) OmTppdClaim::sum('recovered_amount');
        $recoveryRate = $totalClaimed > 0 ? round(($totalRecovered / $totalClaimed) * 100, 1) : 0;
        $activeClaims = OmTppdClaim::whereIn('status', ['drafted', 'submitted_police', 'submitted_insurance'])->count();

        return [
            'total_claims' => OmTppdClaim::count(),
            'total_claimed_bdt' => $totalClaimed,
            'total_recovered_bdt' => $totalRecovered,
            'recovery_rate' => $recoveryRate,
            'active_claims' => $activeClaims,
            'boq_reference' => self::BOQ_RATES,
        ];
    }

    public function saveClaim(array $data, ?int $userId, ?int $id = null): OmTppdClaim
    {
        // Auto-calculate repair cost based on BOQ components if provided
        $calculatedCost = 0;
        if (! empty($data['damaged_components']) && is_array($data['damaged_components'])) {
            foreach ($data['damaged_components'] as $comp) {
                $qty = (float) ($comp['quantity'] ?? 0);
                $rate = (float) ($comp['rate'] ?? (self::BOQ_RATES[$comp['item_key'] ?? '']['rate'] ?? 0));
                $calculatedCost += ($qty * $rate);
            }
        }

        if ($calculatedCost > 0 && empty($data['estimated_repair_cost'])) {
            $data['estimated_repair_cost'] = $calculatedCost;
        }

        if (empty($data['claimed_amount']) && ! empty($data['estimated_repair_cost'])) {
            $data['claimed_amount'] = $data['estimated_repair_cost'];
        }

        if ($id) {
            $claim = OmTppdClaim::findOrFail($id);
            $claim->update($data);
            return $claim;
        }

        $data['claim_number'] = 'TPPD-'.now()->format('Y').'-'.str_pad((string) (OmTppdClaim::count() + 1), 3, '0', STR_PAD_LEFT);
        $data['created_by_user_id'] = $userId;

        return OmTppdClaim::create($data);
    }

    public function updateStatus(OmTppdClaim $claim, string $status, ?float $recoveredAmount = null, ?string $notes = null): OmTppdClaim
    {
        $updateData = ['status' => $status];
        if ($recoveredAmount !== null) {
            $updateData['recovered_amount'] = $recoveredAmount;
            if ($status === 'settled') {
                $updateData['recovery_date'] = now()->toDateString();
            }
        }
        if ($notes !== null) {
            $updateData['notes'] = $notes;
        }

        $claim->update($updateData);
        return $claim;
    }
}
