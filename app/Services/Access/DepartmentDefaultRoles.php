<?php

namespace App\Services\Access;

use App\Models\HRM\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

/**
 * Department -> default functional roles (departments.default_roles).
 *
 * Every employee holds the base `Employee` role plus the functional roles their department lists
 * (e.g. Quality Control -> Daily Works Contributor). This is the single place that resolves
 * those sets, so create, the HR form, department admins and transfers all agree.
 */
class DepartmentDefaultRoles
{
    /** Roles that can never be handed out as a department default (privileged or base). */
    public const FORBIDDEN = ['Super Administrator', 'Administrator', 'HR Manager', 'Department Admin', User::BASE_ROLE];

    /** Default role names configured on one department (existing roles only). */
    public function forDepartment(?int $departmentId): array
    {
        if (! $departmentId) {
            return [];
        }

        return $this->existing((array) (Department::query()->whereKey($departmentId)->value('default_roles') ?? []));
    }

    /** Base role + the department's defaults: what a new employee of that department receives. */
    public function rolesForNewUser(?int $departmentId): array
    {
        return array_values(array_unique([User::BASE_ROLE, ...$this->forDepartment($departmentId)]));
    }

    /** Every role name listed as a default by ANY department (the "managed" set). */
    public function allManaged(): array
    {
        return Department::query()->whereNotNull('default_roles')->pluck('default_roles')
            ->flatten()->filter(fn ($r) => is_string($r) && $r !== '')->unique()->values()->all();
    }

    /**
     * Department transfer: add the new department's defaults, remove the old department's defaults
     * that the new one does not list. Only roles managed by some department's default_roles are ever
     * removed - never admin or base roles, never a role someone was given by hand that no department manages.
     *
     * @return array{added: string[], removed: string[]}
     */
    public function syncOnTransfer(User $user, ?int $oldDepartmentId, ?int $newDepartmentId): array
    {
        if ((int) $oldDepartmentId === (int) $newDepartmentId) {
            return ['added' => [], 'removed' => []];
        }

        $new = $this->forDepartment($newDepartmentId);
        $old = $this->forDepartment($oldDepartmentId);
        $remove = array_values(array_diff($old, $new, self::FORBIDDEN));
        $current = $user->roles()->pluck('name')->all();
        $add = array_values(array_diff($new, $current));
        $remove = array_values(array_intersect($remove, $current));

        if ($add === [] && $remove === []) {
            return ['added' => [], 'removed' => []];
        }

        DB::transaction(function () use ($user, $add, $remove) {
            if ($remove) {
                $user->removeRole(...$remove);
            }
            if ($add) {
                $user->assignRole(...$add);
            }
        });

        Log::info('Department default roles synced on transfer', [
            'user_id' => $user->employee_id,
            'from_department_id' => $oldDepartmentId,
            'to_department_id' => $newDepartmentId,
            'added' => $add,
            'removed' => $remove,
            'actor' => auth()->id(),
        ]);

        return ['added' => $add, 'removed' => $remove];
    }

    /** Filter to names that exist as web-guard roles. */
    private function existing(array $names): array
    {
        $names = array_values(array_diff(array_filter($names, 'is_string'), self::FORBIDDEN));

        return $names === [] ? [] : Role::query()->where('guard_name', 'web')->whereIn('name', $names)->pluck('name')->all();
    }
}
