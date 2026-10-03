<?php

namespace App\Services\Access;

use App\Models\User;
use App\Services\Admin\UserManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Plans, backs up, applies, verifies and restores an assignment plan (see CatalogPlan).
 *
 * The RBAC tables are MyISAM in production, so DB::transaction protects NOTHING here. The design
 * instead is: absolute targets (re-running converges), attach-before-detach on every pivot, the
 * backup written before the first write, and a verification pass that reports drift instead of
 * trusting that every write landed.
 */
class CatalogApplier
{
    private const USER_TYPE = 'App\\Models\\User';

    /** @var array<int, string> the RBAC tables a backup carries (config keys of spatie/laravel-permission) */
    private const TABLES = ['roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions'];

    public function __construct(private readonly UserManagementService $users, private readonly AccessAudit $audit) {}

    public function backupRoot(): string
    {
        return storage_path('app/access-backups');
    }

    /**
     * Dry-run: what apply would do, and every reason it must not.
     *
     * @return array{plan_hash: string, errors: array<int, string>, warnings: array<int, string>, users: array<string, array<string, mixed>>, defaults: array<string, array<string, mixed>>, orphans: array<string, int>, deletable: array<string, int|null>}
     */
    public function plan(CatalogPlan $plan): array
    {
        $errors = $plan->staticErrors();
        $warnings = [];
        $state = $this->state();
        $result = [];

        foreach ($plan->users() as $id => $entry) {
            $label = "{$id} ({$entry['name']})";
            if (! array_key_exists($id, $state['users'])) {
                $errors[] = "{$label}: user not found";

                continue;
            }
            if ($state['users'][$id] !== null) {
                $warnings[] = "{$label}: inactive (soft deleted); skipped";

                continue;
            }

            $targetRoles = $plan->targetRoles($id);
            foreach ($targetRoles as $role) {
                if (! isset($state['rolePermissions'][$role])) {
                    $errors[] = "{$label}: target role '{$role}' does not exist (run the catalog migrations first)";
                }
            }

            $currentRoles = $state['userRoles'][$id] ?? [];
            $currentDirect = $state['userDirect'][$id] ?? [];
            $targetDirect = $plan->targetDirectPermissions($id);
            $before = $this->effective($state, $currentRoles, $currentDirect);
            $after = $this->effective($state, $targetRoles, $targetDirect);
            $lost = array_values(array_diff($before, $after));
            $unexpected = array_values(array_diff($lost, $plan->expectedLosses($id)));
            if ($unexpected !== []) {
                $errors[] = "{$label}: would lose permissions not in expected_losses: ".implode(', ', array_slice($unexpected, 0, 10));
            }

            if (! in_array(User::BASE_ROLE, $targetRoles, true)) {
                $errors[] = "{$label}: would not hold the Employee role";
            }
            if (! array_intersect($targetRoles, RoleCatalog::SSD_EXEMPT_ROLES)) {
                foreach (RoleCatalog::SSD_PAIRS as [$a, $b, $why]) {
                    if (in_array($a, $after, true) && in_array($b, $after, true)) {
                        $errors[] = "{$label}: segregation of duties violated ({$why})";
                    }
                }
            }

            $removeDirect = array_values(array_diff($currentDirect, $targetDirect, RoleCatalog::PER_PERSON_PERMISSIONS));
            $result[$id] = [
                'name' => $entry['name'],
                'roles_add' => array_values(array_diff($targetRoles, $currentRoles)),
                'roles_remove' => array_values(array_diff($currentRoles, $targetRoles)),
                'direct_add' => array_values(array_diff($targetDirect, $currentDirect)),
                'direct_remove' => $removeDirect,
                'lost' => $lost,
            ];
        }

        foreach ($state['users'] as $id => $deletedAt) {
            if ($deletedAt === null && ! isset($plan->users()[$id])) {
                $roles = $state['userRoles'][$id] ?? [];
                if (! in_array(User::BASE_ROLE, $roles, true)) {
                    $errors[] = "{$id}: active user is not in the plan and lacks the Employee role";
                } else {
                    $warnings[] = "{$id}: active user is not in the plan; left unchanged";
                }
            }
        }

        $defaults = [];
        foreach ($plan->departmentDefaultRoles() as $departmentId => $roles) {
            $current = $this->departmentDefaults((int) $departmentId);
            if ($current === null) {
                $errors[] = "department {$departmentId} not found";

                continue;
            }
            foreach ($roles as $role) {
                if (! isset($state['rolePermissions'][$role])) {
                    $errors[] = "department {$departmentId}: default role '{$role}' does not exist";
                }
            }
            $defaults[$departmentId] = ['current' => $current, 'target' => $roles, 'changes' => $this->differs($current, $roles)];
        }

        $orphans = $plan->detachesRolesFromInactiveUsers() ? $this->orphanRoleCounts($state) : [];

        $deletable = [];
        foreach ($plan->rolesToDeleteWhenUnheld() as $roleName) {
            $deletable[$roleName] = isset($state['rolePermissions'][$roleName])
                ? $this->holdersAfter($state, $roleName, $plan, $result)
                : null;
        }

        return [
            'plan_hash' => $plan->hash(),
            'errors' => array_values(array_unique($errors)),
            'warnings' => $warnings,
            'users' => $result,
            'defaults' => $defaults,
            'orphans' => $orphans,
            'deletable' => $deletable,
        ];
    }

