<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase 3 & Research-Backed Advanced O&M tables:
     * 1. om_tppd_claims (Third-Party Property Damage insurance & legal recovery)
     * 2. om_iri_readings (Smartphone accelerometer-sourced International Roughness Index)
     * 3. om_wim_fatigue_logs (Weigh-In-Motion 4th power law structural fatigue & damage costs)
     */
    public function up(): void
    {
        // 1. Third-Party Property Damage (TPPD) Claims
        if (! Schema::hasTable('om_tppd_claims')) {
            Schema::create('om_tppd_claims', function (Blueprint $table) {
                $table->id();
                $table->string('claim_number', 50)->unique(); // e.g. TPPD-2026-001
                $table->unsignedBigInteger('incident_id')->nullable();
                $table->date('incident_date');
                $table->string('chainage', 50)->nullable();
                $table->string('direction', 20)->nullable();
                $table->string('vehicle_registration_number', 50);
                $table->string('driver_name', 255)->nullable();
                $table->string('driver_license_number', 100)->nullable();
                $table->string('insurance_company', 255)->nullable();
                $table->string('insurance_policy_number', 100)->nullable();
                $table->string('police_station', 100)->nullable();
                $table->string('police_fir_number', 100)->nullable();
                $table->json('damaged_components')->nullable(); // [{"item": "guardrail_w_beam", "qty": 4, "unit": "m", "rate": 4500}]
                $table->decimal('estimated_repair_cost', 12, 2)->default(0);
                $table->decimal('actual_repair_cost', 12, 2)->nullable();
                $table->decimal('claimed_amount', 12, 2)->default(0);
                $table->decimal('recovered_amount', 12, 2)->default(0);
                $table->enum('status', ['drafted', 'submitted_police', 'submitted_insurance', 'settled', 'disputed', 'written_off'])->default('drafted');
                $table->date('recovery_date')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['incident_date', 'status']);
                $table->index('vehicle_registration_number');
                $table->index('incident_id');
                $table->index('created_by_user_id');
            });
        }

        // 2. Patrol-Sourced International Roughness Index (IRI) Readings
        if (! Schema::hasTable('om_iri_readings')) {
            Schema::create('om_iri_readings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('patrol_shift_id')->nullable();
                $table->dateTime('recorded_at');
                $table->decimal('chainage_km', 7, 3); // e.g. 14.200
                $table->string('direction', 20)->default('northbound');
                $table->decimal('iri_value', 5, 2); // e.g. 2.35 m/km
                $table->decimal('speed_kmh', 5, 2)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->enum('condition_band', ['smooth', 'fair', 'rough'])->default('smooth');
                $table->timestamps();

                $table->index(['chainage_km', 'direction']);
                $table->index('recorded_at');
                $table->index('patrol_shift_id');
            });
        }

        // 3. Weigh-In-Motion (WIM) Fourth Power Law Structural Fatigue Logs
        if (! Schema::hasTable('om_wim_fatigue_logs')) {
            Schema::create('om_wim_fatigue_logs', function (Blueprint $table) {
                $table->id();
                $table->date('log_date');
                $table->string('toll_plaza', 100)->default('Kanchan Toll Plaza');
                $table->string('lane_number', 20)->default('Lane 01');
                $table->string('axle_class', 50)->default('5-Axle Semi-Trailer');
                $table->decimal('gross_weight_tonnes', 6, 2); // e.g. 48.50 tonnes
                $table->decimal('statutory_weight_limit', 6, 2)->default(38.00);
                $table->decimal('overload_percentage', 6, 2)->default(0); // e.g. 27.6%
                $table->decimal('fourth_power_damage_factor', 8, 2)->default(1.0); // (W/W0)^4
                $table->decimal('esal_equivalent', 8, 2)->default(1.0);
                $table->decimal('estimated_damage_cost_bdt', 10, 2)->default(0);
                $table->boolean('intercepted_by_patrol')->default(false);
                $table->timestamps();

                $table->index(['log_date', 'toll_plaza']);
                $table->index('fourth_power_damage_factor');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('om_wim_fatigue_logs');
        Schema::dropIfExists('om_iri_readings');
        Schema::dropIfExists('om_tppd_claims');
    }
};
