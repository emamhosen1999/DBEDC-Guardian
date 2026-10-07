<?php

namespace App\Services\Access;

use App\Models\HRM\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may this actor choose as an employee's reporting manager (supervisory-organization model)?
 *
 * Every ACTIVE employee except the employee and anyone below them in the reporting tree (the loop the
 * ReportingManager rule also refuses), narrowed by the actor's data scope:
 *   - a global actor sees everyone;
 *   - anyone else sees the people in their DepartmentScope PLUS the head of every department
 *     (departments.manager_id - org-chart heads are directory information).
 * The picker payload is directory data only: id, name, designation and department.
 */
class ReportingManagerCandidates
{
    public function __construct(private DepartmentScope $scope) {}

    /** @return Builder<User> */
    public function query(User $actor, ?string $employeeId = null): Builder
    {
        $query = User::query()->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true));

        if ($employeeId !== null && $employeeId !== '') {
            $below = array_merge([$employeeId], $this->scope->descendantIds($employeeId));
            $query->whereNotIn('employee_id', $below);
        }

        if (! $this->scope->isGlobal($actor)) {
            $headIds = Department::query()->whereNotNull('manager_id')->pluck('manager_id')->map(fn ($id) => (string) $id)->all();
            $query->where(function (Builder $scoped) use ($actor, $headIds): void {
                $this->scope->applyToUsers($scoped, $actor);
                $scoped->orWhereIn('employee_id', $headIds);
            });
        }

        return $query;
    }

    /** May the actor pick $candidateId as manager of $employeeId (null while creating the employee)? */
    public function allows(User $actor, ?string $employeeId, string|int|null $candidateId): bool
    {
        if ($candidateId === null || $candidateId === '') {
            return true;
        }

        return $this->query($actor, $employeeId)->whereKey((string) $candidateId)->exists();
    }

    /**
     * @return array<int, array{id: string, name: string, designation: ?string, department_id: ?int, department: ?string}>
     */
    public function list(User $actor, ?string $employeeId = null): array
    {
        return $this->query($actor, $employeeId)
            ->with(['designation:id,title', 'department:id,name'])
            ->select(['employee_id', 'name', 'designation_id', 'department_id'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => (string) $u->employee_id,
                'name' => (string) $u->name,
                'designation' => $u->designation?->title,
                'department_id' => $u->department_id !== null ? (int) $u->department_id : null,
                'department' => $u->department?->name,
            ])
            ->values()
            ->all();
    }
}
