<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Department -> default functional roles.
 *
 * `departments.default_roles` is a nullable JSON list of role names every employee of that
 * department receives next to the base `Employee` role (on create, and on transfer in). The
 * Daily Works Contributor role belongs to Quality Control employees only, so QC is seeded with it.
 * Idempotent: an already-configured department is left alone; a missing QC department or role is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('departments', 'default_roles')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->json('default_roles')->nullable()->after('is_active');
            });
        }

        $qc = DB::table('departments')->where('name', 'Quality Control')->first();
        if ($qc && $qc->default_roles === null) {
            DB::table('departments')->where('id', $qc->id)->update([
                'default_roles' => json_encode(['Daily Works Contributor']),
            ]);
            Log::info('Seeded default_roles for Quality Control', ['department_id' => $qc->id]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('departments', 'default_roles')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropColumn('default_roles');
            });
        }
    }
};
