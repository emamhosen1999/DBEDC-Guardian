<?php

namespace App\Console\Commands;

use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Approvals\ApprovalRouting;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Read-only audit of the organization structure: reporting lines, department heads and the escalation
 * approver. Fails (non-zero) only on reporting loops or self-references - the data errors that make a
 * request unroutable; everything else is reported as a warning.
 */
class OrgHealth extends Command
{
    protected $signature = 'org:health';

    protected $description = 'Report reporting-line and department-head problems (read-only)';

    public function handle(ApprovalRouting $routing): int
    {
        $users = User::withTrashed()->get(['employee_id', 'name', 'report_to', 'department_id', 'is_active', 'deleted_at'])
            ->keyBy(fn (User $u) => (string) $u->employee_id);
        $isLive = fn (?User $u): bool => $u !== null && $u->deleted_at === null && ($u->is_active === null || (bool) $u->is_active);
        $active = $users->filter(fn (User $u) => $isLive($u));
        $label = fn (?User $u, $id = null): string => $u ? "{$u->employee_id} {$u->name}" : (string) $id;

        $selfRefs = $active->filter(fn (User $u) => $u->report_to !== null && (string) $u->report_to === (string) $u->employee_id);
        $loops = $this->loops($active);

        $noManager = $active->filter(fn (User $u) => ($u->report_to === null || $u->report_to === '') && ! $selfRefs->has((string) $u->employee_id));
        $topIds = $this->topOfOrganization($noManager);
        $top = $noManager->filter(fn (User $u) => in_array((string) $u->employee_id, $topIds, true));
        $without = $noManager->reject(fn (User $u) => in_array((string) $u->employee_id, $topIds, true));

        $inactiveManagers = $active->filter(fn (User $u) => $u->report_to !== null && $u->report_to !== ''
            && ! $selfRefs->has((string) $u->employee_id) && ! $isLive($users->get((string) $u->report_to)));

        $headless = Department::query()->where('is_active', true)->get()
            ->filter(fn (Department $d) => ! $d->manager_id || ! $isLive($users->get((string) $d->manager_id)));

        $this->section('Top of the organization (no manager, expected)', $top->map(fn ($u) => $label($u))->values()->all());
        $this->section('Employees without a manager', $without->map(fn ($u) => $label($u))->values()->all());
        $this->section('Managers who are inactive or offboarded', $inactiveManagers->map(fn ($u) => $label($u).' -> '.$label($users->get((string) $u->report_to), $u->report_to))->values()->all());
        $this->section('Departments without an active head', $headless->map(fn ($d) => "{$d->id} {$d->name}".($d->manager_id ? " (head {$d->manager_id} inactive or missing)" : ''))->values()->all());
        $this->section('Self-references (report_to = self)', $selfRefs->map(fn ($u) => $label($u))->values()->all(), error: true);
        $this->section('Reporting loops', $loops, error: true);

        $hasApprover = $routing->hasEscalationApprover();
        $hasApprover
            ? $this->info('Escalation approver: present (HR Manager or configured approver).')
            : $this->warn('Escalation approver: NONE - requests without a manager fall back to Super Administrators.');

        return $selfRefs->isNotEmpty() || $loops !== [] ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, string> one "a -> b -> a" line per distinct cycle */
    private function loops($active): array
    {
        $found = [];
        foreach ($active as $start => $user) {
            $path = [(string) $start];
            $next = $user->report_to !== null && $user->report_to !== '' ? (string) $user->report_to : null;
            while ($next !== null && $next !== (string) $start) {
                if (in_array($next, $path, true) || ! $active->has($next)) {
                    $next = null;
                    break;
                }
                $path[] = $next;
                $next = $active[$next]->report_to !== null && $active[$next]->report_to !== '' ? (string) $active[$next]->report_to : null;
            }
            if ($next === (string) $start && count($path) > 1) {
                $members = $path;
                sort($members);
                $found[implode(',', $members)] = implode(' -> ', [...$path, $start]);
            }
        }

        return array_values($found);
    }

    /** Top of the organization: managerless Super Administrators, else the managerless root of the largest tree. */
    private function topOfOrganization($noManager): array
    {
        $roleExists = Role::query()->where('name', ApprovalRouting::LAST_RESORT_ROLE)->exists();
        if ($roleExists) {
            $supers = User::role(ApprovalRouting::LAST_RESORT_ROLE)->pluck('employee_id')->map(fn ($id) => (string) $id)->all();
            $ids = $noManager->keys()->map(fn ($k) => (string) $k)->intersect($supers)->values()->all();
            if ($ids !== []) {
                return $ids;
            }
        }

        $sizes = $noManager->map(fn (User $u) => User::query()->where('report_to', $u->employee_id)->count());

        return $sizes->isEmpty() || $sizes->max() === 0 ? [] : [(string) $sizes->sortDesc()->keys()->first()];
    }

    /** @param  array<int, string>  $lines */
    private function section(string $title, array $lines, bool $error = false): void
    {
        $this->line('');
        $this->line("<options=bold>{$title}: ".count($lines).'</>');
        foreach ($lines as $line) {
            $error ? $this->error("  {$line}") : $this->line("  {$line}");
        }
    }
}
