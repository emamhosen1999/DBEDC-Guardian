<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OnboardingTask uses SoftDeletes, but onboarding_tasks was created without a
 * deleted_at column, so every Eloquent query on the model failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('onboarding_tasks') && ! Schema::hasColumn('onboarding_tasks', 'deleted_at')) {
            Schema::table('onboarding_tasks', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('onboarding_tasks') && Schema::hasColumn('onboarding_tasks', 'deleted_at')) {
            Schema::table('onboarding_tasks', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
