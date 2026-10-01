<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may SEE, ASSIGN and MANAGE shift definitions and rotation patterns ("templates").
 *
 * A template is either company-wide (`department_id` NULL — configuration, managed with
 * `attendance.settings`) or OWNED by a department (`department_id` = X — delegated to X's
 * administrators):
 *
 *   - a company-wide actor (global role) or an attendance administrator (`attendance.settings`):
 *     sees every template; a global actor manages every one, a settings holder those they created;
 *   - a scheduler (`attendance.roster.manage` — the department admin): sees the company-wide
 *     templates plus those of the departments he administers, assigns only those, and may create /
 *     edit / delete the ones his departments own — never a company-wide one;
 *   - anyone else sees only the templates they created.
 *
 * Creator names are for company-wide viewers only (see exposesCreator()).
 */
class ShiftTemplateScope
{
    public function __construct(private readonly DepartmentScope $scope) {}

    /**
     * Narrow a shifts / shift_rotation_patterns query to the templates the actor may see and assign.
     */
    public function applyTo(Builder $query, User $actor, string $table): Builder
    {
        if ($this->scope->isAttendanceAdmin($actor)) {
            return $query;
        }

        if (! $actor->checkPermissionTo('attendance.roster.manage')) {
            // Legacy rule: a user with no scheduling right sees only what they created themselves.
            return $query->where("{$table}.created_by", $actor->getKey());
        }

        $managed = $this->scope->managedDepartmentIds($actor);

        return $query->where(function (Builder $visible) use ($table, $managed): void {
            $visible->whereNull("{$table}.department_id");
            if ($managed !== []) {
                $visible->orWhereIn("{$table}.department_id", $managed);
            }
        });
    }

    /**
     * May the actor assign this template to people? Anything visible to them, nothing else.
     */
    public function canAssign(User $actor, Model $template): bool
    {
        if ($this->scope->isAttendanceAdmin($actor)) {
            return true;
        }

        return $this->applyTo($template->newQuery()->whereKey($template->getKey()), $actor, $template->getTable())->exists();
    }

    /**
     * May the actor create a template owned by `$departmentId` (NULL = company-wide)?
     */
    public function canCreate(User $actor, ?int $departmentId): bool
    {
        if ($this->scope->isGlobal($actor) || $actor->checkPermissionTo(DepartmentScope::ATTENDANCE_SETTINGS_PERMISSION)) {
            return true;
        }

        return $this->mayAdministerDepartmentTemplates($actor, $departmentId);
    }

    /**
     * May the actor edit / delete this template?
     */
    public function canManage(User $actor, Model $template): bool
    {
        if ($this->scope->isGlobal($actor)) {
            return true;
        }

        // Company-wide configuration holders keep the long-standing rule: the templates they created.
        if ($actor->checkPermissionTo(DepartmentScope::ATTENDANCE_SETTINGS_PERMISSION)
            && (string) $template->created_by === (string) $actor->getKey()) {
            return true;
        }

        return $this->mayAdministerDepartmentTemplates($actor, $template->department_id === null ? null : (int) $template->department_id);
    }

    /**
     * May the actor move a template to `$newDepartmentId` (NULL = company-wide)? A global actor may;
     * a settings holder may for templates they manage; a delegated admin may not move it at all
     * (he can only keep it in his department).
     */
    public function canReassignDepartment(User $actor, Model $template, ?int $newDepartmentId): bool
    {
        if ((int) ($template->department_id ?? 0) === (int) ($newDepartmentId ?? 0)) {
            return true;
        }

        return $this->scope->isGlobal($actor)
            || ($actor->checkPermissionTo(DepartmentScope::ATTENDANCE_SETTINGS_PERMISSION) && $this->canManage($actor, $template));
    }

    /**
     * The delegated right: a department's own templates, for someone who schedules and administers it.
     */
    private function mayAdministerDepartmentTemplates(User $actor, ?int $departmentId): bool
    {
        return $departmentId !== null
            && $actor->checkPermissionTo('attendance.roster.manage')
            && in_array($departmentId, $this->scope->managedDepartmentIds($actor), true);
    }

    /**
     * Is the actor allowed to see WHO created a template? Company-wide actors only: a department
     * admin has no business with another department's (or HR's) author names.
     */
    public function exposesCreator(User $actor): bool
    {
        return $this->scope->isGlobal($actor);
    }

    /**
     * Flags the UI needs per template: may the actor edit/delete this one?
     *
     * @return array{can_manage: bool}
     */
    public function flagsFor(User $actor, Model $template): array
    {
        return ['can_manage' => $this->canManage($actor, $template)];
    }
}
