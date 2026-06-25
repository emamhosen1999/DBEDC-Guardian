<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 0) employee_id is an integer column; placeholders (EMP-{id}, {eid}-DUP{id})
        // are non-numeric strings, so widen the column to varchar before assigning them.
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id', 50)->nullable()->change();
        });

        // 1) Give NULL/empty employee_ids a deterministic unique placeholder.
        DB::table('users')->whereNull('employee_id')->orWhere('employee_id', '')
            ->orderBy('id')->each(function ($u) {
                DB::table('users')->where('id', $u->id)->update(['employee_id' => 'EMP-'.$u->id]);
            });

        // 2) De-duplicate any remaining collisions: keep the lowest id, suffix the rest.
        $dupes = DB::table('users')->select('employee_id')->whereNotNull('employee_id')
            ->groupBy('employee_id')->havingRaw('COUNT(*) > 1')->pluck('employee_id');
        foreach ($dupes as $eid) {
            $rows = DB::table('users')->where('employee_id', $eid)->orderBy('id')->get();
            foreach ($rows as $i => $row) {
                if ($i === 0) { continue; } // first keeps the original id
                DB::table('users')->where('id', $row->id)->update(['employee_id' => $eid.'-DUP'.$row->id]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_id']);
        });
    }
};
