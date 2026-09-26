<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OffboardingTask uses SoftDeletes, but offboarding_tasks was created without a
 * deleted_at column, so every Eloquent query on the model failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('offboarding_tasks') && ! Schema::hasColumn('offboarding_tasks', 'deleted_at')) {
            Schema::table('offboarding_tasks', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('offboarding_tasks') && Schema::hasColumn('offboarding_tasks', 'deleted_at')) {
            Schema::table('offboarding_tasks', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
