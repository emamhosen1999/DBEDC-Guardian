<?php

namespace App\Services\Operations;

use App\Models\OmAiDetection;
use App\Models\OmWorkOrder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OmAiDistressService
{
    public function getDetections(array $filters = [], int $perPage = 15): LengthAwarePaginator|array
    {
        if (! Schema::hasTable('om_ai_detections')) {
            return $this->getMockDetections($filters);
        }

        try {
            $query = OmAiDetection::query()->latest('id');

            if (! empty($filters['status']) && $filters['status'] !== 'all') {
                $query->where('status', $filters['status']);
            }

            if (! empty($filters['distress_type']) && $filters['distress_type'] !== 'all') {
                $query->where('distress_type', $filters['distress_type']);
            }

            if (! empty($filters['severity']) && $filters['severity'] !== 'all') {
                $query->where('severity', $filters['severity']);
            }

            if (! empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('detection_code', 'like', "%{$search}%")
                        ->orWhere('chainage', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%");
                });
            }

            return $query->paginate($perPage)->withQueryString();
        } catch (\Throwable $e) {
            return (new OmAiDetection)->newQuery()->paginate($perPage);
        }
    }

    public function getAiStats(): array
    {
        if (! Schema::hasTable('om_ai_detections')) {
            return [
                'total_detections' => 0,
                'pending_review' => 0,
                'approved_work_orders' => 0,
                'false_positive_rejected' => 0,
                'mean_confidence_score' => 0.0,
                'high_severity_count' => 0,
            ];
        }

        try {
            $avgConfidence = OmAiDetection::avg('confidence_score');
            return [
                'total_detections' => OmAiDetection::count(),
                'pending_review' => OmAiDetection::where('status', 'pending_review')->count(),
                'approved_work_orders' => OmAiDetection::where('status', 'approved_work_order')->count(),
                'false_positive_rejected' => OmAiDetection::where('status', 'rejected_false_positive')->count(),
                'mean_confidence_score' => $avgConfidence !== null ? round($avgConfidence * 100, 1) : 0.0,
                'high_severity_count' => OmAiDetection::whereIn('severity', ['high', 'critical'])->where('status', 'pending_review')->count(),
            ];
        } catch (\Throwable $e) {
            return [
                'total_detections' => 0,
                'pending_review' => 0,
                'approved_work_orders' => 0,
                'false_positive_rejected' => 0,
                'mean_confidence_score' => 0.0,
                'high_severity_count' => 0,
            ];
        }
    }

    public function ingestBatch(array $detections, ?int $patrolShiftId = null): int
    {
        if (! Schema::hasTable('om_ai_detections')) {
            return count($detections);
        }

        $count = 0;
        foreach ($detections as $d) {
            OmAiDetection::create([
                'detection_code' => 'AID-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                'distress_type' => $d['distress_type'] ?? 'pothole',
                'confidence_score' => $d['confidence_score'] ?? 0.85,
                'chainage' => $d['chainage'] ?? 'KM 00+000',
                'direction' => $d['direction'] ?? 'northbound',
                'latitude' => $d['latitude'] ?? null,
                'longitude' => $d['longitude'] ?? null,
                'estimated_area_sqm' => $d['estimated_area_sqm'] ?? 0.5,
                'severity' => $d['severity'] ?? 'medium',
                'image_path' => $d['image_path'] ?? null,
                'bounding_box' => $d['bounding_box'] ?? null,
                'patrol_shift_id' => $patrolShiftId,
                'status' => 'pending_review',
                'notes' => $d['notes'] ?? 'Auto-detected via Mobile AI Dashcam edge inference',
            ]);
            $count++;
        }

        return $count;
    }

    public function batchConvertToWorkOrder(array $detectionIds, array $woData, ?string $userId = null): ?OmWorkOrder
    {
        if (! Schema::hasTable('om_work_orders') || ! Schema::hasTable('om_ai_detections')) {
            return null;
        }

        $wo = OmWorkOrder::create([
            'work_order_number' => 'WO-AI-'.now()->format('Ymd').'-'.strtoupper(Str::random(4)),
            'title' => $woData['title'] ?? 'Batch AI Road Repair: Potholes & Cracks',
            'work_type' => $woData['work_type'] ?? 'corrective',
            'priority' => $woData['priority'] ?? 'high',
            'status' => 'draft',
            'chainage' => $woData['chainage'] ?? 'KM 14+000 - KM 16+000',
            'description' => $woData['description'] ?? 'Automated batch Work Order converted from Edge-AI dashcam detections.',
            'assigned_to' => $userId,
        ]);

        OmAiDetection::whereIn('id', $detectionIds)->update([
            'status' => 'approved_work_order',
            'work_order_id' => $wo->id,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
        ]);

        return $wo;
    }

    public function rejectDetection(int $id, ?string $reason = null, ?string $userId = null): bool
    {
        if (! Schema::hasTable('om_ai_detections')) {
            return true;
        }

        $detection = OmAiDetection::find($id);
        if (! $detection) return false;

        $detection->update([
            'status' => 'rejected_false_positive',
            'notes' => $reason ? "Rejected: {$reason}" : 'Flagged as false positive by engineering review.',
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
        ]);

        return true;
    }
}
