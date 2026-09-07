<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 O&M Renovation: Contractors, Environmental Logs, Toolbox Talks.
     */
    public function up(): void
    {
        // 1. O&M Contractors & Vendor Performance Registry
        if (! Schema::hasTable('om_contractors')) {
            Schema::create('om_contractors', function (Blueprint $table) {
                $table->id();
                $table->string('company_name');
                $table->string('trade_specialty'); // pavement, structural, its_electrical, tolling, landscaping
                $table->string('contact_person')->nullable();
                $table->string('contact_phone')->nullable();
                $table->string('contact_email')->nullable();
                $table->string('contract_reference')->nullable();
                $table->date('contract_start_date')->nullable();
                $table->date('contract_end_date')->nullable();
                $table->decimal('quality_score', 4, 2)->default(85.00); // 0.00 to 100.00
                $table->decimal('sla_compliance_rate', 5, 2)->default(90.00); // %
                $table->decimal('avg_response_hours', 6, 2)->default(4.00);
                $table->unsignedInteger('jobs_completed_count')->default(0);
                $table->unsignedInteger('jobs_delayed_count')->default(0);
                $table->enum('status', ['active', 'probation', 'blacklisted', 'inactive'])->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['trade_specialty', 'status']);
            });
        }

        // 2. Environmental & Weather Monitoring Logs
        if (! Schema::hasTable('om_environmental_logs')) {
            Schema::create('om_environmental_logs', function (Blueprint $table) {
                $table->id();
                $table->string('log_code')->unique(); // e.g. ENV-2026-0001
                $table->date('log_date');
                $table->enum('monitoring_type', [
                    'spill_hazardous', 'noise_level', 'air_particulate',
                    'stormwater_runoff', 'illegal_waste_dumping', 'slope_erosion',
                    'ambient_temperature_extreme', 'other',
                ])->default('spill_hazardous');
                $table->string('location')->nullable();
                $table->string('chainage')->nullable();
                $table->enum('direction', ['northbound', 'southbound', 'both', 'median', 'cross_culvert'])->default('both');
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->decimal('measured_value', 10, 2)->nullable();
                $table->string('measured_unit')->nullable(); // dB(A), PM2.5, Liters, mg/L
                $table->decimal('regulatory_threshold', 10, 2)->nullable();
                $table->enum('compliance_status', ['compliant', 'minor_exceedance', 'critical_violation'])->default('compliant');
                $table->string('weather_condition')->nullable(); // heavy_rain, extreme_heat, dense_fog, clear
                $table->text('description')->nullable();
                $table->text('corrective_action_taken')->nullable();
                $table->json('photo_paths')->nullable();
                $table->unsignedBigInteger('reported_by')->nullable();
                $table->timestamps();

                $table->index(['monitoring_type', 'compliance_status']);
                $table->index('log_date');
            });
        }

        // 3. Safety Toolbox Talks & Pre-Work Briefing Confirmations
        if (! Schema::hasTable('om_toolbox_talks')) {
            Schema::create('om_toolbox_talks', function (Blueprint $table) {
                $table->id();
                $table->string('talk_code')->unique(); // e.g. TBT-2026-0001
                $table->date('talk_date');
                $table->unsignedBigInteger('supervisor_id')->nullable();
                $table->string('topic'); // High-Speed Traffic Safety, Trench Working, Live Electrical, Night Working
                $table->unsignedBigInteger('work_order_id')->nullable();
                $table->string('chainage')->nullable();
                $table->json('attendees')->nullable(); // [{name, role, signature_confirmed: true}]
                $table->unsignedSmallInteger('attendee_count')->default(1);
                $table->boolean('ppe_verified')->default(true);
                $table->boolean('traffic_management_briefed')->default(true);
                $table->boolean('emergency_response_briefed')->default(true);
                $table->text('hazards_identified')->nullable();
                $table->json('photo_paths')->nullable();
                $table->timestamps();

                $table->index(['talk_date', 'work_order_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('om_toolbox_talks');
        Schema::dropIfExists('om_environmental_logs');
        Schema::dropIfExists('om_contractors');
    }
};
