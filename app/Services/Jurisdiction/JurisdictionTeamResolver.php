<?php

namespace App\Services\Jurisdiction;

use App\Models\Jurisdiction;
use App\Models\JurisdictionMember;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Corridor\ChainageResolver;
use App\Services\Org\ReportingLineService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The team of a jurisdiction = its in-charge plus EVERYONE who reports to the in-charge, directly or indirectly (the
 * recursive reporting line, active employees only), plus the people explicitly assigned to the jurisdiction (deputies and
 * anyone outside that line). Built from ONE query of the active staff, assembled in memory, with a visited set and a depth cap:
 * a person whose line loops is shown once, with a data-quality warning. A person is never counted twice; someone both in the
 * line and assigned shows once, marked "assigned" as well.
 *
 * Each person carries designation, department, works in the period and the attendance status on the as-of date, all from real
 * data. Phone and e-mail are included only when the viewer may see them in the employee directory (employees.view and the
 * person inside the viewer's DepartmentScope); the print view asks for none.
 */
class JurisdictionTeamResolver
{
    public const MAX_DEPTH = 10;

    public function __construct(private readonly ReportingLineService $lines, private readonly DepartmentScope $scope) {}

    /**
     * @param  Collection<int, Jurisdiction>  $jurisdictions
     * @param  array<int, Collection<int, JurisdictionMember>>  $explicit  jurisdiction id => team rows valid on the date
     * @param  array<int, array<string, int>>  $works  jurisdiction id => employee id => works in the period
     * @return array<int, array{tree: array, kpis: array, warnings: int, note: ?string}>
     */
    public function resolve(Collection $jurisdictions, array $explicit, array $works, string $asOf, ?User $viewer, bool $withContacts = true): array
    {
        $today = ChainageResolver::today();
        $staff = User::query()->whereNull('deleted_at')->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->with(['designation:id,title', 'department:id,name'])
            ->get(['employee_id', 'name', 'report_to', 'department_id', 'designation_id', 'phone', 'email'])->keyBy(fn (User $u): string => (string) $u->employee_id);

        // reporting line on the date: today from users.report_to (authoritative), earlier from the recorded history
        $line = $asOf >= $today ? $staff->map(fn (User $u): ?string => $u->report_to !== null && $u->report_to !== '' ? (string) $u->report_to : null)->all() : $this->lines->mapAsOf($asOf);
        $children = [];
        foreach ($line as $employee => $boss) {
            if ($boss !== null && $boss !== (string) $employee && $staff->has((string) $employee)) {
                $children[$boss][] = (string) $employee;
            }
        }
        foreach ($children as &$ids) {
            usort($ids, fn (string $a, string $b): int => strcmp((string) $staff[$a]->name, (string) $staff[$b]->name));
        }
        unset($ids);

        $built = [];
        $allIds = [];
        foreach ($jurisdictions as $j) {
            $members = $explicit[$j->id] ?? collect();
            $inCharge = $members->firstWhere('role', JurisdictionMember::IN_CHARGE);
            $assigned = $members->where('role', '!=', JurisdictionMember::IN_CHARGE)->keyBy(fn (JurisdictionMember $m): string => (string) $m->employee_id);
            $built[$j->id] = $this->assemble($inCharge?->employee_id !== null ? (string) $inCharge->employee_id : null, $children, $staff, $assigned);
            foreach ($built[$j->id]['ids'] as $id) {
                $allIds[$id] = true;
            }
        }
        $ids = array_keys($allIds);
        $attendance = $this->attendance($ids, $asOf, $today);
        $photos = User::query()->whereIn('employee_id', $ids)->with('media')->get()->keyBy(fn (User $u): string => (string) $u->employee_id);
        $visible = $withContacts && $viewer !== null && $viewer->can('employees.view') ? $this->scope->visibleEmployeeIds($viewer) : false; // null = everyone
        $recordedSince = $this->lines->recordedSince();
        $note = $recordedSince !== null && $asOf < $recordedSince
            ? "Reporting lines are recorded from {$recordedSince}; for earlier dates the earliest known line is shown."
            : null;

        $out = [];
        foreach ($jurisdictions as $j) {
            $b = $built[$j->id];
            $nodeFor = function (array $n) use (&$nodeFor, $staff, $works, $j, $attendance, $photos, $visible): array {
                $u = $staff[$n['id']] ?? null;
                $canSee = $visible !== false && ($visible === null || in_array($n['id'], array_map('strval', (array) $visible), true));

                return [
                    'employee_id' => $n['id'], 'name' => $u?->name, 'designation' => $u?->designation?->title, 'department' => $u?->department?->name,
                    'photo' => ($photos[$n['id']] ?? null)?->profile_image_url, 'position' => $n['position'], 'member_id' => $n['member_id'], 'assigned_role' => $n['assigned_role'],
                    'role' => $n['assigned_role'] ?? ($n['position'] === 'in_charge' ? JurisdictionMember::IN_CHARGE : 'member'),
                    'works' => $works[$j->id][$n['id']] ?? 0, 'attendance' => $attendance[$n['id']] ?? ['status' => 'unknown', 'label' => 'No data'],
                    'contact' => $canSee && $u !== null ? ['phone' => $u->phone, 'email' => $u->email] : null,
                    'warnings' => $n['warnings'], 'children' => array_map($nodeFor, $n['children']),
                ];
            };
            $tree = array_map($nodeFor, $b['tree']);
            $out[$j->id] = ['tree' => $tree, 'kpis' => $this->kpis($b['ids'], $attendance, $works[$j->id] ?? []), 'warnings' => $b['warnings'], 'note' => $note];
        }

        return $out;
    }

    /**
     * @param  array<string, array<int, string>>  $children
     * @param  Collection<string, User>  $staff
     * @param  Collection<string, JurisdictionMember>  $assigned
     * @return array{tree: array, ids: array<int, string>, warnings: int}
     */
    private function assemble(?string $root, array $children, Collection $staff, Collection $assigned): array
    {
        $visited = [];
        $warnings = 0;
        $walk = function (string $id, string $position, int $depth) use (&$walk, &$visited, &$warnings, $children, $assigned): array {
            $visited[$id] = true;
            $node = ['id' => $id, 'position' => $position, 'assigned_role' => ($assigned[$id] ?? null)?->role, 'member_id' => ($assigned[$id] ?? null)?->id, 'warnings' => [], 'children' => []];
            foreach ($children[$id] ?? [] as $child) {
                if (isset($visited[$child])) {
                    // a loop (or a person reachable twice): shown once, flagged where it was first placed
                    $warnings++;

                    continue;
                }
                if ($depth + 1 > self::MAX_DEPTH) {
                    $warnings++;
                    $node['warnings'][] = 'Reporting line deeper than '.self::MAX_DEPTH.' levels is not shown';
                    break;
                }
                $node['children'][] = $walk($child, 'reporting', $depth + 1);
            }

            return $node;
        };

        $tree = [];
        if ($root !== null) {
            $rootNode = $walk($root, 'in_charge', 1);
            $tree[] = $rootNode;
        }
        // people assigned to the jurisdiction who are not in the reporting line sit under the in-charge
        $extra = [];
        foreach ($assigned as $id => $m) {
            if (! isset($visited[$id]) && $staff->has((string) $id)) {
                $visited[(string) $id] = true;
                $extra[] = ['id' => (string) $id, 'position' => 'assigned', 'assigned_role' => $m->role, 'member_id' => $m->id, 'warnings' => [], 'children' => []];
            }
        }
        if ($tree !== []) {
            $tree[0]['children'] = array_merge($tree[0]['children'], $extra);
        } else {
            $tree = $extra;
        }
        if ($warnings > 0 && $tree !== []) {
            $tree[0]['warnings'][] = $warnings.' reporting-line data problem'.($warnings > 1 ? 's' : '').' (a loop or a line deeper than '.self::MAX_DEPTH.' levels); each person is shown once';
        }

        return ['tree' => $tree, 'ids' => array_keys($visited), 'warnings' => $warnings];
    }

    /**
     * Attendance status on the date, from real data: approved leave, an attendance punch, or a roster entry without a punch.
     * present | on_leave | absent (rostered, no punch) | not_rostered.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array{status: string, label: string}>
     */
    private function attendance(array $ids, string $asOf, string $today): array
    {
        if ($ids === []) {
            return [];
        }
        $leave = DB::table('leaves')->whereIn('user_id', $ids)->whereRaw('LOWER(status) = ?', ['approved'])->where('from_date', '<=', $asOf)->where('to_date', '>=', $asOf)->pluck('user_id')->map(fn ($i): string => (string) $i)->flip();
        $punched = DB::table('attendances')->whereIn('user_id', $ids)->whereDate('date', $asOf)->whereNotNull('punchin')->pluck('user_id')->map(fn ($i): string => (string) $i)->flip();
        $rostered = DB::table('roster_days')->whereIn('user_id', $ids)->whereDate('date', $asOf)->pluck('user_id')->map(fn ($i): string => (string) $i)->flip();

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = match (true) {
                $leave->has($id) => ['status' => 'on_leave', 'label' => 'On leave'],
                $punched->has($id) => ['status' => 'present', 'label' => $asOf === $today ? 'Present today' : 'Present'],
                $rostered->has($id) => ['status' => 'absent', 'label' => $asOf === $today ? 'Not punched in' : 'Absent'],
                default => ['status' => 'not_rostered', 'label' => 'Not rostered'],
            };
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $ids
     * @param  array<string, array{status: string}>  $attendance
     * @param  array<string, int>  $works
     * @return array{headcount: int, present: int, on_leave: int, absent: int, not_rostered: int, works: int, works_per_person: ?float}
     */
    private function kpis(array $ids, array $attendance, array $works): array
    {
        $count = fn (string $s): int => count(array_filter($ids, fn (string $id): bool => ($attendance[$id]['status'] ?? null) === $s));
        $total = array_sum(array_map(fn (string $id): int => (int) ($works[$id] ?? 0), $ids));

        return [
            'headcount' => count($ids), 'present' => $count('present'), 'on_leave' => $count('on_leave'), 'absent' => $count('absent'), 'not_rostered' => $count('not_rostered'),
            'works' => $total, 'works_per_person' => count($ids) > 0 ? round($total / count($ids), 1) : null,
        ];
    }
}
