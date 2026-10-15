<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * One named permission per Daily Works action. The coarse permissions (view, update, delete, import) carried several
 * actions each: view alone opened status changes, assignee changes, inspection details and RFI documents. They are
 * split, and every role that held the old permission receives the new ones it implied, so nobody gains or loses access:
 *
 *   daily-works.view   -> status.update, inspection.update, assign, files.manage, history.view
 *   daily-works.update -> status.reopen, incharge.update, bulk
 *   daily-works.delete -> restore
 *   daily-works.import AND daily-works.delete -> import.reverse
 *
 * Idempotent; the RBAC pivot may be MyISAM in production, so grants are a diff of inserts.
 */
return new class extends Migration
{
    /** new permission => [[holders of any of these...], description]. import.reverse needs ALL listed. */
    private const SPLIT = [
        'daily-works.status.update' => [['daily-works.view'], 'Change the status, inspection result and RFI dates of a work'],
        'daily-works.inspection.update' => [['daily-works.view'], 'Edit the inspection details of a work'],
        'daily-works.assign' => [['daily-works.view'], 'Assign a work to an assignee'],
        'daily-works.files.manage' => [['daily-works.view'], 'Upload and delete RFI documents'],
        'daily-works.history.view' => [['daily-works.view'], 'See the revision history and status timeline of a work'],
        'daily-works.status.reopen' => [['daily-works.update'], 'Re-open a closed RFI (rejected or completed back to an open state)'],
        'daily-works.incharge.update' => [['daily-works.update'], 'Change the in-charge of a work (a recorded reassignment)'],
        'daily-works.bulk' => [['daily-works.update'], 'Use the bulk actions of the register (each also needs its own permission)'],
        'daily-works.restore' => [['daily-works.delete'], 'Restore a deleted work'],
        'daily-works.import.reverse' => [['daily-works.import', 'daily-works.delete'], 'Reverse an Excel import batch (audited, soft)'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $table = config('permission.table_names.role_has_permissions');
        $granted = 0;

        foreach (self::SPLIT as $name => [$requires, $description]) {
            $new = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['module' => 'daily-works', 'description' => $description]);
            $holders = null;
            foreach ($requires as $old) {
                $perm = Permission::query()->where('name', $old)->where('guard_name', 'web')->first();
                $ids = $perm === null ? collect() : DB::table($table)->where('permission_id', $perm->getKey())->pluck('role_id');
                $holders = $holders === null ? $ids : $holders->intersect($ids);
            }
            $already = DB::table($table)->where('permission_id', $new->getKey())->pluck('role_id')->all();
            foreach (($holders ?? collect())->diff($already) as $roleId) {
                DB::table($table)->insert(['permission_id' => $new->getKey(), 'role_id' => $roleId]);
                $granted++;
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Log::info('daily-works permissions split', ['grants' => $granted]);
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_keys(self::SPLIT))->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
