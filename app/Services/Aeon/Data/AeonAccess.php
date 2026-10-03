<?php

declare(strict_types=1);

namespace App\Services\Aeon\Data;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single authority on what Aeon may read for a given actor. FAILS CLOSED.
 *
 * A table is queryable only if it appears in REGISTRY AND the actor holds one of its permissions
 * (the same permission that gates the module's own routes) or one of its "own" permissions
 * (then only the actor's own rows). Everything else (role/permission pivots, tokens, sessions,
 * audit and self-administration logs, other people's notifications, settings and secrets) is
 * simply not in the registry, so it is "unknown": never listed, never confirmed to exist.
 *
 * Row scope reuses App\Services\Access\DepartmentScope; global actors skip ROW filters only,
 * never the allowlist or the permission gate. Columns are narrowed too: secrets and identity
 * documents (NID, passport, bank) are never returned, compensation only with
 * employees.compensation.view.
 *
 * Registry entry:
 *   perms  any-of permissions that grant the scoped view of the table
 *   own    any-of permissions that grant only the actor's own rows ('*' = any signed-in user)
 *   scope  [type, ...] where type is one of
 *            users               the users table itself (DepartmentScope::applyToUsers)
 *            employee, <fk>      employee-owned rows (DepartmentScope::applyToEmployeeOwned)
 *            via, <fk>, <parent>, <parentFk>   child rows of an employee-owned parent table
 *            department, <col>   rows whose department column is in the actor's visible departments
 *            departments         the departments table (DepartmentScope::applyToDepartments)
 *            none                module table with no row scoping in the app itself: permission gate alone
 *   columns optional allowlist of visible columns
 */
class AeonAccess
{
    public const COMPENSATION_PERMISSION = 'employees.compensation.view';

    /** @var array<string, array<string, mixed>> */
    private const REGISTRY = [
        'users' => [
            'perms' => ['employees.view', 'users.view'],
            'own' => ['profile.own.view'],
            'scope' => ['users'],
            'columns' => [
                'employee_id', 'name', 'user_name', 'email', 'phone', 'department_id', 'designation_id', 'report_to',
                'date_of_joining', 'gender', 'employment_status', 'is_active', 'attendance_type_id', 'work_location_id',
                'probation_end_date', 'confirmation_date', 'salary_basis', 'salary_amount', 'created_at', 'updated_at',
            ],
        ],
        'departments' => ['perms' => ['departments.view'], 'scope' => ['departments']],
        'designations' => ['perms' => ['designations.view'], 'scope' => ['none']],
        'holidays' => ['perms' => ['holidays.view'], 'scope' => ['none']],
        'leave_settings' => ['perms' => ['leaves.view'], 'scope' => ['none']],

        // Employee-owned HR records.
        'attendances' => ['perms' => ['attendance.view'], 'own' => ['attendance.own.view'], 'scope' => ['employee', 'user_id']],
        'leaves' => ['perms' => ['leaves.view'], 'own' => ['leave.own.view', 'leaves.own.view'], 'scope' => ['employee', 'user_id']],
        'overtime_requests' => ['perms' => ['attendance.view', 'attendance.correct'], 'own' => ['attendance.own.view'], 'scope' => ['employee', 'user_id']],
        'attendance_regularizations' => ['perms' => ['attendance.view', 'attendance.correct'], 'own' => ['attendance.own.view'], 'scope' => ['employee', 'user_id']],
        'roster_days' => ['perms' => ['attendance.view', 'attendance.roster.manage'], 'own' => ['attendance.own.view'], 'scope' => ['employee', 'user_id']],
        'onboardings' => ['perms' => ['hr.onboarding.view'], 'scope' => ['employee', 'employee_id']],
        'offboardings' => ['perms' => ['hr.offboarding.view'], 'scope' => ['employee', 'employee_id']],
        'assets' => ['perms' => ['hr.assets.view'], 'scope' => ['employee', 'assignee_id']],

        // Modules whose own UI applies no row scoping: the permission gate alone.
        'daily_works' => ['perms' => ['daily-works.view'], 'scope' => ['none']],
        'rfi_objections' => ['perms' => ['daily-works.view'], 'scope' => ['none']],
        'quality_ncrs' => ['perms' => ['quality.ncr.view'], 'scope' => ['none']],
        'om_incidents' => ['perms' => ['om.incidents.view'], 'scope' => ['none']],
        'om_work_orders' => ['perms' => ['om.maintenance.view'], 'scope' => ['none']],
        'om_defects' => ['perms' => ['om.maintenance.view'], 'scope' => ['none']],
        'om_assets' => ['perms' => ['om.equipment.view'], 'scope' => ['none']],

        // Petty cash: approvers see the scoped ledger; everyone else only their own loans.
        'petty_cash_loans' => ['perms' => ['petty-cash.approve'], 'own' => ['*'], 'scope' => ['employee', 'user_id']],
        'petty_cash_transactions' => ['perms' => ['petty-cash.approve'], 'own' => ['*'], 'scope' => ['via', 'petty_cash_loan_id', 'petty_cash_loans', 'user_id']],
    ];

    /** Entity names the model commonly uses, mapped onto registry tables. */
    private const ALIASES = [
        'employee' => 'users', 'employees' => 'users', 'staff' => 'users',
        'ncr' => 'quality_ncrs', 'ncrs' => 'quality_ncrs',
        'daily_work' => 'daily_works', 'attendance' => 'attendances', 'leave' => 'leaves', 'leave_requests' => 'leaves',
        'incidents' => 'om_incidents', 'work_orders' => 'om_work_orders', 'defects' => 'om_defects',
        'petty_cash' => 'petty_cash_loans', 'loans' => 'petty_cash_loans',
        'objections' => 'rfi_objections', 'overtime' => 'overtime_requests', 'regularizations' => 'attendance_regularizations',
    ];

    public function __construct(
        private SchemaCatalog $schema,
        private DepartmentScope $departments,
    ) {}

    public function actor(int|string|null $userId): ?User
    {
        return $userId === null ? null : User::find($userId);
    }

    /**
     * The access the actor has on a table: 'full' (scoped view), 'own' (own rows only) or null.
     *
     * @return array{mode: string, def: array<string, mixed>}|null
     */
    public function policy(User $actor, string $table): ?array
    {
        $def = self::REGISTRY[$table] ?? null;
        if ($def === null || ! isset($this->schema->all()[$table])) {
            return null;
        }

        if ($this->holdsAny($actor, $def['perms'])) {
            return ['mode' => 'full', 'def' => $def];
        }

        $own = $def['own'] ?? [];
        if ($own !== [] && ($own === ['*'] || $this->holdsAny($actor, $own)) && $this->supportsOwn($def)) {
            return ['mode' => 'own', 'def' => $def];
        }

        return null;
    }

    /**
     * Tables the actor may query, with the columns they may see.
     *
     * @return array<string, array{name: string, label: string, columns: array<int, string>, date_columns: array<int, string>, numeric_columns: array<int, string>, fk_columns: array<int, string>, scope: string}>
     */
    public function tables(User $actor): array
    {
        $out = [];
        foreach (array_keys(self::REGISTRY) as $table) {
            $meta = $this->entity($actor, $table);
            if ($meta !== null) {
                $out[$table] = $meta;
            }
        }

        return $out;
    }

    /**
     * Resolve user/model input to a table the actor may query, or null (= unknown).
     */
    public function resolveTable(User $actor, string $name): ?string
    {
        $name = strtolower(trim($name));
        $snake = Str::snake($name);
        foreach ([$name, Str::plural($name), $snake, Str::plural($snake), self::ALIASES[$name] ?? null, self::ALIASES[$snake] ?? null] as $candidate) {
            if ($candidate !== null && isset(self::REGISTRY[$candidate]) && $this->policy($actor, $candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{name: string, label: string, columns: array<int, string>, date_columns: array<int, string>, numeric_columns: array<int, string>, fk_columns: array<int, string>, scope: string}|null
     */
    public function entity(User $actor, string $name): ?array
    {
        $table = $this->resolveTable($actor, $name);
        if ($table === null) {
            return null;
        }

        $base = $this->schema->all()[$table];
        $columns = $this->columns($actor, $table);
        if ($columns === []) {
            return null;
        }

        return [
            'name' => $table,
            'label' => $base['label'],
            'columns' => $columns,
            'date_columns' => array_values(array_intersect($base['date_columns'], $columns)),
            'numeric_columns' => array_values(array_intersect($base['numeric_columns'], $columns)),
            'fk_columns' => array_values(array_intersect($base['fk_columns'], $columns)),
            'scope' => $this->policy($actor, $table)['mode'] === 'own' ? 'own records only' : $this->scopeLabel($actor, $table),
        ];
    }

    /**
     * Columns the actor may read, filter, group or aggregate on this table.
     *
     * @return array<int, string>
     */
    public function columns(User $actor, string $table): array
    {
        $policy = $this->policy($actor, $table);
        if ($policy === null) {
            return [];
        }

        $columns = $this->schema->all()[$table]['columns'];
        if (isset($policy['def']['columns'])) {
            $columns = array_values(array_intersect($columns, $policy['def']['columns']));
        }

        $compensation = $actor->can(self::COMPENSATION_PERMISSION);
        $columns = array_values(array_filter($columns, fn (string $c) => ! $this->schema->isSensitive($c)
            && ($compensation || ! $this->schema->isCompensation($c))));

        return $columns;
    }

    /**
     * Narrow a raw query to the rows the actor may see on this table. The caller must have obtained
     * $table from resolveTable(); a table without a policy yields no rows.
     */
    public function scoped(Builder $qb, User $actor, string $table): Builder
    {
        $policy = $this->policy($actor, $table);
        if ($policy === null) {
            return $qb->whereRaw('1 = 0');
        }

        $scope = $policy['def']['scope'];
        $key = (string) $actor->getKey();

        if ($policy['mode'] === 'own') {
            return $this->applyOwn($qb, $table, $scope, $key);
        }

        return $this->applyFull($qb, $actor, $table, $scope);
    }

    /**
     * Schema description handed to the model: only what this actor may query.
     */
    public function promptSchema(User $actor): string
    {
        $tables = $this->tables($actor);
        if ($tables === []) {
            return 'You have no queryable data tables for this user; use the other tools or answer from the knowledge base.';
        }

        $lines = ['QUERYABLE DATA (query_data may use ONLY these tables and columns; anything else does not exist for this user):'];
        foreach ($tables as $name => $meta) {
            $lines[] = "- {$name} [{$meta['scope']}]: ".implode(', ', $meta['columns']);
        }

        return implode("\n", $lines);
    }

    /**
     * Does the actor hold any one of the permissions?
     *
     * @param  array<int, string>  $permissions
     */
    public function holdsAny(User $actor, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($actor->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $scope
     */
    private function applyFull(Builder $qb, User $actor, string $table, array $scope): Builder
    {
        $global = $this->departments->isGlobal($actor);

        switch ($scope[0]) {
            case 'users':
                $qb->whereNull('users.deleted_at');
                $this->departments->applyToUsers($this->wrap($qb), $actor, 'users.department_id');
                break;
            case 'departments':
                $qb->whereNull('departments.deleted_at');
                $this->departments->applyToDepartments($this->wrap($qb), $actor, 'departments.id');
                break;
            case 'employee':
                $this->departments->applyToEmployeeOwned($this->wrap($qb), $actor, $table.'.'.$scope[1]);
                break;
            case 'via':
                if (! $global) {
                    $parent = DB::table($scope[2])->select('id');
                    $this->departments->applyToEmployeeOwned($this->wrap($parent), $actor, $scope[2].'.'.$scope[3]);
                    $qb->whereIn($table.'.'.$scope[1], $parent);
                }
                break;
            case 'department':
                if (! $global) {
                    $qb->whereIn($table.'.'.$scope[1], $this->departments->visibleDepartmentIds($actor));
                }
                break;
            case 'none':
                break;
            default:
                $qb->whereRaw('1 = 0'); // unknown strategy: fail closed
        }

        return $qb;
    }

    /**
     * @param  array<int, string>  $scope
     */
    private function applyOwn(Builder $qb, string $table, array $scope, string $key): Builder
    {
        switch ($scope[0]) {
            case 'users':
                return $qb->where('users.employee_id', $key)->whereNull('users.deleted_at');
            case 'employee':
                return $qb->where($table.'.'.$scope[1], $key);
            case 'via':
                return $qb->whereIn($table.'.'.$scope[1], DB::table($scope[2])->select('id')->where($scope[2].'.'.$scope[3], $key));
            default:
                return $qb->whereRaw('1 = 0');
        }
    }

    /**
     * DepartmentScope's helpers take an Eloquent builder; this wraps a raw query so they narrow it in place.
     */
    private function wrap(Builder $qb): EloquentBuilder
    {
        $from = $qb->from;
        $wrapped = (new EloquentBuilder($qb))->setModel(new User);
        $qb->from($from); // setModel() re-points the query at the users table

        return $wrapped;
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private function supportsOwn(array $def): bool
    {
        return in_array($def['scope'][0], ['users', 'employee', 'via'], true);
    }

    private function scopeLabel(User $actor, string $table): string
    {
        if ($this->departments->isGlobal($actor)) {
            return 'all records';
        }

        return match (self::REGISTRY[$table]['scope'][0]) {
            'none' => 'all records',
            default => 'your department and reports',
        };
    }
}
