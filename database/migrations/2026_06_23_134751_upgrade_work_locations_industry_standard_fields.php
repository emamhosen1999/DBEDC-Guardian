<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade work_locations to an industry-standard structure:
     * physical address, per-location geofence (lat/long + radius), timezone,
     * active toggle, description, and soft deletes.
     */
    public function up(): void
    {
        Schema::table('work_locations', function (Blueprint $table) {
            if (!Schema::hasColumn('work_locations', 'code')) {
                $table->string('code')->nullable()->unique()->after('name');
            }
            if (!Schema::hasColumn('work_locations', 'description')) {
                $table->text('description')->nullable()->after('code');
            }
            if (!Schema::hasColumn('work_locations', 'address')) {
                $table->string('address')->nullable()->after('description');
            }
            if (!Schema::hasColumn('work_locations', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('address');
            }
            if (!Schema::hasColumn('work_locations', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (!Schema::hasColumn('work_locations', 'geofence_radius')) {
                // Radius in meters used for geofence punch validation.
                $table->unsignedInteger('geofence_radius')->nullable()->after('longitude');
            }
            if (!Schema::hasColumn('work_locations', 'timezone')) {
                $table->string('timezone')->nullable()->after('geofence_radius');
            }
            if (!Schema::hasColumn('work_locations', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('timezone');
            }
            if (!Schema::hasColumn('work_locations', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_locations', function (Blueprint $table) {
            foreach (['code', 'description', 'address', 'latitude', 'longitude', 'geofence_radius', 'timezone', 'is_active'] as $column) {
                if (Schema::hasColumn('work_locations', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('work_locations', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
