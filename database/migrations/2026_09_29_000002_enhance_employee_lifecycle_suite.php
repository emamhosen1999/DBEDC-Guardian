<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Re-structure assets table for full employee lifecycle allocation
        // The 2024 `assets` table (assignee/asset_id/asset_image) holds real data:
        // keep it as `assets_legacy_2024` instead of dropping it.
        if (Schema::hasTable('assets') && ! Schema::hasColumn('assets', 'asset_code')) {
            if (Schema::hasTable('assets_legacy_2024')) {
                throw new RuntimeException('Cannot preserve legacy assets table: assets_legacy_2024 already exists.');
            }
            Schema::rename('assets', 'assets_legacy_2024');
        }

        if (! Schema::hasTable('assets')) {
            Schema::create('assets', function (Blueprint $table) {
                $table->id();
                $table->string('asset_code')->unique();
                $table->string('name');
                $table->string('category')->default('it_hardware'); // it_hardware, sim_card, access_card, safety_gear, keys, vehicle, other
                $table->string('serial_number')->nullable();
                $table->string('assignee_id', 50)->nullable();
                $table->dateTime('assigned_date')->nullable();
                $table->dateTime('return_date')->nullable();
                $table->string('status')->default('available'); // available, assigned, returned, damaged, disposed
                $table->string('condition_on_issue')->nullable()->default('good');
                $table->string('condition_on_return')->nullable();
                $table->text('notes')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->foreign('assignee_id')->references('employee_id')->on('users')->onDelete('set null');
            });
        }

        // 2. Extend users table for probation & confirmation tracking
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'employment_status')) {
                $table->string('employment_status')->default('probationary')->after('date_of_joining');
            }
            if (!Schema::hasColumn('users', 'probation_end_date')) {
                $table->date('probation_end_date')->nullable()->after('employment_status');
            }
            if (!Schema::hasColumn('users', 'confirmation_date')) {
                $table->date('confirmation_date')->nullable()->after('probation_end_date');
            }
            if (!Schema::hasColumn('users', 'probation_notes')) {
                $table->text('probation_notes')->nullable()->after('confirmation_date');
            }
        });

        // 3. Full & Final Settlement (F&F) table
        if (!Schema::hasTable('final_settlements')) {
            Schema::create('final_settlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('offboarding_id');
                $table->string('employee_id', 50);
                $table->date('last_working_date');
                $table->decimal('monthly_gross_salary', 12, 2)->default(0);
                $table->decimal('daily_rate', 12, 2)->default(0);
                $table->decimal('payable_working_days', 5, 2)->default(0);
                $table->decimal('earned_salary', 12, 2)->default(0);
                $table->decimal('unavailed_leave_days', 5, 2)->default(0);
                $table->decimal('leave_encashment_amount', 12, 2)->default(0);
                $table->decimal('gratuity_amount', 12, 2)->default(0);
                $table->decimal('other_earnings', 12, 2)->default(0);
                $table->decimal('total_earnings', 12, 2)->default(0);
                $table->unsignedSmallInteger('notice_shortfall_days')->default(0);
                $table->decimal('notice_shortfall_deduction', 12, 2)->default(0);
                $table->decimal('loan_recovery_amount', 12, 2)->default(0);
                $table->decimal('asset_damage_deduction', 12, 2)->default(0);
                $table->decimal('other_deductions', 12, 2)->default(0);
                $table->decimal('total_deductions', 12, 2)->default(0);
                $table->decimal('net_payable', 12, 2)->default(0);
                $table->string('status')->default('draft'); // draft, approved, paid
                $table->string('payment_method')->nullable();
                $table->string('payment_reference')->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->text('remarks')->nullable();
                $table->string('prepared_by', 50);
                $table->string('approved_by', 50)->nullable();
                $table->timestamps();

                $table->foreign('offboarding_id')->references('id')->on('offboardings')->onDelete('cascade');
                $table->foreign('employee_id')->references('employee_id')->on('users')->onDelete('cascade');
                $table->foreign('prepared_by')->references('employee_id')->on('users');
                $table->foreign('approved_by')->references('employee_id')->on('users')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('final_settlements');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['employment_status', 'probation_end_date', 'confirmation_date', 'probation_notes']);
        });

        // The legacy 2024 table (assets_legacy_2024) is intentionally left in place.
        Schema::dropIfExists('assets');
    }
};
