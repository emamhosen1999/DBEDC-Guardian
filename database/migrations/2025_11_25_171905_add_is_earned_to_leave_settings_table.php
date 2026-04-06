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
        if (! Schema::hasTable('leave_settings')) {
            return;
        }

        $isMySql = Schema::getConnection()->getDriverName() === 'mysql';

        Schema::table('leave_settings', function (Blueprint $table) use ($isMySql) {
            if (! Schema::hasColumn('leave_settings', 'is_earned')) {
                if ($isMySql) {
                    $table->boolean('is_earned')->default(false)->after('earned_leave');
                } else {
                    $table->boolean('is_earned')->default(false);
                }

                $table->index('is_earned');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('leave_settings') || ! Schema::hasColumn('leave_settings', 'is_earned')) {
            return;
        }

        Schema::table('leave_settings', function (Blueprint $table) {
            $table->dropIndex(['is_earned']);
            $table->dropColumn('is_earned');
        });
    }
};
