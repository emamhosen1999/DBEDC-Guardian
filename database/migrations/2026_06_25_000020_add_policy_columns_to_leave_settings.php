<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_settings')) {
            return;
        }

        Schema::table('leave_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_settings', 'accrual_method')) {
                $table->string('accrual_method', 20)->default('annual_upfront');
            }
            if (! Schema::hasColumn('leave_settings', 'accrual_rate')) {
                $table->decimal('accrual_rate', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('leave_settings', 'probation_months')) {
                $table->unsignedTinyInteger('probation_months')->default(0);
            }
            if (! Schema::hasColumn('leave_settings', 'prorate_on_join')) {
                $table->boolean('prorate_on_join')->default(true);
            }
            if (! Schema::hasColumn('leave_settings', 'carry_forward_cap')) {
                $table->decimal('carry_forward_cap', 5, 1)->nullable();
            }
            if (! Schema::hasColumn('leave_settings', 'carry_expiry_months')) {
                $table->unsignedTinyInteger('carry_expiry_months')->nullable();
            }
            if (! Schema::hasColumn('leave_settings', 'is_encashable')) {
                $table->boolean('is_encashable')->default(false);
            }
            if (! Schema::hasColumn('leave_settings', 'allow_negative')) {
                $table->boolean('allow_negative')->default(false);
            }
        });

        // Back-fill from legacy flags: earned/is_earned => monthly; accrual_rate <= days.
        DB::table('leave_settings')->get()->each(function ($s) {
            $method = ($s->earned_leave || ($s->is_earned ?? false)) ? 'monthly' : 'annual_upfront';
            DB::table('leave_settings')->where('id', $s->id)->update([
                'accrual_method' => $method,
                'accrual_rate' => $s->days,
                'carry_forward_cap' => $s->carry_forward ? $s->days : null,
            ]);
        });
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
