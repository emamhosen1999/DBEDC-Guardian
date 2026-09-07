<?php

namespace App\Services\Operations;

use App\Models\OmDefect;
use App\Models\OmInspection;
use App\Models\OmInspectionTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class OmInspectionService
{
    /**
     * Get all active inspection templates.
     */
    public function getTemplates(array $filters = []): Collection
    {
        $query = OmInspectionTemplate::query()->where('is_active', true);

        if (! empty($filters['asset_category']) && $filters['asset_category'] !== 'all') {
            $query->where('asset_category', $filters['asset_category']);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Create an inspection template.
     */
    public function createTemplate(array $data, ?int $userId): OmInspectionTemplate
    {
        $code = 'INSP-'.strtoupper(substr($data['asset_category'] ?? 'GEN', 0, 4)).'-'.str_pad(
            (OmInspectionTemplate::count() + 1), 2, '0', STR_PAD_LEFT
        );

        return OmInspectionTemplate::create([
            'template_code' => $code,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'asset_category' => $data['asset_category'] ?? 'general',
            'checklist_sections' => $data['checklist_sections'],
            'max_score' => $data['max_score'] ?? 100,
            'pass_threshold' => $data['pass_threshold'] ?? 70,
            'auto_create_defect_on_fail' => $data['auto_create_defect_on_fail'] ?? true,
            'photo_required' => $data['photo_required'] ?? true,
            'is_active' => true,
            'created_by' => $userId,
        ]);
    }

    /**
     * Get paginated inspections with filters.
     */
    public function getInspections(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = OmInspection::query()->with(['template', 'asset', 'inspector', 'reviewer']);

        if (! empty($filters['result']) && $filters['result'] !== 'all') {
            $query->where('result', $filters['result']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['asset_id'])) {
            $query->where('asset_id', $filters['asset_id']);
        }

        if (! empty($filters['template_id'])) {
            $query->where('template_id', $filters['template_id']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('inspection_number', 'like', "%{$search}%")
                    ->orWhere('overall_notes', 'like', "%{$search}%")
                    ->orWhere('chainage', 'like', "%{$search}%");
            });
        }

        return $query->latest('inspection_date')->paginate($perPage);
    }

    /**
     * Inspection dashboard stats.
     */
    public function getInspectionStats(): array
    {
        $total = OmInspection::count();
        $thisMonth = OmInspection::where('inspection_date', '>=', now()->startOfMonth())->count();
        $passRate = $total > 0
            ? round(OmInspection::where('result', 'pass')->count() / $total * 100, 1)
            : 0;
        $failCount = OmInspection::where('result', 'fail')->where('status', '!=', 'closed')->count();
        $criticalCount = OmInspection::where('result', 'critical')->where('status', '!=', 'closed')->count();
        $pendingReview = OmInspection::where('status', 'submitted')->count();
        $avgScore = OmInspection::avg('total_score');

        return [
            'total_inspections' => $total,
            'inspections_this_month' => $thisMonth,
            'pass_rate' => $passRate,
            'open_failures' => $failCount,
            'critical_findings' => $criticalCount,
            'pending_review' => $pendingReview,
            'average_score' => $avgScore !== null ? round((float) $avgScore, 1) : 0,
        ];
    }

    /**
     * Submit a completed inspection from field.
     */
    public function submitInspection(array $data, ?int $inspectorId): OmInspection
    {
        $number = 'INS-'.date('Y').'-'.str_pad(
            (OmInspection::whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT
        );

        // Calculate score from checklist responses
        $totalScore = $this->calculateScore($data['checklist_responses'] ?? [], $data['template_id'] ?? null);
        $result = $this->determineResult($totalScore, $data['template_id'] ?? null);

        $inspection = OmInspection::create([
            'inspection_number' => $number,
            'template_id' => $data['template_id'] ?? null,
            'asset_id' => $data['asset_id'] ?? null,
            'preventive_schedule_id' => $data['preventive_schedule_id'] ?? null,
            'inspection_date' => $data['inspection_date'] ?? now()->toDateString(),
            'inspector_id' => $inspectorId,
            'chainage' => $data['chainage'] ?? null,
            'direction' => $data['direction'] ?? 'northbound',
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'checklist_responses' => $data['checklist_responses'] ?? [],
            'total_score' => $totalScore,
            'result' => $result,
            'overall_notes' => $data['overall_notes'] ?? null,
            'photo_paths' => $data['photo_paths'] ?? null,
            'status' => 'submitted',
        ]);

        // Auto-create defect if inspection failed and template says so
        if (in_array($result, ['fail', 'critical'])) {
            $template = $data['template_id'] ? OmInspectionTemplate::find($data['template_id']) : null;
            if (! $template || $template->auto_create_defect_on_fail) {
                $this->autoCreateDefect($inspection);
            }

            try {
                $managers = \App\Models\User::permission('om.maintenance.manage')->get();
                if ($managers->isNotEmpty()) {
                    \Illuminate\Support\Facades\Notification::send($managers, new \App\Notifications\OmAlertNotification(
                        "Inspection Alert: {$inspection->inspection_number}",
                        "Asset audit at {$inspection->chainage} scored {$inspection->total_score}/100 ({$inspection->result}). Auto-defect generated.",
                        'critical_inspection',
                        $inspection->inspection_number,
                        '/om/inspections'
                    ));
                }
            } catch (\Throwable) {
                // Fail-safe
            }
        }

        return $inspection->fresh(['template', 'asset', 'inspector']);
    }

    /**
     * Review and close an inspection.
     */
    public function reviewInspection(OmInspection $inspection, string $reviewerId, ?string $notes): OmInspection
    {
        $inspection->update([
            'status' => 'reviewed',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'overall_notes' => $notes ? ($inspection->overall_notes."\n\nReview: ".$notes) : $inspection->overall_notes,
        ]);

        return $inspection->fresh();
    }

    private function calculateScore(array $responses, ?int $templateId): int
    {
        if (empty($responses)) {
            return 0;
        }

        $template = $templateId ? OmInspectionTemplate::find($templateId) : null;
        $maxScore = $template?->max_score ?? 100;

        // Simple scoring: count pass/fail items
        $totalItems = 0;
        $passedItems = 0;

        foreach ($responses as $section) {
            if (! empty($section['items']) && is_array($section['items'])) {
                foreach ($section['items'] as $item) {
                    $totalItems++;
                    $value = $item['value'] ?? null;
                    if ($value === 'pass' || $value === true || (is_numeric($value) && $value >= 70)) {
                        $passedItems++;
                    }
                }
            }
        }

        return $totalItems > 0 ? (int) round(($passedItems / $totalItems) * $maxScore) : $maxScore;
    }

    private function determineResult(int $score, ?int $templateId): string
    {
        $template = $templateId ? OmInspectionTemplate::find($templateId) : null;
        $passThreshold = $template?->pass_threshold ?? 70;

        if ($score >= $passThreshold) {
            return 'pass';
        }
        if ($score >= $passThreshold * 0.5) {
            return 'needs_attention';
        }
        if ($score >= $passThreshold * 0.25) {
            return 'fail';
        }

        return 'critical';
    }

    private function autoCreateDefect(OmInspection $inspection): void
    {
        $defectNumber = 'DEF-'.date('Y').'-'.str_pad(
            (OmDefect::whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT
        );

        OmDefect::create([
            'defect_number' => $defectNumber,
            'asset_id' => $inspection->asset_id,
            'title' => "[Inspection] {$inspection->inspection_number}: Score {$inspection->total_score} — Auto-generated",
            'distress_type' => 'other',
            'chainage' => $inspection->chainage ?? 'Unknown',
            'direction' => $inspection->direction ?? 'northbound',
            'severity' => $inspection->result === 'critical' ? 'critical' : 'high',
            'sla_hours' => $inspection->result === 'critical' ? 4 : 24,
            'sla_due_at' => now()->addHours($inspection->result === 'critical' ? 4 : 24),
            'status' => 'reported',
            'reported_by' => $inspection->inspector_id,
            'description' => "Auto-generated from failed inspection {$inspection->inspection_number}.\n\nNotes: {$inspection->overall_notes}",
            'latitude' => $inspection->latitude,
            'longitude' => $inspection->longitude,
        ]);
    }
}
