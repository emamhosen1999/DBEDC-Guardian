<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('om_ai_detections')) {
            Schema::create('om_ai_detections', function (Blueprint $table) {
                $table->id();
                $table->string('detection_code', 50)->unique();
                $table->string('distress_type', 50); // pothole, longitudinal_crack, alligator_crack, rutting, road_debris
                $table->decimal('confidence_score', 5, 2)->default(0.85); // 0.00 - 1.00
                $table->string('chainage', 50)->nullable(); // e.g. KM 14+350
                $table->string('direction', 20)->default('northbound');
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->decimal('estimated_area_sqm', 8, 2)->nullable();
                $table->string('severity', 20)->default('medium'); // low, medium, high, critical
                $table->string('image_path')->nullable();
                $table->json('bounding_box')->nullable(); // [x_min, y_min, x_max, y_max]
                $table->unsignedBigInteger('patrol_shift_id')->nullable();
                $table->string('status', 30)->default('pending_review'); // pending_review, approved_work_order, rejected_false_positive, auto_converted
                $table->unsignedBigInteger('work_order_id')->nullable();
                $table->string('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['distress_type', 'severity']);
                $table->index('status');
                $table->index('chainage');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('om_ai_detections');
    }
};
