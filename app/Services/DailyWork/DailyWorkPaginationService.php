<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\User;
use App\Traits\DailyWorkFilterable;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DailyWorkPaginationService
{
    use DailyWorkFilterable;

    private DailyWorkSearchService $searchService;

    public function __construct()
    {
        $this->searchService = app(DailyWorkSearchService::class);
    }

    /**
     * Get paginated daily works based on user role and filters
     * Returns CursorPaginator for very large datasets (>= 2000 perPage)
     * Returns LengthAwarePaginator for mobile mode (500-1999 perPage)
     * Returns standard paginated results for normal web usage (< 500 perPage)
     */
    public function getPaginatedDailyWorks(Request $request)
    {
        $startTime = microtime(true);

        // Load user with designation once to avoid redundant queries
        $user = User::with('designation')->find(Auth::id());
        $userDesignationTitle = $user->designation?->title;

        $perPage = (int) $request->get('perPage', 30);
        $page = $request->get('search') != '' ? 1 : $request->get('page', 1);
        $search = $request->get('search');
        $statusFilter = $request->get('status');
        $inChargeFilter = $request->input('inCharge');
        $jurisdictionFilter = $request->input('jurisdiction');
        $startDate = $request->get('startDate');
        $endDate = $request->get('endDate');

        // Log the request parameters for debugging
        Log::info('DailyWork pagination request', [
            'perPage' => $perPage,
            'perPage_type' => gettype($perPage),
            'page' => $page,
            'is_mobile_mode' => $perPage >= 1000,
            'date_range' => [$startDate, $endDate],
            'user_id' => $user->id,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'inChargeFilter' => $inChargeFilter,
            'jurisdictionFilter' => $jurisdictionFilter,
        ]);

        $query = $this->buildBaseQuery($user, $userDesignationTitle);
        $query = $this->applyFilters($query, $search, $statusFilter, $inChargeFilter, $jurisdictionFilter, $startDate, $endDate);

        // Cursor-based pagination for extremely large datasets (perPage >= 2000)
        if ($perPage >= 2000) {
            Log::info('Cursor pagination mode: using cursor-based pagination for large dataset', [
                'perPage' => $perPage,
                'startDate' => $startDate,
                'endDate' => $endDate,
            ]);

            $cursorResults = $query->orderBy('date', 'desc')
                ->orderBy('id', 'desc')
                ->cursorPaginate($perPage);

            $endTime = microtime(true);
            Log::info('DailyWork cursor pagination query completed', [
                'execution_time' => round(($endTime - $startTime) * 1000, 2).'ms',
                'records_count' => $cursorResults->count(),
                'has_more_pages' => $cursorResults->hasMorePages(),
            ]);

            return $cursorResults;
        }

        // Mobile mode detection: if perPage is large (500-1999), return all data without pagination
        if ($perPage >= 500) {
            Log::info('Mobile mode: fetching all data without pagination', [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'statusFilter' => $statusFilter,
                'inChargeFilter' => $inChargeFilter,
                'search' => $search,
                'perPage' => $perPage,
            ]);

            // Dynamic limit based on date range to prevent memory issues
            $maxRecords = $this->calculateSafeLimit($startDate, $endDate);

            $allData = $query->orderBy('date', 'desc')
                ->orderBy('id', 'desc') // Secondary sort for consistent ordering
                ->limit($maxRecords)
                ->get();

            $endTime = microtime(true);
            Log::info('DailyWork mobile query completed', [
                'execution_time' => round(($endTime - $startTime) * 1000, 2).'ms',
                'records_count' => $allData->count(),
                'max_limit_applied' => $maxRecords,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'memory_usage' => round(memory_get_peak_usage(true) / 1024 / 1024, 2).'MB',
            ]);

            // Create a manual paginator with all data on page 1
            return new LengthAwarePaginator(
                $allData,
                $allData->count(),
                $allData->count() ?: 1, // perPage = total count to show all on one page
                1,
                ['path' => $request->url(), 'pageName' => 'page']
            );
        }

        $result = $query->orderBy('date', 'desc')->paginate($perPage, ['*'], 'page', $page);

        $endTime = microtime(true);
        Log::info('DailyWork desktop query completed', [
            'execution_time' => round(($endTime - $startTime) * 1000, 2).'ms',
            'records_count' => $result->count(),
            'total_records' => $result->total(),
        ]);

        return $result;
    }

    /**
     * Calculate safe limit for mobile queries based on date range
     */
    private function calculateSafeLimit(?string $startDate, ?string $endDate): int
    {
        // Base limit for safety
        $baseLimit = 1000;

        if (!$startDate && !$endDate) {
            // No date filter - be conservative
            return $baseLimit;
        }

        try {
            $start = $startDate ? \Carbon\Carbon::parse($startDate) : now()->subMonths(1);
            $end = $endDate ? \Carbon\Carbon::parse($endDate) : now();

            $daysDiff = $start->diffInDays($end);

            // Scale limit based on date range
            // More days = higher limit, but capped for safety
            $scaledLimit = min($baseLimit + ($daysDiff * 10), 3000);

            return (int) $scaledLimit;

        } catch (\Exception $e) {
            // Fallback to base limit if date parsing fails
            Log::warning('Failed to calculate safe limit from dates, using base limit', [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'error' => $e->getMessage(),
            ]);

            return $baseLimit;
        }
    }

    /**
     * Get user role for response based on designation
     */
    public function getAllDailyWorks(Request $request): array
    {
        // Load user with designation once to avoid redundant queries
        $user = User::with('designation')->find(Auth::id());
        $userDesignationTitle = $user->designation?->title;

        $search = $request->get('search');
        $statusFilter = $request->get('status');
        $inChargeFilter = $request->input('inCharge');
        $jurisdictionFilter = $request->input('jurisdiction');
        $startDate = $request->get('startDate');
        $endDate = $request->get('endDate');

        $query = $this->buildBaseQuery($user, $userDesignationTitle);
        $query = $this->applyFilters($query, $search, $statusFilter, $inChargeFilter, $jurisdictionFilter, $startDate, $endDate);

        $dailyWorks = $query->orderBy('date', 'desc')->get();

        return [
            'dailyWorks' => $dailyWorks,
            'role' => $this->getUserRole($user, $userDesignationTitle),
            'userInfo' => $this->getUserInfo($user, $userDesignationTitle),
        ];
    }

    /**
     * Build base query based on user designation with optimized eager loading
     */
    private function buildBaseQuery(User $user, ?string $userDesignationTitle = null)
    {
        // Use optimized eager loading to prevent N+1 queries
        // Include active objections count for RFI warning indicators
        $baseQuery = DailyWork::with([
            'inchargeUser:id,name', // Load user names for display
            'assignedUser:id,name',  // Load assigned user names
        ])->withCount(['activeObjections']);

        if ($userDesignationTitle === 'Supervision Engineer') {
            return $baseQuery->where('incharge', $user->id);
        }

        if (in_array($userDesignationTitle, ['Quality Control Inspector', 'Asst. Quality Control Inspector'])) {
            return $baseQuery->where('assigned', $user->id);
        }

        // Super Administrator and Administrator get all data
        if ($user->hasRoleCached(['Super Administrator', 'Administrator'])) {
            return $baseQuery;
        }

        return $baseQuery;
    }

    /**
     * Apply filters to the query with optimized date filtering
     */
    private function applyFilters($query, ?string $search, ?string $statusFilter, $inChargeFilter, $jurisdictionFilter, ?string $startDate, ?string $endDate)
    {
        $normalizedIncharge = $this->normalizeIdFilter($inChargeFilter);
        $normalizedJurisdictions = $this->normalizeIdFilter($jurisdictionFilter);

        // Apply date range filter FIRST for better performance (most selective)
        if ($startDate && $endDate) {
            // For single date (mobile), use exact match instead of range
            if ($startDate === $endDate) {
                $query->whereDate('date', $startDate);
            } else {
                $query->whereBetween('date', [$startDate, $endDate]);
            }
        } elseif ($startDate) {
            $query->whereDate('date', '>=', $startDate);
        }

        // Apply status filter if provided
        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        if (! empty($normalizedIncharge)) {
            $query->whereIn('incharge', $normalizedIncharge);
        } elseif (! empty($normalizedJurisdictions)) {
            $jurisdictionIncharges = Jurisdiction::whereIn('id', $normalizedJurisdictions)
                ->pluck('incharge')
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            if (! empty($jurisdictionIncharges)) {
                $query->whereIn('incharge', $jurisdictionIncharges);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Apply optimized search using the search service
        if ($search) {
            $query = $this->searchService->applySearch($query, $search);
        }

        return $query;
    }

    /**
     * Get user role for response based on designation
     */
    private function getUserRole(User $user, ?string $userDesignationTitle = null): string
    {
        if ($userDesignationTitle === 'Supervision Engineer') {
            return 'Supervision Engineer';
        }

        if ($userDesignationTitle === 'Quality Control Inspector') {
            return 'Quality Control Inspector';
        }

        if ($userDesignationTitle === 'Asst. Quality Control Inspector') {
            return 'Asst. Quality Control Inspector';
        }

        if ($user->hasRole('Super Administrator')) {
            return 'Super Administrator';
        }

        if ($user->hasRole('Administrator')) {
            return 'Administrator';
        }

        return 'Unknown';
    }

    /**
     * Get additional user info for response based on designation
     */
    private function getUserInfo(User $user, ?string $userDesignationTitle = null): array
    {
        if ($userDesignationTitle === 'Supervision Engineer') {
            return [
                'allInCharges' => [],
                'juniors' => User::where('report_to', $user->id)->get(),
            ];
        }

        if ($user->hasRole('Super Administrator') || $user->hasRole('Administrator')) {
            return [
                'allInCharges' => User::whereHas('designation', function ($q) {
                    $q->where('title', 'Supervision Engineer');
                })->get(),
                'juniors' => [],
            ];
        }

        return [];
    }
}
