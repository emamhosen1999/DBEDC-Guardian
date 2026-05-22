<?php

namespace App\Services\DailyWork;

use App\Repositories\DailyWorkRepository;

class DailyWorkQueryService
{
    /**
     * @var DailyWorkRepository
     */
    private DailyWorkRepository $dailyWorkRepository;

    /**
     * Create a new service instance
     *
     * @param DailyWorkRepository $dailyWorkRepository
     */
    public function __construct(DailyWorkRepository $dailyWorkRepository)
    {
        $this->dailyWorkRepository = $dailyWorkRepository;
    }

    /**
     * Get daily works with pagination
     *
     * @param array $filters
     * @return array
     */
    public function getDailyWorks(array $filters = []): array
    {
        $perPage = $filters['per_page'] ?? 25;
        $page = $filters['page'] ?? 1;

        $paginator = $this->dailyWorkRepository->paginate($perPage, $filters);

        return [
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * Get daily work details
     *
     * @param int $dailyWorkId
     * @return array
     */
    public function getDailyWorkDetails(int $dailyWorkId): array
    {
        $dailyWork = $this->dailyWorkRepository->findOrFail($dailyWorkId);
        
        return [
            'id' => $dailyWork->id,
            'user_id' => $dailyWork->user_id,
            'incharge_id' => $dailyWork->incharge_id,
            'assigned_id' => $dailyWork->assigned_id,
            'date' => $dailyWork->date->format('Y-m-d'),
            'type' => $dailyWork->type,
            'chainage_from' => $dailyWork->chainage_from,
            'chainage_to' => $dailyWork->chainage_to,
            'status' => $dailyWork->status,
            'description' => $dailyWork->description,
            'remarks' => $dailyWork->remarks,
            'inspection_result' => $dailyWork->inspection_result,
            'rfi_response_status' => $dailyWork->rfi_response_status,
            'project_id' => $dailyWork->project_id,
            'created_at' => $dailyWork->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $dailyWork->updated_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get selectable dates for daily works
     *
     * @param array $filters
     * @return array
     */
    public function getSelectableDates(array $filters = []): array
    {
        $dates = $this->dailyWorkRepository->getSelectableDates($filters);
        
        return $dates->map(function ($date) {
            return [
                'date' => $date->format('Y-m-d'),
                'formatted' => $date->format('F d, Y'),
            ];
        })->toArray();
    }

    /**
     * Get daily works statistics
     *
     * @param array $filters
     * @return array
     */
    public function getStatistics(array $filters = []): array
    {
        return $this->dailyWorkRepository->getStatistics($filters);
    }

    /**
     * Update daily work status
     *
     * @param int $dailyWorkId
     * @param string $status
     * @return array
     */
    public function updateStatus(int $dailyWorkId, string $status): array
    {
        $dailyWork = $this->dailyWorkRepository->updateStatus($dailyWorkId, $status);
        
        return [
            'id' => $dailyWork->id,
            'status' => $dailyWork->status,
            'message' => 'Status updated successfully',
        ];
    }

    /**
     * Update daily work incharge
     *
     * @param int $dailyWorkId
     * @param int|null $inchargeId
     * @return array
     */
    public function updateIncharge(int $dailyWorkId, ?int $inchargeId): array
    {
        $dailyWork = $this->dailyWorkRepository->updateIncharge($dailyWorkId, $inchargeId);
        
        return [
            'id' => $dailyWork->id,
            'incharge_id' => $dailyWork->incharge_id,
            'message' => 'Incharge updated successfully',
        ];
    }

    /**
     * Update daily work assigned user
     *
     * @param int $dailyWorkId
     * @param int|null $assignedId
     * @return array
     */
    public function updateAssigned(int $dailyWorkId, ?int $assignedId): array
    {
        $dailyWork = $this->dailyWorkRepository->updateAssigned($dailyWorkId, $assignedId);
        
        return [
            'id' => $dailyWork->id,
            'assigned_id' => $dailyWork->assigned_id,
            'message' => 'Assigned user updated successfully',
        ];
    }
}
