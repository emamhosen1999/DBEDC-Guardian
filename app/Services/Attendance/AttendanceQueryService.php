<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\Leave;
use App\Repositories\AttendanceRepository;
use App\Services\Attendance\Contracts\ScheduleResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class AttendanceQueryService
{
    private AttendanceRepository $attendanceRepository;

    /**
     * Create a new service instance
     */
    public function __construct(AttendanceRepository $attendanceRepository)
    {
        $this->attendanceRepository = $attendanceRepository;
    }

    /**
     * Get today's attendance for a user
     */
    public function getTodayAttendance(int $userId): array
    {
        $attendances = $this->attendanceRepository->getTodayAttendance($userId);
        if (! $attendances->contains(fn ($a) => $a->punchout === null)) {
            if ($overnight = $this->openOvernightSession($userId)) {
                $attendances = collect([$overnight])->concat($attendances);
            }
        }

        $totalProductionTime = 0;
        $punches = $attendances->map(function (Attendance $attendance) use (&$totalProductionTime) {
            $punchInTime = Carbon::parse($attendance->punchin);
            $punchOutTime = $attendance->punchout ? Carbon::parse($attendance->punchout) : Carbon::now();

            $duration = $punchInTime->diffInSeconds($punchOutTime);
            $totalProductionTime += $duration;

            return [
                'date' => $attendance->date->format('Y-m-d'),
                'punchin_time' => $attendance->punchin->format('H:i:s'),
                'punchin_location' => $attendance->punchin_location_array,
                'punchout_time' => $attendance->punchout?->format('H:i:s'),
                'punchout_location' => $attendance->punchout_location_array,
                'duration' => gmdate('H:i:s', $duration),
            ];
        })->values();

        // Check if user is on leave today
        $isUserOnLeave = false;
        if (Schema::hasTable('leaves')) {
            $isUserOnLeave = Leave::query()
                ->where('user_id', $userId)
                ->whereDate('from_date', '<=', Carbon::today())
                ->whereDate('to_date', '>=', Carbon::today())
                ->whereRaw('LOWER(status) = ?', ['approved'])
                ->exists();
        }

        return [
            'punches' => $punches,
            'total_production_time' => gmdate('H:i:s', $totalProductionTime),
            'isUserOnLeave' => $isUserOnLeave,
            'is_user_on_leave' => $isUserOnLeave,
        ];
    }

    /**
     * Get attendance history with pagination
     */
    public function getAttendanceHistory(int $userId, array $filters = []): array
    {
        if (($filters['scope'] ?? 'self') !== 'team') {
            $filters['user_id'] = $userId;
        } else {
            unset($filters['user_id']);
        }
        $filters['with'] = ['user.designation'];
        $perPage = $filters['per_page'] ?? 10;
        $page = $filters['page'] ?? 1;

        $isTeam = ($filters['scope'] ?? 'self') === 'team';

        $query = $this->attendanceRepository->query();
        $query = $this->attendanceRepository->applyFilters($query, $filters);

        // Remove ordering from query for counting/grouping to prevent SQL errors in some SQL modes/engines.
        $query->getQuery()->orders = null;

        if (!$isTeam) {
            // Self scope: group by date
            $paginator = $query->select('date')
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->paginate($perPage);

            $dates = collect($paginator->items())->pluck('date')->map(function ($d) {
                return Carbon::parse($d)->toDateString();
            })->all();

            if (empty($dates)) {
                $attendances = [];
            } else {
                $records = Attendance::query()
                    ->with(['user.designation'])
                    ->where('user_id', $userId)
                    ->whereIn('date', $dates)
                    ->orderBy('date', 'desc')
                    ->orderBy('punchin', 'asc')
                    ->get();

                $attendances = $records->groupBy(function ($r) {
                    return Carbon::parse($r->date)->toDateString();
                })->map(function ($dayRecords, $dateStr) {
                    return $this->formatGroupedAttendance($dayRecords, $dateStr);
                })->values()->all();
            }
        } else {
            // Team scope: group by user_id and date
            $paginator = $query->select('user_id', 'date')
                ->groupBy('user_id', 'date')
                ->orderBy('date', 'desc')
                ->orderBy('user_id', 'asc')
                ->paginate($perPage);

            $items = $paginator->items();
            if (empty($items)) {
                $attendances = [];
            } else {
                $recordsQuery = Attendance::query()->with(['user.designation']);
                $recordsQuery->where(function ($q) use ($items) {
                    foreach ($items as $item) {
                        $q->orWhere(function ($sub) use ($item) {
                            $dateStr = Carbon::parse($item->date)->toDateString();
                            $sub->where('user_id', $item->user_id)
                                ->where('date', $dateStr);
                        });
                    }
                });
                $records = $recordsQuery->orderBy('date', 'desc')
                    ->orderBy('user_id', 'asc')
                    ->orderBy('punchin', 'asc')
                    ->get();

                $attendances = $records->groupBy(function ($r) {
                    return $r->user_id . '_' . Carbon::parse($r->date)->toDateString();
                })->map(function ($userDayRecords) {
                    $first = $userDayRecords->first();
                    $dateStr = Carbon::parse($first->date)->toDateString();
                    return $this->formatGroupedAttendance($userDayRecords, $dateStr);
                })->values()->all();
            }
        }

        return [
            'attendances' => $attendances,
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
     * Return an open prior-day Attendance row if the resolved shift crosses midnight
     * and the punch-in is within 18 hours of now (bounded overnight rule for live display).
     * Returns null for day shifts or stale rows.
     */
    private function openOvernightSession(int $userId): ?Attendance
    {
        $now = Carbon::now();
        $prior = $this->attendanceRepository
            ->getUserAttendanceForDate($userId, $now->copy()->subDay())
            ->firstWhere('punchout', null);
        if (! $prior || ! $prior->punchin) {
            return null;
        }
        $in = Carbon::parse($prior->punchin);
        if ($in->diffInHours($now) > 18) {
            return null;
        }
        $shift = app(ScheduleResolver::class)->resolve($userId, $in);

        return $shift->crossesMidnight ? $prior : null;
    }

    /**
     * Format grouped attendance records for a single day/user
     */
    private function formatGroupedAttendance($records, string $dateStr): array
    {
        $firstRecord = $records->first();
        $user = $firstRecord?->user;
        $sortedPunches = $records->sortBy('punchin')->values();

        $totalWorkMinutes = 0;
        $completePunches = 0;
        $hasIncompletePunch = false;

        $punches = $sortedPunches->map(function (Attendance $record) use (&$totalWorkMinutes, &$completePunches, &$hasIncompletePunch) {
            $duration = null;
            if ($record->punchin && $record->punchout) {
                $punchIn = Carbon::parse($record->punchin);
                $punchOut = Carbon::parse($record->punchout);

                $minutes = $punchIn->diffInMinutes($punchOut);
                $totalWorkMinutes += $minutes;

                $diffSeconds = $punchIn->diffInSeconds($punchOut);
                $duration = gmdate('H:i:s', $diffSeconds);

                $completePunches++;
            } elseif ($record->punchin && ! $record->punchout) {
                $hasIncompletePunch = true;
            }

            return [
                'id' => $record->id,
                'date' => $record->date instanceof Carbon ? $record->date->toDateString() : (string) $record->date,
                'punch_in' => $record->punchin ? Carbon::parse($record->punchin)->format('H:i:s') : null,
                'punch_out' => $record->punchout ? Carbon::parse($record->punchout)->format('H:i:s') : null,
                'punchin_location' => $record->punchin_location_array,
                'punchout_location' => $record->punchout_location_array,
                'duration' => $duration,
            ];
        })->values();

        $firstPunch = $sortedPunches->first();
        $lastCompletePunch = $sortedPunches->whereNotNull('punchout')->last();

        return [
            'id' => $firstRecord?->id,
            'user_id' => (int) ($user?->id ?? 0),
            'user' => [
                'id' => (int) ($user?->id ?? 0),
                'name' => $user?->name,
                'employee_id' => $user?->employee_id,
                'phone' => $user?->phone,
                'profile_image' => $user?->profile_image,
                'profile_image_url' => $user?->profile_image_url,
                'designation' => $user?->designation?->title,
            ],
            'date' => $dateStr,
            'punchin_time' => $firstPunch?->punchin ? Carbon::parse($firstPunch->punchin)->format('H:i:s') : null,
            'punchin_id' => $firstPunch?->id,
            'punchout_time' => $lastCompletePunch?->punchout ? Carbon::parse($lastCompletePunch->punchout)->format('H:i:s') : null,
            'punchout_id' => $sortedPunches->last()?->id,
            'punchin_location' => $firstPunch?->punchin_location_array,
            'punchout_location' => $lastCompletePunch?->punchout_location_array,
            'total_work_minutes' => round($totalWorkMinutes, 2),
            'punch_count' => $records->count(),
            'complete_punches' => $completePunches,
            'has_incomplete_punch' => $hasIncompletePunch,
            'first_punch_date' => $firstRecord?->date instanceof Carbon ? $firstRecord->date->toDateString() : (string) $firstRecord?->date,
            'last_punch_date' => $sortedPunches->last()?->date instanceof Carbon ? $sortedPunches->last()->date->toDateString() : (string) $sortedPunches->last()?->date,
            'punches' => $punches,
        ];
    }

    /**
     * Get monthly attendance summary
     */
    public function getMonthlySummary(int $userId, int $month, int $year): array
    {
        $summary = $this->attendanceRepository->getAttendanceSummary(
            $userId,
            Carbon::create($year, $month, 1)->startOfMonth(),
            Carbon::create($year, $month, 1)->endOfMonth()
        );

        return $summary;
    }

    /**
     * Get present users for a specific date
     */
    public function getPresentUsersForDate(string $date, array $filters = []): array
    {
        $attendances = $this->attendanceRepository->getPresentUsersForDate($date, $filters);

        return $attendances->map(function ($attendance) {
            return [
                'id' => $attendance->id,
                'user_id' => $attendance->user_id,
                'user' => [
                    'id' => $attendance->user->id,
                    'name' => $attendance->user->name,
                    'employee_id' => $attendance->user->employee_id,
                ],
                'punchin_time' => $attendance->punchin->format('H:i:s'),
                'punchin_location' => $attendance->punchin_location_array,
                'punchout_time' => $attendance->punchout?->format('H:i:s'),
            ];
        })->toArray();
    }

    /**
     * Get absent users for a specific date
     */
    public function getAbsentUsersForDate(string $date, array $filters = []): array
    {
        $attendances = $this->attendanceRepository->getAbsentUsersForDate($date, $filters);

        return $attendances->map(function ($attendance) {
            return [
                'id' => $attendance->id,
                'user_id' => $attendance->user_id,
                'user' => [
                    'id' => $attendance->user->id,
                    'name' => $attendance->user->name,
                    'employee_id' => $attendance->user->employee_id,
                ],
            ];
        })->toArray();
    }

    /**
     * Get user locations for a specific date
     */
    public function getUserLocationsForDate(string $date, array $filters = []): array
    {
        $attendances = $this->attendanceRepository->all([
            'date' => $date,
            'with' => ['user.designation'],
        ]);

        return $attendances->filter(function ($attendance) {
            return $attendance->punchin_location_array !== null;
        })->map(function ($attendance) {
            return [
                'user_id' => $attendance->user_id,
                'user_name' => $attendance->user->name,
                'name' => $attendance->user->name,
                'profile_image_url' => $attendance->user->profile_image_url,
                'designation' => $attendance->user->designation?->title ?? 'Employee',
                'location' => $attendance->punchin_location_array,
                'punchin_location' => $attendance->punchin_location_array,
                'punchout_location' => $attendance->punchout_location_array,
                'punchin_time' => $attendance->punchin->format('H:i:s'),
                'punchout_time' => $attendance->punchout?->format('H:i:s'),
            ];
        })->values()->toArray();
    }

    /**
     * Get daily timesheet data
     */
    public function getDailyTimesheet(string $date, array $filters = []): array
    {
        $perPage = $filters['per_page'] ?? 25;
        $page = $filters['page'] ?? 1;

        $paginator = $this->attendanceRepository->paginate($perPage, array_merge($filters, ['date' => $date]));

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
}