    /** Write the backup (JSON per table + a manifest written last) and return its directory. */
    public function backup(CatalogPlan $plan): string
    {
        $directory = $this->backupRoot().'/'.now()->format('Ymd_His').'_'.substr($plan->hash(), 0, 8);
        File::ensureDirectoryExists($directory);

        $counts = [];
        foreach (self::TABLES as $key) {
            $table = $key === 'roles' || $key === 'permissions' ? $key : config("permission.table_names.{$key}");
            $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            $counts[$table] = count($rows);
            File::put("{$directory}/{$table}.json", json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        $departments = DB::table('departments')->get(['id', 'name', 'default_roles'])->map(fn ($row) => (array) $row)->all();
        $counts['departments'] = count($departments);
        File::put("{$directory}/departments.json", json_encode($departments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $dump = $this->mysqldump($directory);

        // The manifest is written LAST: a directory without one is an unfinished backup and is never accepted.
        File::put("{$directory}/manifest.json", json_encode([
            'plan_hash' => $plan->hash(),
            'created_at' => now()->toIso8601String(),
            'tables' => $counts,
            'mysqldump' => $dump,
            'complete' => true,
        ], JSON_PRETTY_PRINT));

        return $directory;
    }

    /** The newest complete backup taken for exactly this plan hash, or null. */
    public function findBackup(CatalogPlan $plan): ?string
    {
        $found = null;
        foreach (glob($this->backupRoot().'/*/manifest.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);
            if (($data['complete'] ?? false) === true && ($data['plan_hash'] ?? null) === $plan->hash()) {
                $found = dirname($manifest); // glob() sorts by name = by timestamp: the last match is the newest
            }
        }

        return $found;
    }

    /**
     * Apply the plan. Every step is idempotent, so a run that stopped halfway is finished by running it again.
     *
     * @return array<string, int>
     */
    public function apply(CatalogPlan $plan, string $actor, string $reason): array
    {
        $stats = ['users_changed' => 0, 'roles_attached' => 0, 'roles_detached' => 0, 'direct_granted' => 0, 'direct_revoked' => 0, 'defaults_changed' => 0, 'orphan_rows_detached' => 0, 'roles_deleted' => 0];

        $this->audit->withContext($actor, $reason, $plan->hash(), function () use ($plan, &$stats) {
            // 1. Department default roles first: a hire during the run gets the new default already.
            foreach ($plan->departmentDefaultRoles() as $departmentId => $roles) {
                $current = $this->departmentDefaults((int) $departmentId);
                if ($current !== null && $this->differs($current, $roles)) {
                    DB::table('departments')->where('id', $departmentId)->update(['default_roles' => json_encode(array_values($roles))]);
                    $this->audit->record('catalog.apply.default_roles', 'department', $departmentId, ['default_roles' => $current], ['default_roles' => $roles]);
                    $stats['defaults_changed']++;
                }
            }

            // 2. People: roles attach-before-detach, then direct permissions.
            foreach ($plan->users() as $id => $entry) {
                $user = User::query()->find($id); // soft-deleted users are not found: never touched
                if ($user === null) {
                    continue;
                }

                $beforeRoles = $user->roles()->pluck('name')->all();
                $beforeDirect = $user->permissions()->pluck('name')->all();

                $roleChange = $this->users->reconcileRoles($user, $plan->targetRoles($id));
                $direct = $this->reconcileDirectPermissions($user, $plan->targetDirectPermissions($id));

                $changed = $roleChange['added'] || $roleChange['removed'] || $direct['granted'] || $direct['revoked'];
                if ($changed) {
                    $this->audit->record(
                        'catalog.apply',
                        'user',
                        $id,
                        ['roles' => $beforeRoles, 'direct_permissions' => $beforeDirect],
                        ['roles' => $user->roles()->pluck('name')->all(), 'direct_permissions' => $user->permissions()->pluck('name')->all()],
                    );
                    app(DepartmentScope::class)->forget($user);
                    $stats['users_changed']++;
                }
                $stats['roles_attached'] += count($roleChange['added']);
                $stats['roles_detached'] += count($roleChange['removed']);
                $stats['direct_granted'] += count($direct['granted']);
                $stats['direct_revoked'] += count($direct['revoked']);
            }

            // 3. Role rows left on deleted or missing users (nothing can use them; they only block deleting a role).
            if ($plan->detachesRolesFromInactiveUsers()) {
                $stats['orphan_rows_detached'] = $this->detachOrphans();
            }

            // 4. Roles retired once nobody holds them.
            foreach ($plan->rolesToDeleteWhenUnheld() as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                if ($role === null) {
                    continue;
                }
                if (DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $role->id)->exists()) {
                    Log::warning("access:apply-catalog: role {$roleName} still has holders; not deleted.");

                    continue;
                }
                $role->permissions()->detach();
                $role->delete();
                $this->audit->record('catalog.apply.role_deleted', 'role', $roleName, ['hierarchy_level' => $role->hierarchy_level], null);
                $stats['roles_deleted']++;
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $stats;
    }

    /**
     * Recompute the live state against the plan.
     *
     * @return array<int, string> one line per drift; empty = the database equals the plan
     */
    public function verify(CatalogPlan $plan): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $state = $this->state();
        $drift = [];

        foreach ($plan->users() as $id => $entry) {
            if (! $this->isActive($state, $id)) {
                continue; // missing or inactive: not applied, not verified
            }
            $roles = $state['userRoles'][$id] ?? [];
            $target = $plan->targetRoles($id);
            if ($this->differs($roles, $target)) {
                $drift[] = "{$id}: roles are [".implode(', ', $roles).'] but the plan says ['.implode(', ', $target).']';
            }
            $direct = array_values(array_diff($state['userDirect'][$id] ?? [], RoleCatalog::PER_PERSON_PERMISSIONS));
            $wantDirect = array_values(array_diff($plan->targetDirectPermissions($id), RoleCatalog::PER_PERSON_PERMISSIONS));
            if ($this->differs($direct, $wantDirect)) {
                $drift[] = "{$id}: direct permissions are [".implode(', ', $direct).'] but the plan says ['.implode(', ', $wantDirect).']';
            }
            foreach ($plan->targetDirectPermissions($id) as $permission) {
                if (! in_array($permission, $state['userDirect'][$id] ?? [], true)) {
                    $drift[] = "{$id}: lacks the direct permission {$permission}";
                }
            }
        }

        foreach ($plan->departmentDefaultRoles() as $departmentId => $roles) {
            $current = $this->departmentDefaults((int) $departmentId);
            if ($current === null || $this->differs($current, $roles)) {
                $drift[] = "department {$departmentId}: default_roles are [".implode(', ', $current ?? []).'] but the plan says ['.implode(', ', $roles).']';
            }
        }

        if ($plan->detachesRolesFromInactiveUsers()) {
            foreach ($this->orphanRoleCounts($state) as $role => $count) {
                $drift[] = "{$count} role row(s) of {$role} remain on deleted or missing users";
            }
        }

        foreach ($plan->rolesToDeleteWhenUnheld() as $roleName) {
            if (isset($state['rolePermissions'][$roleName])) {
                $holders = DB::table(config('permission.table_names.model_has_roles'))->where('role_id', array_search($roleName, $state['roles'], true))->count();
                $drift[] = $holders === 0 ? "role {$roleName} still exists with no holder" : "role {$roleName} still has {$holders} holder(s)";
            }
        }

        return $drift;
    }

    /**
     * Put a backup back through the same diff-based path (attach before detach) and audit it.
     *
     * @return array<string, int>
     */
    public function restore(string $directory, string $actor, string $reason): array
    {
        $manifest = json_decode((string) @file_get_contents("{$directory}/manifest.json"), true);
        if (($manifest['complete'] ?? false) !== true) {
            throw new RuntimeException("Not a complete backup: {$directory}");
        }
        $read = fn (string $table): array => json_decode((string) file_get_contents("{$directory}/{$table}.json"), true) ?? [];
        $pivot = fn (string $key): string => config("permission.table_names.{$key}");
        $stats = ['roles_restored' => 0, 'role_permission_changes' => 0, 'subjects_changed' => 0, 'defaults_restored' => 0];

        $this->audit->withContext($actor, $reason, $manifest['plan_hash'] ?? null, function () use ($read, $pivot, &$stats) {
            // Roles first (a deleted role comes back with its id), then what each role carries, then who holds what.
            foreach ($read('roles') as $row) {
                $existing = DB::table('roles')->where('id', $row['id'])->first();
                if ($existing === null) {
                    if (! DB::table('roles')->where('name', $row['name'])->where('guard_name', $row['guard_name'])->exists()) {
                        DB::table('roles')->insert($row);
                        $stats['roles_restored']++;
                        $this->audit->record('catalog.restore.role', 'role', $row['name'], null, ['id' => $row['id']]);
                    }
                } elseif ($existing->name !== $row['name'] || (int) $existing->hierarchy_level !== (int) ($row['hierarchy_level'] ?? 0)) {
                    DB::table('roles')->where('id', $row['id'])->update(array_intersect_key($row, array_flip(['name', 'hierarchy_level', 'description'])));
                    $stats['roles_restored']++;
                }
            }

            foreach ($this->groupPivot($read($pivot('role_has_permissions')), 'role_id', 'permission_id') as $roleId => $permissionIds) {
                $stats['role_permission_changes'] += $this->reconcilePivot($pivot('role_has_permissions'), 'role_id', $roleId, 'permission_id', $permissionIds);
            }

            $changedUsers = [];
            foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $key => $column) {
                $rows = array_filter($read($pivot($key)), fn ($r) => $r['model_type'] === self::USER_TYPE);
                $grouped = $this->groupPivot($rows, 'model_id', $column);
                // Users with rows now but none in the backup are reconciled to empty as well.
                foreach (DB::table($pivot($key))->where('model_type', self::USER_TYPE)->distinct()->pluck('model_id') as $modelId) {
                    $grouped[(string) $modelId] ??= [];
                }
                foreach ($grouped as $modelId => $ids) {
                    $changes = $this->reconcilePivot($pivot($key), 'model_id', $modelId, $column, $ids, ['model_type' => self::USER_TYPE]);
                    if ($changes > 0) {
                        $changedUsers[(string) $modelId] = true;
                        $this->audit->record('catalog.restore', 'user', $modelId, null, ['pivot' => $key, 'ids' => $ids]);
                    }
                }
            }
            foreach (array_keys($changedUsers) as $employeeId) {
                $stats['subjects_changed']++;
                try {
                    User::query()->find($employeeId)?->bumpSyncEpoch();
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }

            foreach ($read('departments') as $row) {
                $current = DB::table('departments')->where('id', $row['id'])->value('default_roles');
                if ($current !== null || $row['default_roles'] !== null) {
                    if ((string) $current !== (string) $row['default_roles']) {
                        DB::table('departments')->where('id', $row['id'])->update(['default_roles' => $row['default_roles']]);
                        $this->audit->record('catalog.restore.default_roles', 'department', $row['id'], ['default_roles' => $current], ['default_roles' => $row['default_roles']]);
                        $stats['defaults_restored']++;
                    }
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $stats;
    }

    /**
     * Direct permissions: grant the missing first, then revoke the extras. Documented per-person
     * exceptions (access.self-administration) are never revoked, whatever the plan says.
     *
     * @param  array<int, string>  $target
     * @return array{granted: array<int, string>, revoked: array<int, string>}
     */
    private function reconcileDirectPermissions(User $user, array $target): array
    {
        $current = $user->permissions()->pluck('name')->all();
        $grant = array_values(array_diff($target, $current));
        $revoke = array_values(array_diff($current, $target, RoleCatalog::PER_PERSON_PERMISSIONS));

        if ($grant !== []) {
            $user->givePermissionTo($grant);
        }
        if ($revoke !== []) {
            $user->revokePermissionTo($revoke);
        }
        $user->unsetRelation('permissions');

        return ['granted' => $grant, 'revoked' => $revoke];
    }

    /** @return int how many role rows were detached from deleted or missing users */
    private function detachOrphans(): int
    {
        $table = config('permission.table_names.model_has_roles');
        $active = DB::table('users')->whereNull('deleted_at')->pluck('employee_id')->map(fn ($id) => (string) $id)->all();
        $query = DB::table($table)->where('model_type', self::USER_TYPE);
        if ($active !== []) {
            $query->whereNotIn('model_id', $active);
        }

        $rows = (clone $query)->get(['model_id', 'role_id']);
        if ($rows->isEmpty()) {
            return 0;
        }

        $names = DB::table('roles')->pluck('name', 'id');
        foreach ($rows->groupBy('model_id') as $modelId => $group) {
            $this->audit->record('catalog.apply.orphan_roles', 'user', $modelId, ['roles' => $group->map(fn ($r) => $names[$r->role_id] ?? "#{$r->role_id}")->all()], null);
        }
        $query->delete();

        return $rows->count();
    }

    /** @return array<string, int> role name => orphan rows */
    private function orphanRoleCounts(array $state): array
    {
        $counts = [];
        foreach ($state['pivotRows'] as [$modelId, $roleId]) {
            if (! $this->isActive($state, $modelId)) {
                $name = $state['roles'][$roleId] ?? "#{$roleId}";
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    /** Holders of a role once the plan is applied (planned active users + unplanned active users; orphans go). */
    private function holdersAfter(array $state, string $roleName, CatalogPlan $plan, array $planned): int
    {
        $count = 0;
        foreach ($state['users'] as $id => $deletedAt) {
            if ($deletedAt !== null) {
                continue;
            }
            $roles = isset($plan->users()[$id]) ? $plan->targetRoles($id) : ($state['userRoles'][$id] ?? []);
            $count += in_array($roleName, $roles, true) ? 1 : 0;
        }

        return $count;
    }

    /**
     * @param  array<int, string>  $roles
     * @param  array<int, string>  $direct
     * @return array<int, string>
     */
    private function effective(array $state, array $roles, array $direct): array
    {
        $names = $direct;
        foreach ($roles as $role) {
            $names = array_merge($names, $state['rolePermissions'][$role] ?? []);
        }

        return array_values(array_unique($names));
    }

    /** Active = the user row exists and is not soft deleted (a null deleted_at, which `??` would read as "missing"). */
    private function isActive(array $state, string $employeeId): bool
    {
        return array_key_exists($employeeId, $state['users']) && $state['users'][$employeeId] === null;
    }

    /** @return array<int, string>|null null = the department does not exist */
    private function departmentDefaults(int $departmentId): ?array
    {
        $row = DB::table('departments')->where('id', $departmentId)->first(['default_roles']);
        if ($row === null) {
            return null;
        }

        return array_values((array) json_decode((string) $row->default_roles, true));
    }

    private function differs(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return array_values($a) !== array_values($b);
    }

    /**
     * Everything the plan is compared against, read once.
     *
     * @return array{roles: array<int, string>, rolePermissions: array<string, array<int, string>>, users: array<string, string|null>, userRoles: array<string, array<int, string>>, userDirect: array<string, array<int, string>>, pivotRows: array<int, array{0: string, 1: int}>}
     */
    private function state(): array
    {
        $roles = DB::table('roles')->where('guard_name', 'web')->pluck('name', 'id')->all();

        $rolePermissions = array_fill_keys(array_values($roles), []);
        $rows = DB::table(config('permission.table_names.role_has_permissions').' as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')->get(['rp.role_id', 'p.name']);
        foreach ($rows as $row) {
            if (isset($roles[$row->role_id])) {
                $rolePermissions[$roles[$row->role_id]][] = $row->name;
            }
        }

        $users = [];
        foreach (DB::table('users')->get(['employee_id', 'deleted_at']) as $row) {
            $users[(string) $row->employee_id] = $row->deleted_at;
        }

        $userRoles = [];
        $pivotRows = [];
        foreach (DB::table(config('permission.table_names.model_has_roles'))->where('model_type', self::USER_TYPE)->get(['role_id', 'model_id']) as $row) {
            $pivotRows[] = [(string) $row->model_id, (int) $row->role_id];
            if (isset($roles[$row->role_id])) {
                $userRoles[(string) $row->model_id][] = $roles[$row->role_id];
            }
        }

        $userDirect = [];
        $direct = DB::table(config('permission.table_names.model_has_permissions').' as mp')
            ->join('permissions as p', 'p.id', '=', 'mp.permission_id')->where('mp.model_type', self::USER_TYPE)->get(['mp.model_id', 'p.name']);
        foreach ($direct as $row) {
            $userDirect[(string) $row->model_id][] = $row->name;
        }

        return compact('roles', 'rolePermissions', 'users', 'userRoles', 'userDirect', 'pivotRows');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<int, int>>
     */
    private function groupPivot(array $rows, string $subject, string $target): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row[$subject]][] = (int) $row[$target];
        }

        return $grouped;
    }

    /**
     * Attach the missing ids first, then detach the extras, on a raw pivot.
     *
     * @param  array<int, int>  $ids
     * @param  array<string, string>  $scope
     * @return int rows changed
     */
    private function reconcilePivot(string $table, string $subjectColumn, string|int $subject, string $targetColumn, array $ids, array $scope = []): int
    {
        $current = DB::table($table)->where($scope)->where($subjectColumn, $subject)->pluck($targetColumn)->map(fn ($id) => (int) $id)->all();
        $add = array_values(array_diff($ids, $current));
        $remove = array_values(array_diff($current, $ids));

        if ($add !== []) {
            DB::table($table)->insert(array_map(fn ($id) => $scope + [$subjectColumn => $subject, $targetColumn => $id], $add));
        }
        if ($remove !== []) {
            DB::table($table)->where($scope)->where($subjectColumn, $subject)->whereIn($targetColumn, $remove)->delete();
        }

        return count($add) + count($remove);
    }

    /** Best effort: a SQL dump next to the JSON one. The JSON backup is the authoritative one. */
    private function mysqldump(string $directory): ?string
    {
        $connection = config('database.connections.'.config('database.default'));
        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            return null;
        }
        $binary = (new ExecutableFinder)->find('mysqldump');
        if ($binary === null) {
            return 'mysqldump not found';
        }

        $tables = array_map(fn ($key) => $key === 'roles' || $key === 'permissions' ? $key : config("permission.table_names.{$key}"), self::TABLES);
        $process = new Process([
            $binary, '--no-tablespaces', '--skip-lock-tables',
            '-h', (string) $connection['host'], '-P', (string) $connection['port'], '-u', (string) $connection['username'],
            (string) $connection['database'], ...$tables, 'departments',
        ], null, ['MYSQL_PWD' => (string) $connection['password']], null, 120);
        $process->run();
        if (! $process->isSuccessful()) {
            return 'mysqldump failed: '.trim(mb_substr($process->getErrorOutput(), 0, 200));
        }
        File::put("{$directory}/tables.sql", $process->getOutput());

        return 'tables.sql';
    }
}
