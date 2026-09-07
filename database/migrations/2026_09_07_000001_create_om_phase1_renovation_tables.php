<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 O&M Renovation: Preventive Maintenance, Inspections, Safety, Analytics.
     */
    public function up(): void
    {
        // 1. Preventive Maintenance Schedules (Recurring PM Task Definitions)
        if (! Schema::hasTable('om_preventive_schedules')) {
            Schema::create('om_preventive_schedules', function (Blueprint $table) {
                $table->id();
                $table->string('schedule_code')->unique(); // e.g. PM-PVMT-001
                $table->string('title');
                $table->text('description')->nullable();
                $table->enum('asset_category', [
                    'pavement_civil', 'bridge_structure', 'guardrail_safety',
                    'signage_marking', 'drainage_slope', 'lighting_electrical',
                    'its_telecom', 'toll_equipment', 'building_facility',
                ])->default('pavement_civil');
                $table->unsignedBigInteger('asset_id')->nullable(); // Specific asset, or null = category-wide
                $table->enum('frequency_type', ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'semi_annual', 'annual', 'condition_based'])->default('monthly');
                $table->unsignedSmallInteger('frequency_interval_days')->default(30);
                $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
                $table->string('assigned_to')->nullable(); // Default crew or contractor
                $table->string('contractor_name')->nullable();
                $table->decimal('estimated_cost', 12, 2)->default(0);
                $table->decimal('estimated_duration_hours', 6, 2)->default(2);
                $table->string('chainage_from')->nullable();
                $table->string('chainage_to')->nullable();
                $table->enum('direction', ['northbound', 'southbound', 'both', 'median', 'all'])->default('both');
                $table->boolean('requires_lane_closure')->default(false);
                $table->json('checklist_items')->nullable(); // JSON array of checklist steps
                $table->json('required_materials')->nullable(); // JSON array of materials needed
                $table->date('last_generated_at')->nullable();
                $table->date('next_due_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['asset_category', 'is_active']);
                $table->index('next_due_at');
            });
        }

        // 2. Inspection Templates (Configurable checklist definitions per asset type)
        if (! Schema::hasTable('om_inspection_templates')) {
            Schema::create('om_inspection_templates', function (Blueprint $table) {
                $table->id();
                $table->string('template_code')->unique(); // e.g. INSP-PVMT-01
                $table->string('name');
                $table->text('description')->nullable();
                $table->enum('asset_category', [
                    'pavement_civil', 'bridge_structure', 'guardrail_safety',
                    'signage_marking', 'drainage_slope', 'lighting_electrical',
                    'its_telecom', 'toll_equipment', 'building_facility', 'general',
                ])->default('general');
                $table->json('checklist_sections'); // JSON: [{section, items: [{label, type: pass_fail|score|text|photo, required, weight}]}]
                $table->unsignedSmallInteger('max_score')->default(100);
                $table->unsignedSmallInteger('pass_threshold')->default(70); // Score below this = fail
                $table->boolean('auto_create_defect_on_fail')->default(true);
                $table->boolean('photo_required')->default(true);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['asset_category', 'is_active']);
            });
        }

        // 3. Inspections (Completed inspection records from field)
        if (! Schema::hasTable('om_inspections')) {
            Schema::create('om_inspections', function (Blueprint $table) {
                $table->id();
                $table->string('inspection_number')->unique(); // e.g. INS-2026-0001
                $table->unsignedBigInteger('template_id')->nullable();
                $table->unsignedBigInteger('asset_id')->nullable();
                $table->unsignedBigInteger('preventive_schedule_id')->nullable();
                $table->date('inspection_date');
                $table->unsignedBigInteger('inspector_id')->nullable();
                $table->string('chainage')->nullable();
                $table->enum('direction', ['northbound', 'southbound', 'both', 'median'])->default('northbound');
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->json('checklist_responses')->nullable(); // JSON: filled-in checklist answers
                $table->unsignedSmallInteger('total_score')->default(0);
                $table->enum('result', ['pass', 'fail', 'needs_attention', 'critical'])->default('pass');
                $table->text('overall_notes')->nullable();
                $table->json('photo_paths')->nullable();
                $table->enum('status', ['draft', 'submitted', 'reviewed', 'closed'])->default('submitted');
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->index(['inspection_date', 'status']);
                $table->index('asset_id');
                $table->index('template_id');
            });
        }

        // 4. Safety Incidents & Near-Miss Reports
        if (! Schema::hasTable('om_safety_incidents')) {
            Schema::create('om_safety_incidents', function (Blueprint $table) {
                $table->id();
                $table->string('safety_number')->unique(); // e.g. SAF-2026-0001
                $table->string('title');
                $table->enum('incident_type', [
                    'workplace_injury', 'near_miss', 'vehicle_incident',
                    'hazardous_material', 'fall_from_height', 'electrical_hazard',
                    'traffic_zone_breach', 'ppe_violation', 'environmental_spill',
                    'fire_emergency', 'heat_stress', 'other',
                ])->default('near_miss');
                $table->enum('severity', ['negligible', 'minor', 'moderate', 'major', 'catastrophic'])->default('minor');
                $table->string('location')->nullable();
                $table->string('chainage')->nullable();
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->timestamp('occurred_at');
                $table->unsignedBigInteger('reported_by')->nullable();
                $table->timestamp('reported_at')->nullable();
                $table->text('description')->nullable();
                $table->text('immediate_action_taken')->nullable();
                $table->text('root_cause')->nullable();
                $table->text('corrective_action')->nullable();
                $table->json('persons_involved')->nullable(); // JSON: [{name, role, injury_type, treatment}]
                $table->json('photo_paths')->nullable();
                $table->boolean('ppe_worn')->default(true);
                $table->boolean('toolbox_talk_done')->default(false);
                $table->string('work_order_ref')->nullable(); // Related WO if during maintenance
                $table->enum('status', ['reported', 'investigating', 'corrective_action', 'closed'])->default('reported');
                $table->unsignedBigInteger('investigated_by')->nullable();
                $table->timestamp('investigated_at')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedInteger('lost_time_hours')->default(0);
                $table->timestamps();

                $table->index(['incident_type', 'status']);
                $table->index('occurred_at');
            });
        }

        // 5. SLA Breach Registry (Track every SLA breach for compliance reporting)
        if (! Schema::hasTable('om_sla_breaches')) {
            Schema::create('om_sla_breaches', function (Blueprint $table) {
                $table->id();
                $table->string('entity_type'); // 'defect', 'work_order', 'incident'
                $table->unsignedBigInteger('entity_id');
                $table->string('entity_number'); // DEF-2026-0001, WO-12345, etc.
                $table->unsignedSmallInteger('sla_hours');
                $table->timestamp('sla_started_at');
                $table->timestamp('sla_due_at');
                $table->timestamp('breached_at');
                $table->unsignedSmallInteger('overdue_hours')->default(0);
                $table->enum('escalation_level', ['warning', 'breach', 'critical_breach'])->default('breach');
                $table->boolean('acknowledged')->default(false);
                $table->unsignedBigInteger('acknowledged_by')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['entity_type', 'entity_id']);
                $table->index('breached_at');
            });
        }

        // 6. Add preventive_schedule_id to work orders for PM tracking
        if (Schema::hasTable('om_work_orders')) {
            Schema::table('om_work_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('om_work_orders', 'preventive_schedule_id')) {
                    $table->unsignedBigInteger('preventive_schedule_id')->nullable()->after('asset_id');
                }
                if (! Schema::hasColumn('om_work_orders', 'inspection_id')) {
                    $table->unsignedBigInteger('inspection_id')->nullable()->after('preventive_schedule_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('om_work_orders')) {
            Schema::table('om_work_orders', function (Blueprint $table) {
                if (Schema::hasColumn('om_work_orders', 'preventive_schedule_id')) {
                    $table->dropColumn('preventive_schedule_id');
                }
                if (Schema::hasColumn('om_work_orders', 'inspection_id')) {
                    $table->dropColumn('inspection_id');
                }
            });
        }

        Schema::dropIfExists('om_sla_breaches');
        Schema::dropIfExists('om_safety_incidents');
        Schema::dropIfExists('om_inspections');
        Schema::dropIfExists('om_inspection_templates');
        Schema::dropIfExists('om_preventive_schedules');
    }
};
