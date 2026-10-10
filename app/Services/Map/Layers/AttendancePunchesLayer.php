<?php

namespace App\Services\Map\Layers;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Attendance\AttendanceQueryService;
use App\Services\Corridor\CorridorGeometry;
use App\Services\Map\LayerResult;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;
use Illuminate\Support\Facades\Route;

/**
 * Punch-in / punch-out positions per employee for one day - the SAME source as the Daily Timesheet map
 * (AttendanceController::getUserLocationsForDate -> AttendanceQueryService::getUserLocationsForDate),
 * narrowed to the employees the viewer may see through DepartmentScope exactly as that action does.
 * A range shows its latest day. One marker per employee at the last known position; the full punch
 * detail (photos, times, places) travels in `person` for the popup, roster drawer and officer modal.
 */
final class AttendancePunchesLayer extends MapLayer
{
    public function __construct(private readonly AttendanceQueryService $attendance, private readonly DepartmentScope $scope) {}

    public function key(): string
    {
        return 'workforce.punches';
    }

    public function label(): string
    {
        return 'Attendance locations';
    }

    public function group(): string
    {
        return 'workforce';
    }

    public function geometry(): array
    {
        return [self::POINT];
    }

    public function permissions(): array
    {
        return ['attendance.view'];
    }

    public function tables(): array
    {
        return ['attendances'];
    }

    public function fields(): array
    {
        return ['status' => 'Status', 'designation' => 'Designation', 'punch_in' => 'Punch in', 'punch_out' => 'Punch out'];
    }

    public function scope(): string
    {
        return self::SCOPE_DEPARTMENT;
    }

    public function timeMode(): string
    {
        return self::TIME_PERIOD;
    }

    public function route(): ?string
    {
        return 'attendance.unified';
    }

    public function routeQuery(MapFilter $filter): array
    {
        return ['date' => $filter->to->toDateString()];
    }

    public function icon(): string
    {
        return 'person-badge';
    }

    public function source(): string
    {
        return 'Attendance punches (same source as the Daily Timesheet map), latest day of the range';
    }

    protected function collect(User $viewer, MapFilter $filter, CorridorGeometry $geometry, LayerResult $out): void
    {
        $date = $filter->to->toDateString();
        $rows = collect($this->attendance->getUserLocationsForDate($date));

        $visible = $this->scope->visibleEmployeeIds($viewer);
        if ($visible !== null) {
            $rows = $rows->filter(fn ($row) => in_array((string) ($row['employee_id'] ?? ''), $visible, true));
        }
        if ($filter->search !== null) {
            $needle = mb_strtolower($filter->search);
            $rows = $rows->filter(fn ($row) => str_contains(mb_strtolower(($row['name'] ?? '').' '.($row['employee_id'] ?? '').' '.($row['designation'] ?? '')), $needle));
        }

        $timesheet = Route::has('attendance.unified') && $viewer->can('attendance.view') ? route('attendance.unified', ['date' => $date]) : null;

        foreach ($rows as $row) {
            $in = $this->place($row['punchin_location'] ?? null);
            $outLoc = $this->place($row['punchout_location'] ?? null);
            $here = $outLoc ?? $in;
            if ($here === null) {
                $out->skip();

                continue;
            }
            $done = ($row['status'] ?? 'active') === 'completed';
            $out->add($this->feature('point', (string) $row['employee_id'], (string) $row['name'], [
                'status' => $done ? 'Done' : 'Active',
                'designation' => $row['designation'] ?? null,
                'punch_in' => $this->stamp($row['punchin_time'] ?? null, $in),
                'punch_out' => $this->stamp($row['punchout_time'] ?? null, $outLoc),
            ], $done ? 'info' : 'good', [
                'lat' => $here['lat'], 'lng' => $here['lng'],
                'person' => [
                    'employee_id' => (string) $row['employee_id'],
                    'name' => (string) $row['name'],
                    'designation' => $row['designation'] ?? null,
                    'department' => $row['department'] ?? null,
                    'photo' => $row['profile_image_url'] ?? null,
                    'status' => $done ? 'completed' : 'active',
                    'attendance_type' => $row['attendance_type']['name'] ?? null,
                    'requires_photo' => (bool) ($row['requires_photo'] ?? false),
                    'date' => $date,
                    'punch_in' => $in === null ? null : $in + ['time' => $row['punchin_time'] ?? null, 'photo' => $row['punchin_photo_url'] ?? null],
                    'punch_out' => $outLoc === null && empty($row['punchout_time']) ? null : ($outLoc ?? []) + ['time' => $row['punchout_time'] ?? null, 'photo' => $row['punchout_photo_url'] ?? null],
                    'cycles' => count($row['cycles'] ?? []),
                    'timesheet' => $timesheet,
                ],
            ]));
        }
    }

    /** @return array{lat: float, lng: float, address: ?string}|null */
    private function place(mixed $loc): ?array
    {
        if (is_string($loc)) {
            $loc = json_decode($loc, true);
        }
        if (! is_array($loc) || ! is_numeric($loc['lat'] ?? null) || ! is_numeric($loc['lng'] ?? null)) {
            return null;
        }

        return ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng'], 'address' => ($loc['address'] ?? '') !== '' ? (string) $loc['address'] : null];
    }

    private function stamp(?string $time, ?array $place): ?string
    {
        if ($time === null) {
            return null;
        }

        return substr($time, 0, 5).($place['address'] ?? null ? ' at '.$place['address'] : '');
    }
}
