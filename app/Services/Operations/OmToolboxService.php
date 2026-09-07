<?php

namespace App\Services\Operations;

use App\Models\OmToolboxTalk;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OmToolboxService
{
    /**
     * Get paginated toolbox talks.
     */
    public function getToolboxTalks(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = OmToolboxTalk::query()->with(['supervisor', 'workOrder']);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('talk_code', 'like', "%{$search}%")
                    ->orWhere('topic', 'like', "%{$search}%")
                    ->orWhere('chainage', 'like', "%{$search}%");
            });
        }

        return $query->latest('talk_date')->paginate($perPage);
    }

    /**
     * Create a toolbox talk briefing.
     */
    public function createToolboxTalk(array $data, ?int $supervisorId): OmToolboxTalk
    {
        $code = 'TBT-'.date('Y').'-'.str_pad(
            (OmToolboxTalk::whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT
        );

        return OmToolboxTalk::create(array_merge($data, [
            'talk_code' => $code,
            'supervisor_id' => $supervisorId,
            'talk_date' => $data['talk_date'] ?? now()->toDateString(),
            'attendee_count' => count($data['attendees'] ?? [1]),
        ]));
    }
}
