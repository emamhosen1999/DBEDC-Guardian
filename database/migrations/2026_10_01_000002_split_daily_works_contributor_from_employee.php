<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Split the field-reporting abilities out of the base `Employee` role.
 *
 * In production `Employee` carries 32 permissions. Four of them — daily-works.view,
 * daily-works.create, daily-works.export and tasks.view — are FUNCTIONAL (field reporting), not
 * self-service. Bundled into `Employee` they leak into any account that needs the base role for
 * self-service only: a department admin who must appear in rosters, swaps and attendance reports
 * (all keyed on the Employee role) would inherit Daily Works without being a field reporter.
 *
 *   1. create the role `Daily Works Contributor` (hierarchy 60, not a system role);
 *   2. give it those four permissions;
 *   3. assign it to EVERY user who currently holds `Employee` (soft-deleted ones included, so a
 *      restore keeps parity) — nobody loses access;
 *   4. only then revoke the four from `Employee`.
 *
 * Safety-incident reporting (hr.safety.incidents.create) and every hr.selfservice.* / own-scoped
 * permission stay in `Employee`. All four steps are one transaction. Idempotent: once `Employee` no
 * longer carries the four permissions the split is DONE, and a second run assigns nobody — an
 * employee created after the split (a department admin's hire, who is deliberately a plain Employee)
 * must not be swept into the contributor role by a re-run. Logs the counts.
 */
return new class extends Migration
{
    private const EMPLOYEE = 'Employee';

    private const CONTRIBUTOR = 'Daily Works Contributor';

    /** The four functional permissions (seeded in module "ppm"; they already exist in production). */
    private const PERMISSIONS = ['daily-works.view', 'daily-works.create', 'daily-works.export', 'tasks.view'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $counts = DB::transaction(function (): array {
            // Only permissions that exist: they are owned by the seeder, not by this migration (a bare
            // database that has not been seeded yet simply gets the role, and the seeder fills it).
            $names = Permission::query()->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->pluck('name')->all();

            $contributor = Role::firstOrCreate(
                ['name' => self::CONTRIBUTOR, 'guard_name' => 'web'],
                [
                    'description' => 'Field reporting: daily works and tasks. Held next to the base Employee role by staff who file daily works.',
                    'hierarchy_level' => 60,
                    'is_system_role' => false,
                ],
            );
            $contributor->givePermissionTo($names);

            $employee = Role::where('name', self::EMPLOYEE)->where('guard_name', 'web')->first();
            $bundled = $employee?->permissions()->whereIn('name', $names)->pluck('name')->all() ?? [];

            // Already split (or the base role never carried them): assign nobody.
            if ($employee === null || $bundled === []) {
                return ['employee_holders' => 0, 'newly_assigned' => 0, 'already_contributors' => 0, 'revoked_from_employee' => 0, 'skipped' => true];
            }

            $holders = DB::table('model_has_roles')
                ->where('role_id', $employee->id)
                ->where('model_type', User::class)
                ->pluck('model_id')
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values();

            $existing = DB::table('model_has_roles')
                ->where('role_id', $contributor->id)
                ->where('model_type', User::class)
                ->pluck('model_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $missing = $holders->reject(fn (string $id) => in_array($id, $existing, true))->values();

            foreach ($missing->chunk(500) as $chunk) {
                DB::table('model_has_roles')->insert(
                    $chunk->map(fn (string $id) => ['role_id' => $contributor->id, 'model_type' => User::class, 'model_id' => $id])->all()
                );
            }

            // Only now — after every holder is covered — does the base role give the abilities up.
            $employee->revokePermissionTo($bundled);

            return [
                'employee_holders' => $holders->count(),
                'newly_assigned' => $missing->count(),
                'already_contributors' => $holders->count() - $missing->count(),
                'revoked_from_employee' => count($bundled),
                'skipped' => false,
            ];
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Log::info('Daily Works Contributor split: '.json_encode($counts), $counts);
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            // Give the abilities back to the base role first, so nobody loses access while rolling back.
            $names = Permission::query()->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->pluck('name')->all();
            Role::where('name', self::EMPLOYEE)->where('guard_name', 'web')->first()?->givePermissionTo($names);

            $contributor = Role::where('name', self::CONTRIBUTOR)->where('guard_name', 'web')->first();
            if ($contributor !== null) {
                DB::table('model_has_roles')->where('role_id', $contributor->id)->delete();
                $contributor->delete();
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
