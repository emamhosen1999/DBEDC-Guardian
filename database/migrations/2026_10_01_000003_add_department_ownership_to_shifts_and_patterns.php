<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift definitions and rotation patterns gain an OWNER: a nullable department.
 *
 *   department_id NULL  -> company-wide (what every existing row is): managed with `attendance.settings`
 *   department_id = X   -> owned by department X: a department admin of X may create / edit / delete it
 *                          (delegated administration) and assign it to X's staff; schedulers of other
 *                          departments neither see nor can assign it.
 *
 * nullOnDelete: deleting a department turns its templates company-wide rather than destroying roster
 * history that references them. Every existing row stays NULL, so nothing changes until a template is
 * explicitly given an owner.
 */
return new class extends Migration
{
    private const TABLES = ['shifts', 'shift_rotation_patterns'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'department_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('department_id')->nullable()->after('created_by')->constrained('departments')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'department_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('department_id');
            });
        }
    }
};
