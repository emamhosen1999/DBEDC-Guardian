<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which daily works a viewer may see or act on: ONE rule, used by the policy (one work) and by every list, summary,
 * export and print (a query), so a number on a report always agrees with what the viewer can open.
 *
 *  - Super Administrator and Administrator: everything.
 *  - Department Manager (with a department): works whose in-charge or assignee is in the department.
 *  - Supervision Engineer: works they are in charge of, and those of the manager they report to.
 *  - Quality Control Inspector (and Asst.): their manager's works, else works assigned to them.
 *  - Employee: the in-charge's own works if they are a section in-charge; else their manager's works; else their own.
 *  - anyone else: own (in-charge or assigned), plus the works of the manager they report to.
 * The permission to act (view, update, status.update ...) is checked separately, by the policy.
 */
class DailyWorkVisibility
{
    public function isAdmin(User $user): bool
    {
        return $user->hasRole('Super Administrator') || $user->hasRole('Administrator');
    }

    public function isDepartmentManager(User $user): bool
    {
        return $user->hasRole('Department Manager') && $user->department_id !== null;
    }

    /** @var array<string, bool> per request, so listing many works does not repeat the question */
    private array $sectionIncharge = [];

    /** @var array<string, int|null> */
    private array $departmentOf = [];

    private function isSectionIncharge(string $uid): bool
    {
        return $this->sectionIncharge[$uid] ??= Jurisdiction::sections()->where('incharge', $uid)->exists();
    }

    private function designation(User $user): ?string
    {
        if (! $user->relationLoaded('designation')) {
            $user->load('designation:id,title');
        }

        return $user->designation?->title;
    }

    private function uid(User $user): string
    {
        return (string) ($user->employee_id ?? $user->getKey());
    }

    /** Restrict a daily works query to what the viewer may see. */
    public function apply(Builder $query, User $user): Builder
    {
        $uid = $this->uid($user);
        $manager = $user->report_to ? (string) $user->report_to : null;
        $designation = $this->designation($user);

        if ($this->isAdmin($user)) {
            return $query;
        }
        if ($this->isDepartmentManager($user)) {
            return $query->where(function ($q) use ($user): void {
                $q->whereHas('inchargeUser', fn ($uq) => $uq->where('department_id', $user->department_id))
                    ->orWhereHas('assignedUser', fn ($uq) => $uq->where('department_id', $user->department_id));
            });
        }
        if ($designation === 'Supervision Engineer') {
            return $query->where(function ($q) use ($uid, $manager): void {
                $q->where('incharge', $uid);
                if ($manager) {
                    $q->orWhere('incharge', $manager);
                }
            });
        }
        if (in_array($designation, ['Quality Control Inspector', 'Asst. Quality Control Inspector'], true)) {
            return $manager ? $query->where('incharge', $manager) : $query->where('assigned', $uid);
        }
        if ($user->hasRole('Employee')) {
            if ($this->isSectionIncharge($uid)) {
                return $query->where('incharge', $uid);
            }

            return $query->where('incharge', $manager ?? $uid);
        }
        if ($manager) {
            return $query->where(fn ($q) => $q->where('incharge', $uid)->orWhere('assigned', $uid)->orWhere('incharge', $manager));
        }

        return $query->where(fn ($q) => $q->where('incharge', $uid)->orWhere('assigned', $uid));
    }

    /**
     * May the viewer act on this one work? $reach is the widest relationship the action allows outside the
     * administrator / department / employee rules: 'view' (in-charge, assignee or the manager's in-charge) or
     * 'assigned' (in-charge or assignee) or 'incharge' (in-charge only).
     */
    public function allows(User $user, DailyWork $work, string $reach = 'view'): bool
    {
        $uid = $this->uid($user);
        $incharge = (string) $work->incharge === $uid;
        $assigned = (string) $work->assigned === $uid;

        if ($this->isAdmin($user)) {
            return true;
        }
        if ($this->isDepartmentManager($user)) {
            return $this->departmentWork($user, $work);
        }
        if ($user->hasRole('Employee')) {
            if ($this->isSectionIncharge($uid)) {
                return $incharge;
            }

            return (string) $work->incharge === ($user->report_to ? (string) $user->report_to : $uid);
        }

        return match ($reach) {
            'incharge' => $incharge,
            'assigned' => $incharge || $assigned,
            default => $incharge || $assigned || ($user->report_to && (string) $work->incharge === (string) $user->report_to),
        };
    }

    /** A work belongs to a department when its in-charge or its assignee is a member. */
    public function departmentWork(User $manager, DailyWork $work): bool
    {
        $dept = (int) $manager->department_id;
        foreach (array_values(array_filter([(string) $work->incharge, (string) $work->assigned])) as $id) {
            if (! array_key_exists($id, $this->departmentOf)) {
                $this->departmentOf[$id] = User::query()->whereKey($id)->value('department_id');
            }
            if ($this->departmentOf[$id] !== null && (int) $this->departmentOf[$id] === $dept) {
                return true;
            }
        }

        return false;
    }
}
