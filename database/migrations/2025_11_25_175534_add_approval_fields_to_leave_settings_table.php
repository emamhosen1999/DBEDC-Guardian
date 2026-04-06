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
            if (! Schema::hasColumn('leave_settings', 'requires_approval')) {
                if ($isMySql) {
                    $table->boolean('requires_approval')->default(true)->after('earned_leave');
                } else {
                    $table->boolean('requires_approval')->default(true);
                }
            }

            if (! Schema::hasColumn('leave_settings', 'auto_approve')) {
                if ($isMySql) {
                    $table->boolean('auto_approve')->default(false)->after('requires_approval');
                } else {
                    $table->boolean('auto_approve')->default(false);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('leave_settings')) {
            return;
        }

        Schema::table('leave_settings', function (Blueprint $table) {
            $columnsToDrop = [];

            if (Schema::hasColumn('leave_settings', 'requires_approval')) {
                $columnsToDrop[] = 'requires_approval';
            }

            if (Schema::hasColumn('leave_settings', 'auto_approve')) {
                $columnsToDrop[] = 'auto_approve';
            }

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
