<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned corridor centreline storage for the Dashboard corridor map.
 *
 * `corridor_geometries` is one imported version of the centreline (source, capture date and
 * attribution travel with it; exactly one version is active), `corridor_geometry_points` its
 * vertices with linear-referencing chainage in metres, and `corridor_features` the structures
 * on it (interchanges, toll plazas, bridges). Populated only by `php artisan corridor:import`
 * from a real export - never seeded with illustrative data. Re-runnable: every step is guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('corridor_geometries')) {
            Schema::create('corridor_geometries', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('version');
                $table->string('source', 64);
                $table->string('attribution', 255)->default('');
                $table->date('captured_on')->nullable();
                $table->string('chainage_basis', 48)->default('surveyed');
                $table->unsignedInteger('point_count')->default(0);
                $table->unsignedInteger('length_m')->default(0);
                $table->char('checksum', 64);
                $table->boolean('is_active')->default(false);
                $table->timestamp('imported_at')->nullable();
                $table->timestamps();

                $table->unique('version');
                $table->unique('checksum');
                $table->index('is_active');
            });
        }

        if (! Schema::hasTable('corridor_geometry_points')) {
            Schema::create('corridor_geometry_points', function (Blueprint $table) {
                $table->id();
                $table->foreignId('geometry_id')->constrained('corridor_geometries')->cascadeOnDelete();
                $table->unsignedInteger('seq');
                $table->decimal('lat', 10, 7);
                $table->decimal('lng', 10, 7);
                $table->unsignedInteger('chainage_m')->default(0);

                $table->unique(['geometry_id', 'seq']);
                $table->index(['geometry_id', 'chainage_m']);
            });
        }

        if (! Schema::hasTable('corridor_features')) {
            Schema::create('corridor_features', function (Blueprint $table) {
                $table->id();
                $table->foreignId('geometry_id')->constrained('corridor_geometries')->cascadeOnDelete();
                $table->string('code', 32);
                $table->string('kind', 24); // interchange | toll_plaza | bridge | waypoint
                $table->string('name', 160);
                $table->decimal('lat', 10, 7);
                $table->decimal('lng', 10, 7);
                $table->unsignedInteger('chainage_m');
                $table->boolean('estimated')->default(false);
                $table->string('note', 255)->nullable();

                $table->unique(['geometry_id', 'code']);
                $table->index(['geometry_id', 'kind']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('corridor_features');
        Schema::dropIfExists('corridor_geometry_points');
        Schema::dropIfExists('corridor_geometries');
    }
};
