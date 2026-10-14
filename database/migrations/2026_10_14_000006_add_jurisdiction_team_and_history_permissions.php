<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * `jurisdiction.team.manage` (add/remove members, change the in-charge, hand over), `jurisdiction.restructure` (split, merge),
 * `jurisdiction.retire`, `jurisdiction.restore` and `jurisdiction.history.correct` (back-dated corrections, reason mandatory). Split out of `jurisdiction.update` (which keeps editing the jurisdiction itself), so every role that could change a
 * jurisdiction can still manage its team: the grant goes to every role holding `jurisdiction.update`.
 * Idempotent; the RBAC pivot may be MyISAM in production, so the grant is a diff, not a rewrite.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'jurisdiction.team.manage' => 'Manage the team of a jurisdiction: members, deputy in-charges, the in-charge, handovers and their history',
        'jurisdiction.restructure' => 'Split one section into two, or merge two adjacent sections, from an effective date',
        'jurisdiction.retire' => 'Retire (remove) a jurisdiction from a date: its history, works and handovers stay and it remains visible as of past dates',
        'jurisdiction.restore' => 'Restore a retired or deleted jurisdiction',
        'jurisdiction.history.correct' => 'Correct jurisdiction history with a back-dated effective date (boundaries, in-charge, team); a reason is mandatory and the change is audited',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $update = Permission::query()->where('name', 'jurisdiction.update')->where('guard_name', 'web')->first();
        $table = config('permission.table_names.role_has_permissions');
        $granted = 0;
        foreach (self::PERMISSIONS as $name => $description) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['module' => 'jurisdiction', 'description' => $description]);
            if ($update === null) {
                continue;
            }
            $holders = DB::table($table)->where('permission_id', $update->getKey())->pluck('role_id');
            $already = DB::table($table)->where('permission_id', $permission->getKey())->pluck('role_id')->all();
            foreach ($holders->diff($already) as $roleId) {
                DB::table($table)->insert(['permission_id' => $permission->getKey(), 'role_id' => $roleId]);
                $granted++;
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Log::info('jurisdiction team/history permissions granted', ['grants' => $granted]);
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_keys(self::PERMISSIONS))->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
