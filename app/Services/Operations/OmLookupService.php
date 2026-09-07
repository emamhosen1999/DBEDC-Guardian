<?php

namespace App\Services\Operations;

use App\Models\OmLookup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class OmLookupService
{
    const CACHE_KEY = 'om_active_lookups';

    /**
     * Get all active lookups grouped by type
     */
    public function getGroupedLookups(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            $this->ensureDefaultLookupsSeeded();

            $lookups = OmLookup::where('is_active', true)
                ->orderBy('sort_order', 'asc')
                ->orderBy('label', 'asc')
                ->get();

            return [
                'defect_categories' => $lookups->where('type', 'defect_category')->values()->all(),
                'severities' => $lookups->where('type', 'severity')->values()->all(),
                'carriageway_locations' => $lookups->where('type', 'carriageway_location')->values()->all(),
                'responsible_parties' => $lookups->where('type', 'responsible_party')->values()->all(),
                'work_order_categories' => $lookups->where('type', 'work_order_category')->values()->all(),
            ];
        });
    }

    /**
     * Get lookups with pagination and filters for Admin management UI
     */
    public function getLookupsForAdmin(?string $type = null, ?string $search = null)
    {
        $this->ensureDefaultLookupsSeeded();

        $query = OmLookup::with(['creator:id,name', 'updater:id,name'])
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($type && $type !== 'all') {
            $query->where('type', $type);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('label', 'like', "%{$search}%")
                  ->orWhere('key', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->paginate(25);
    }

    /**
     * Create or update lookup
     */
    public function saveLookup(array $data, ?int $userId = null, ?int $id = null): OmLookup
    {
        $data['updated_by'] = $userId;
        if (! $id) {
            $data['created_by'] = $userId;
            $lookup = OmLookup::create($data);
        } else {
            $lookup = OmLookup::findOrFail($id);
            $lookup->update($data);
        }

        $this->clearCache();
        return $lookup;
    }

    /**
     * Delete lookup
     */
    public function deleteLookup(int $id): bool
    {
        $lookup = OmLookup::findOrFail($id);
        $result = $lookup->delete();
        $this->clearCache();
        return $result;
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Auto-seed initial comprehensive lookups combining ASTM standards and DBEDC Concession Excel categories
     */
    public function ensureDefaultLookupsSeeded(): void
    {
        if (OmLookup::count() > 0) {
            return;
        }

        $defaults = [
            // ── Defect Categories & Distress Types ──
            ['type' => 'defect_category', 'key' => 'pothole', 'label' => 'Pothole (Pavement)', 'sla_hours' => 4, 'badge_color' => 'red', 'sort_order' => 1],
            ['type' => 'defect_category', 'key' => 'isolation_barrier', 'label' => 'Isolation Barrier Damage/Cut', 'sla_hours' => 24, 'badge_color' => 'orange', 'sort_order' => 2],
            ['type' => 'defect_category', 'key' => 'guardrail_crash_damage', 'label' => 'Guardrail / Crash Barrier Damage', 'sla_hours' => 24, 'badge_color' => 'red', 'sort_order' => 3],
            ['type' => 'defect_category', 'key' => 'anti_glare_panel', 'label' => 'Anti-Glare Panel Board Damage/Missing', 'sla_hours' => 48, 'badge_color' => 'amber', 'sort_order' => 4],
            ['type' => 'defect_category', 'key' => 'median_delineator', 'label' => 'Central Median Delineator Missing', 'sla_hours' => 48, 'badge_color' => 'amber', 'sort_order' => 5],
            ['type' => 'defect_category', 'key' => 'drainage_clogged', 'label' => 'Drainage Pipe Clogged with Sand/Grass', 'sla_hours' => 12, 'badge_color' => 'blue', 'sort_order' => 6],
            ['type' => 'defect_category', 'key' => 'alligator_cracking', 'label' => 'Alligator / Fatigue Cracking', 'sla_hours' => 48, 'badge_color' => 'amber', 'sort_order' => 7],
            ['type' => 'defect_category', 'key' => 'rutting_depression', 'label' => 'Rutting & Surface Depression', 'sla_hours' => 48, 'badge_color' => 'amber', 'sort_order' => 8],
            ['type' => 'defect_category', 'key' => 'road_marking_faded', 'label' => 'Faded / Damaged Road Marking', 'sla_hours' => 72, 'badge_color' => 'cyan', 'sort_order' => 9],
            ['type' => 'defect_category', 'key' => 'vegetation_overgrowth', 'label' => 'Bush / Plant / Grass Sightline Overgrowth', 'sla_hours' => 24, 'badge_color' => 'green', 'sort_order' => 10],
            ['type' => 'defect_category', 'key' => 'lighting_outage', 'label' => 'High-Mast / Street Lighting Outage', 'sla_hours' => 24, 'badge_color' => 'indigo', 'sort_order' => 11],
            ['type' => 'defect_category', 'key' => 'debris_illegal_dumping', 'label' => 'Debris / Obstruction on Roadway', 'sla_hours' => 1, 'badge_color' => 'red', 'sort_order' => 12],
            ['type' => 'defect_category', 'key' => 'fence_breached', 'label' => 'Right-of-Way (ROW) Fence Cut/Breached', 'sla_hours' => 12, 'badge_color' => 'orange', 'sort_order' => 13],
            ['type' => 'defect_category', 'key' => 'cable_theft_cut', 'label' => 'Electrical / Telecomm Cable Cut', 'sla_hours' => 12, 'badge_color' => 'purple', 'sort_order' => 14],
            ['type' => 'defect_category', 'key' => 'other', 'label' => 'Other Highway Distress', 'sla_hours' => 48, 'badge_color' => 'gray', 'sort_order' => 15],

            // ── Severities ──
            ['type' => 'severity', 'key' => 'minor', 'label' => 'Minor', 'sla_hours' => 72, 'badge_color' => 'blue', 'sort_order' => 1],
            ['type' => 'severity', 'key' => 'moderate', 'label' => 'Moderate', 'sla_hours' => 48, 'badge_color' => 'amber', 'sort_order' => 2],
            ['type' => 'severity', 'key' => 'major', 'label' => 'Major', 'sla_hours' => 24, 'badge_color' => 'orange', 'sort_order' => 3],
            ['type' => 'severity', 'key' => 'critical', 'label' => 'Critical - Safety Hazard', 'sla_hours' => 4, 'badge_color' => 'red', 'sort_order' => 4],

            // ── Carriageway / Section Locations ──
            ['type' => 'carriageway_location', 'key' => 'main_carriageway_left', 'label' => 'Main Carriageway - Left (L)', 'badge_color' => 'blue', 'sort_order' => 1],
            ['type' => 'carriageway_location', 'key' => 'main_carriageway_right', 'label' => 'Main Carriageway - Right (R)', 'badge_color' => 'blue', 'sort_order' => 2],
            ['type' => 'carriageway_location', 'key' => 'median', 'label' => 'Central Median', 'badge_color' => 'green', 'sort_order' => 3],
            ['type' => 'carriageway_location', 'key' => 'guardrail_barrier', 'label' => 'Guardrail / Outer Barrier', 'badge_color' => 'orange', 'sort_order' => 4],
            ['type' => 'carriageway_location', 'key' => 'service_road_left', 'label' => 'Service Road - Left', 'badge_color' => 'indigo', 'sort_order' => 5],
            ['type' => 'carriageway_location', 'key' => 'service_road_right', 'label' => 'Service Road - Right', 'badge_color' => 'indigo', 'sort_order' => 6],
            ['type' => 'carriageway_location', 'key' => 'toll_plaza_ramp', 'label' => 'Toll Plaza / Interchange Ramp', 'badge_color' => 'purple', 'sort_order' => 7],

            // ── Responsible Parties / Contractors ──
            ['type' => 'responsible_party', 'key' => 'om_contractor', 'label' => 'O&M Routine Contractor', 'badge_color' => 'blue', 'sort_order' => 1],
            ['type' => 'responsible_party', 'key' => 'civil_works_contractor', 'label' => 'Civil Works Construction EPC', 'badge_color' => 'indigo', 'sort_order' => 2],
            ['type' => 'responsible_party', 'key' => 'signage_safety_vendor', 'label' => 'Signage & Safety Barrier Vendor', 'badge_color' => 'orange', 'sort_order' => 3],
            ['type' => 'responsible_party', 'key' => 'electrical_lighting_vendor', 'label' => 'Lighting & Electrical Maintenance Vendor', 'badge_color' => 'cyan', 'sort_order' => 4],
            ['type' => 'responsible_party', 'key' => 'dbedc_inhouse_crew', 'label' => 'DBEDC In-House QC & Incident Team', 'badge_color' => 'green', 'sort_order' => 5],

            // ── Work Order Categories ──
            ['type' => 'work_order_category', 'key' => 'pavement', 'label' => 'Pavement & Asphalt Repair', 'badge_color' => 'amber', 'sort_order' => 1],
            ['type' => 'work_order_category', 'key' => 'barrier_isolation', 'label' => 'Barrier & Isolation Reinstallation', 'badge_color' => 'orange', 'sort_order' => 2],
            ['type' => 'work_order_category', 'key' => 'drainage_culverts', 'label' => 'Drainage & Culvert Desilting', 'badge_color' => 'blue', 'sort_order' => 3],
            ['type' => 'work_order_category', 'key' => 'electrical_lighting', 'label' => 'Electrical & High-Mast Lighting', 'badge_color' => 'cyan', 'sort_order' => 4],
            ['type' => 'work_order_category', 'key' => 'signage_markings', 'label' => 'Road Signage & Thermoplastic Markings', 'badge_color' => 'indigo', 'sort_order' => 5],
            ['type' => 'work_order_category', 'key' => 'vegetation_landscaping', 'label' => 'Vegetation Clearance & Mowing', 'badge_color' => 'green', 'sort_order' => 6],
        ];

        foreach ($defaults as $d) {
            OmLookup::create($d);
        }
    }
}
