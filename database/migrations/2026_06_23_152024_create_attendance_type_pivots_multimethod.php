<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-method attendance: a work location and an employee (override) can each have a
 * SET of allowed attendance types. A punch is valid if ANY one validates (OR logic).
 * Existing single FKs (work_locations.attendance_type_id, users.attendance_type_id)
 * are kept and backfilled into the pivots, so behaviour is backward-compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_location_attendance_type')) {
            Schema::create('work_location_attendance_type', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_location_id')->constrained('work_locations')->cascadeOnDelete();
                $table->foreignId('attendance_type_id')->constrained('attendance_types')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['work_location_id', 'attendance_type_id'], 'wlat_unique');
            });
        }

        if (! Schema::hasTable('user_attendance_type')) {
            Schema::create('user_attendance_type', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('attendance_type_id')->constrained('attendance_types')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'attendance_type_id'], 'uat_unique');
            });
        }

        // Backfill from existing single FKs so current assignments keep working.
        $now = now();

        DB::table('work_locations')->whereNotNull('attendance_type_id')->orderBy('id')->chunkById(200, function ($locations) use ($now) {
            $rows = [];
            foreach ($locations as $loc) {
                $rows[] = [
                    'work_location_id' => $loc->id,
                    'attendance_type_id' => $loc->attendance_type_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows) {
                DB::table('work_location_attendance_type')->insertOrIgnore($rows);
            }
        });

        DB::table('users')->whereNotNull('attendance_type_id')->orderBy('id')->chunkById(500, function ($users) use ($now) {
            $rows = [];
            foreach ($users as $user) {
                $rows[] = [
                    'user_id' => $user->id,
                    'attendance_type_id' => $user->attendance_type_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows) {
                DB::table('user_attendance_type')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_attendance_type');
        Schema::dropIfExists('work_location_attendance_type');
    }
};
