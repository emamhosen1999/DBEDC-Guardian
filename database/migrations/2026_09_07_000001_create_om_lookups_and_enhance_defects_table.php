<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Dynamic O&M Lookups & Categories Table
        if (! Schema::hasTable('om_lookups')) {
            Schema::create('om_lookups', function (Blueprint $table) {
                $table->id();
                $table->string('type', 64)->index(); // defect_category, severity, carriageway_location, work_order_category, contractor_entity
                $table->string('key', 64);
                $table->string('label', 128);
                $table->unsignedSmallInteger('sla_hours')->nullable();
                $table->string('badge_color', 32)->default('blue');
                $table->string('description', 255)->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['type', 'key']);
            });
        }

        // 2. Enhance om_defects table for Concessionaire Excel parity & dynamic lookups
        if (Schema::hasTable('om_defects')) {
            Schema::table('om_defects', function (Blueprint $table) {
                if (! Schema::hasColumn('om_defects', 'location_carriageway')) {
                    $table->string('location_carriageway', 100)->nullable()->after('direction');
                }
                if (! Schema::hasColumn('om_defects', 'responsible_party')) {
                    $table->string('responsible_party', 100)->nullable()->after('description');
                }
                if (! Schema::hasColumn('om_defects', 'recommended_action')) {
                    $table->string('recommended_action', 255)->nullable()->after('responsible_party');
                }
                if (! Schema::hasColumn('om_defects', 'date_notified')) {
                    $table->date('date_notified')->nullable()->after('reported_by');
                }
                if (! Schema::hasColumn('om_defects', 'target_repair_date')) {
                    $table->date('target_repair_date')->nullable()->after('date_notified');
                }
                if (! Schema::hasColumn('om_defects', 'photo_reference')) {
                    $table->string('photo_reference', 100)->nullable()->after('after_photos');
                }
                if (! Schema::hasColumn('om_defects', 'excel_sl')) {
                    $table->string('excel_sl', 32)->nullable()->after('defect_number');
                }
            });

            // Convert distress_type, severity, direction from restrictive ENUMs to VARCHAR so dynamic lookups work without error
            // Using raw SQL for MySQL
            try {
                DB::statement("ALTER TABLE om_defects MODIFY COLUMN distress_type VARCHAR(64) NOT NULL DEFAULT 'pothole'");
                DB::statement("ALTER TABLE om_defects MODIFY COLUMN severity VARCHAR(32) NOT NULL DEFAULT 'medium'");
                DB::statement("ALTER TABLE om_defects MODIFY COLUMN direction VARCHAR(32) NOT NULL DEFAULT 'northbound'");
                DB::statement("ALTER TABLE om_defects MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'reported'");
            } catch (\Throwable $e) {
                // Ignore if already VARCHAR or not supported
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('om_lookups');
    }
};
